<?php

namespace Tests\Feature;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Webhooks (v2.0.2).
 *
 * Endpoints are org-configured with a per-endpoint HMAC
 * signing secret, shown once and never again. Deliveries
 * are real HTTP attempts with an HMAC signature; the
 * tests fake the HTTP layer so no real request leaves the
 * VM, and assert the signature the fake received.
 * Unreachable endpoints retry with backoff, then give up
 * and record a failure.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setupOrg(string $plan = 'starter'): array
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        if ($plan !== null) {
            $this->subscribe($organization, $plan);
        }

        return [$user, $organization];
    }

    /**
     * Create an endpoint directly, with a known secret,
     * so the tests can verify the HMAC themselves.
     */
    protected function createEndpoint($organization, string $url = 'https://hooks.example.com/claimtrail'): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'organization_id' => $organization->getKey(),
            'name' => 'Test endpoint',
            'url' => $url,
            'secret' => 'whsec_test_secret_for_hmac',
            'events' => ['claim.created', 'claim.paid', 'payment.recorded'],
            'status' => WebhookEndpoint::STATUS_ACTIVE,
        ]);
    }

    public function test_endpoint_creation_shows_secret_once(): void
    {
        [$user, $organization] = $this->setupOrg();

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/webhooks', [
                'name' => 'Ops hook',
                'url' => 'https://hooks.example.com/claimtrail',
                'events' => ['claim.created'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ops hook')
            ->assertJsonPath('data.status', 'active');

        $secret = $response->json('plain_text_secret');
        $this->assertNotEmpty($secret);
        $this->assertSame(48, strlen($secret));

        // The secret and the URL are never returned again.
        $id = $response->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/webhooks/'.$id)
            ->assertOk()
            ->assertJsonMissing(['plain_text_secret'])
            ->assertJsonMissing(['url' => 'https://hooks.example.com/claimtrail'])
            ->assertJsonPath('data.url_host', 'hooks.example.com');
    }

    public function test_endpoint_management_requires_permission_and_plan(): void
    {
        [, $organization] = $this->setupOrg('free');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/webhooks', [
                'name' => 'Free hook',
                'url' => 'https://hooks.example.com/claimtrail',
                'events' => ['claim.created'],
            ])
            ->assertStatus(402);

        [, $organization] = $this->setupOrg();
        [$member] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/webhooks', [
                'name' => 'Viewer hook',
                'url' => 'https://hooks.example.com/claimtrail',
                'events' => ['claim.created'],
            ])
            ->assertForbidden();
    }

    public function test_claim_created_dispatches_signed_delivery(): void
    {
        [$user, $organization] = $this->setupOrg();
        $endpoint = $this->createEndpoint($organization);

        Http::fake([
            'hooks.example.com/*' => Http::response('received', 200),
        ]);

        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CT-WEBHOOK-1',
        ]);

        // The observer dispatched on creation; the sync
        // queue delivered it before the request finished.
        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->getKey())
            ->where('event_type', 'claim.created')
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(200, $delivery->http_status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->delivered_at);

        // The listener received a real signed payload.
        Http::assertSent(function ($request) use ($claim) {
            $signature = $request->header('X-ClaimTrail-Signature')[0] ?? '';
            $timestamp = $request->header('X-ClaimTrail-Timestamp')[0] ?? '';

            if (! str_starts_with($signature, 'sha256=')) {
                return false;
            }

            $expected = 'sha256='.hash_hmac(
                'sha256',
                $timestamp.'.'.$request->body(),
                'whsec_test_secret_for_hmac',
            );

            return hash_equals($expected, $signature)
                && $request->header('X-ClaimTrail-Event')[0] === 'claim.created'
                && $request->header('X-ClaimTrail-Delivery-Id')[0] !== ''
                && str_contains($request->body(), $claim->getKey());
        });
    }

    public function test_test_endpoint_sends_signed_payload_synchronously(): void
    {
        [$user, $organization] = $this->setupOrg();
        $endpoint = $this->createEndpoint($organization);

        Http::fake([
            'hooks.example.com/*' => Http::response('received', 200),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/webhooks/'.$endpoint->getKey().'/test')
            ->assertOk()
            ->assertJsonPath('data.event_type', 'webhook.test')
            ->assertJsonPath('data.status', 'delivered');

        Http::assertSent(function ($request) {
            return $request->header('X-ClaimTrail-Event')[0] === 'webhook.test'
                && str_starts_with($request->header('X-ClaimTrail-Signature')[0] ?? '', 'sha256=');
        });
    }

    public function test_unreachable_endpoint_retries_then_records_failure(): void
    {
        [$user, $organization] = $this->setupOrg();
        $endpoint = $this->createEndpoint($organization);

        Http::fake([
            'hooks.example.com/*' => Http::response('server error', 500),
        ]);

        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CT-WEBHOOK-FAIL-1',
        ]);

        // The sync queue retried until the policy gave up:
        // each failed attempt re-dispatched the next one.
        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->getKey())
            ->where('event_type', 'claim.created')
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame(500, $delivery->http_status);
        $this->assertNull($delivery->delivered_at);
        $this->assertNull($delivery->next_retry_at);

        // The endpoint carries the failure for monitoring.
        $this->assertNotNull($endpoint->fresh()->last_error);

        // Five attempts were made against the endpoint.
        Http::assertSentCount(5);
    }

    public function test_disabled_endpoint_receives_nothing(): void
    {
        [$user, $organization] = $this->setupOrg();
        $endpoint = $this->createEndpoint($organization);
        $endpoint->update(['status' => WebhookEndpoint::STATUS_DISABLED]);

        Http::fake([
            'hooks.example.com/*' => Http::response('received', 200),
        ]);

        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CT-WEBHOOK-OFF-1',
        ]);

        $this->assertDatabaseMissing('webhook_deliveries', [
            'webhook_endpoint_id' => $endpoint->getKey(),
        ]);

        Http::assertSentCount(0);
    }

    public function test_deliveries_are_listed_per_endpoint(): void
    {
        [$user, $organization] = $this->setupOrg();
        $endpoint = $this->createEndpoint($organization);

        Http::fake([
            'hooks.example.com/*' => Http::response('received', 200),
        ]);

        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CT-WEBHOOK-LIST-1',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/webhooks/'.$endpoint->getKey().'/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'claim.created')
            ->assertJsonPath('data.0.status', 'delivered');
    }
}
