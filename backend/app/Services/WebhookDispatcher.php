<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhook dispatch (v2.0.2).
 *
 * Events are dispatched to every active endpoint of the
 * organization that subscribes to the event type. Each
 * endpoint gets its own delivery row and its own queued
 * job, so one slow endpoint never blocks another. The
 * payload is signed with HMAC-SHA256 over the raw JSON
 * body using the endpoint's secret, and the signature
 * travels in the X-ClaimTrail-Signature header as
 * `sha256=<hex>`, with X-ClaimTrail-Event,
 * X-ClaimTrail-Delivery-Id, and X-ClaimTrail-Timestamp
 * alongside it, so a receiver can verify the payload and
 * reject replays.
 *
 * Event types: claim.created, claim.updated, claim.paid,
 * payment.recorded, document.processed,
 * workflow.run_completed. The payload data carries only
 * ids, references, statuses, and amounts — never tokens,
 * secrets, or personal contact details.
 *
 * A delivery attempt is a real HTTP POST with a timeout
 * (config public_api.webhook_timeout_seconds). A 2xx
 * response delivers; anything else is a failed attempt and
 * the bounded retry policy applies. Delivery rows are the
 * audit trail: nothing is only logged.
 */
class WebhookDispatcher
{
    public const EVENT_CLAIM_CREATED = 'claim.created';

    public const EVENT_CLAIM_UPDATED = 'claim.updated';

    public const EVENT_CLAIM_PAID = 'claim.paid';

    public const EVENT_PAYMENT_RECORDED = 'payment.recorded';

    public const EVENT_DOCUMENT_PROCESSED = 'document.processed';

    public const EVENT_WORKFLOW_RUN_COMPLETED = 'workflow.run_completed';

    /**
     * Every event type webhooks can subscribe to.
     *
     * @return array<int, string>
     */
    public static function events(): array
    {
        return [
            self::EVENT_CLAIM_CREATED,
            self::EVENT_CLAIM_UPDATED,
            self::EVENT_CLAIM_PAID,
            self::EVENT_PAYMENT_RECORDED,
            self::EVENT_DOCUMENT_PROCESSED,
            self::EVENT_WORKFLOW_RUN_COMPLETED,
        ];
    }

    public function __construct(protected NotificationService $notifications) {}

