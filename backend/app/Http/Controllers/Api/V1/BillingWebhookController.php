<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Billing provider webhooks (v1.2.0).
 *
 * The webhook endpoint is public: the provider is not signed in.
 * Authentication is the HMAC signature over the raw body
 * (X-Mock-Signature, verified against the configured webhook
 * secret). Events are deduplicated by provider event id, so a
 * redelivery never applies twice.
 *
 * The portal endpoint is also public: the portal token in the URL
 * is the credential, and it expires after 30 minutes.
 */
class BillingWebhookController extends Controller
{
    public function __construct(
        protected BillingService $billing,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Receive one provider webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('X-Mock-Signature', '');

        if (! $this->billing->provider()->verifyWebhookSignature(
            $payload,
            $signature,
            (string) config('billing.webhook_secret'),
        )) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $record = $this->billing->handleWebhookEvent($event);

        return response()->json([
            'data' => [
                'id' => $record->getKey(),
                'type' => $record->type,
                'status' => $record->status,
            ],
        ]);
    }

    /**
     * Fire a mock webhook event through the normal pipeline:
     * sign it, verify the signature, then handle it. Lets an
     * operator (and the tests) exercise the webhook path without
     * a real provider.
     */
    public function simulate(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'data' => ['sometimes', 'array'],
        ]);

        $data = $validated['data'] ?? [];
        $data['organization_id'] ??= $organization->getKey();

        $record = $this->billing->simulateWebhook(
            $validated['type'],
            $data,
            $organization,
        );

        return response()->json([
            'data' => [
                'id' => $record->getKey(),
                'type' => $record->type,
                'status' => $record->status,
            ],
        ], 201);
    }

    /**
     * Open a mock billing portal session with its token.
     */
    public function portal(Request $request, string $token): JsonResponse
    {
        $summary = $this->billing->portalSummary($token);

        if ($summary === null) {
            return response()->json(['message' => 'Portal session not found or expired.'], 404);
        }

        return response()->json(['data' => $summary]);
    }
}