    /**
     * Dispatch an event to the organization's endpoints.
     * One delivery row and one queued job per endpoint that
     * wants the event. Unknown event types are refused: a
     * typo must never silently deliver nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatch(Organization $organization, string $event, array $data): void
    {
        if (! in_array($event, self::events(), true)) {
            throw new \InvalidArgumentException("Unknown webhook event: {$event}");
        }

        $endpoints = WebhookEndpoint::forOrganization($organization->getKey())
            ->where('status', WebhookEndpoint::STATUS_ACTIVE)
            ->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->wants($event)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->getKey(),
                'organization_id' => $organization->getKey(),
                'event_type' => $event,
                'payload' => [
                    'event' => $event,
                    'organization_id' => $organization->getKey(),
                    'data' => $data,
                ],
                'status' => WebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
            ]);

            DeliverWebhook::dispatch($delivery);
        }
    }

    /**
     * Perform one delivery attempt synchronously. Returns
     * true when the endpoint answered 2xx. Records the
     * attempt on the delivery row either way, and marks a
     * delivery delivered or failed when the retry budget is
     * spent. The queued job calls this; the test endpoint
     * calls it directly so a test delivery is immediate.
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $endpoint = $delivery->endpoint;

        if ($endpoint === null || $endpoint->status !== WebhookEndpoint::STATUS_ACTIVE) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'response_body' => 'Endpoint is missing or disabled.',
            ]);

            return false;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        $delivery->increment('attempts');
        $delivery->refresh();

        try {
            $response = Http::timeout((int) config('public_api.webhook_timeout_seconds', 10))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-ClaimTrail-Event' => $delivery->event_type,
                    'X-ClaimTrail-Delivery-Id' => $delivery->getKey(),
                    'X-ClaimTrail-Timestamp' => $timestamp,
                    'X-ClaimTrail-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (\Throwable $e) {
            $this->recordAttempt($delivery, null, 'Request failed: '.$e->getMessage());

            return false;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'http_status' => $status,
                'response_body' => Str::limit($response->body(), 2000),
                'delivered_at' => now(),
                'next_retry_at' => null,
            ]);

            $endpoint->update([
                'last_delivered_at' => now(),
                'last_error' => null,
            ]);

            return true;
        }

        $this->recordAttempt($delivery, $status, Str::limit($response->body(), 2000));

        return false;
    }

    /**
     * Record a failed attempt. When the attempt budget is
     * spent the delivery is failed and the endpoint carries
     * the error; otherwise next_retry_at is set from the
     * retry delays and the job re-queues the delivery.
     */
    protected function recordAttempt(WebhookDelivery $delivery, ?int $httpStatus, string $body): void
    {
        $maxAttempts = (int) config('public_api.webhook_max_attempts', 5);
        $delays = config('public_api.webhook_retry_delays', [60, 300, 900, 1800]);

        $delivery->update([
            'http_status' => $httpStatus,
            'response_body' => Str::limit($body, 2000),
        ]);

        if ($delivery->attempts >= $maxAttempts) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'next_retry_at' => null,
            ]);

            $endpoint = $delivery->endpoint;

            if ($endpoint !== null) {
                $endpoint->update(['last_error' => "Delivery {$delivery->getKey()} failed after {$delivery->attempts} attempts."]);
            }

            $this->notifyFailure($delivery);

            return;
        }

        $delay = (int) ($delays[$delivery->attempts - 1] ?? end($delays));

        $delivery->update([
            'status' => WebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now()->addSeconds($delay),
        ]);
    }

    /**
     * Tell the organization's integration managers that a
     * delivery gave up. The notification is best effort:
     * a failure to notify must never break the delivery.
     */
    protected function notifyFailure(WebhookDelivery $delivery): void
    {
        try {
            $organization = $delivery->endpoint?->organization;

            if ($organization === null) {
                return;
            }

            $users = $this->notifications->membersWithPermission(
                $organization,
                \App\Permission::ManageIntegrations,
            );

            $this->notifications->notify(
                $organization,
                $users,
                \App\NotificationType::WebhookDeliveryFailed,
                'Webhook delivery failed',
                "A {$delivery->event_type} delivery to an endpoint failed after {$delivery->attempts} attempts.",
                ['webhook_delivery_id' => $delivery->getKey()],
                'webhook-delivery-failed-'.$delivery->getKey(),
            );
        } catch (\Throwable) {
            // Notification failure must never break delivery handling.
        }
    }

    /**
     * Send a test event to one endpoint immediately and
     * return the delivery row. The test payload is clearly
     * marked test so a receiver never mistakes it for a
     * real event.
     */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'organization_id' => $endpoint->organization_id,
            'event_type' => 'webhook.test',
            'payload' => [
                'event' => 'webhook.test',
                'organization_id' => $endpoint->organization_id,
                'data' => ['endpoint_id' => $endpoint->getKey(), 'test' => true],
            ],
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $this->deliver($delivery);

        return $delivery->fresh();
    }

    /**
     * The public presentation of an endpoint. The URL and
     * the secret are never included: both are encrypted at
     * rest and the secret was shown once, at creation.
     *
     * @return array<string, mixed>
     */
    public function presentEndpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->getKey(),
            'organization_id' => $endpoint->organization_id,
            'name' => $endpoint->name,
            'url_host' => parse_url($endpoint->url, PHP_URL_HOST),
            'events' => $endpoint->events ?? [],
            'status' => $endpoint->status,
            'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
            'last_error' => $endpoint->last_error,
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'updated_at' => $endpoint->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The public presentation of a delivery.
     *
     * @return array<string, mixed>
     */
    public function presentDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getKey(),
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'organization_id' => $delivery->organization_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'http_status' => $delivery->http_status,
            'response_body' => $delivery->response_body,
            'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
