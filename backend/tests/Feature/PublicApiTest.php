<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\OrganizationRole;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The public REST API (v2.0.2).
 *
 * Bearer key authentication, per-key scopes, rotation with
 * a grace window, revocation, per-key rate limiting, the
 * Free-plan 402, and cross-tenant isolation. A key from
 * another organization sees nothing: unknown ids answer
 * 404, never 403.
 */
class PublicApiTest extends TestCase
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
     * Issue a key through the service and return the row
     * plus the full key (shown once, as in production).
     *
     * @param  array<int, string>  $scopes
     * @return array{ApiKey, string}
     */
    protected function issueKey($organization, array $scopes, string $kind = ApiKey::KIND_STANDARD, ?string $brokerName = null): array
    {
        $service = app(ApiKeyService::class);
        $user = $organization->members()->first()->user;

        $issued = $service->create($organization, $user, 'Test key', $scopes, $kind, $brokerName);

        return [$issued['key'], $issued['plain_text']];
    }

    protected function keyHeaders(string $plainText): array
    {
        return ['Authorization' => 'Bearer '.$plainText];
    }

    public function test_public_index_returns_key_scopes_and_rate_limit(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1')
            ->assertOk()
            ->assertJsonPath('data.service', 'ClaimTrail Public API')
            ->assertJsonPath('data.key_id', $key->getKey())
            ->assertJsonPath('data.scopes', ['claims:read'])
            ->assertJsonPath('data.rate_limit_per_minute', 120);
    }

    public function test_missing_key_is_401_and_unknown_key_is_401(): void
    {
        $this->getJson('/api/public/v1')->assertUnauthorized();

        $this->withHeaders($this->keyHeaders('ct_live_doesnotexist0000000000000000000000'))
            ->getJson('/api/public/v1')
            ->assertUnauthorized();
    }

    public function test_scoped_read_ok_out_of_scope_403(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertOk();

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/shipments')
            ->assertForbidden();

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/payments')
            ->assertForbidden();
    }

    public function test_cross_org_claim_is_404(): void
    {
        [, $organization] = $this->setupOrg();
        [, $other] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $otherShipment = $this->createShipment($other);
        $otherClaim = $other->claims()->create([
            'claim_reference' => 'CT-OTHER-1',
            'shipment_id' => $otherShipment->getKey(),
            'claim_category' => 'detention',
            'requested_amount' => 100.00,
            'currency' => 'USD',
        ]);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims/'.$otherClaim->getKey())
            ->assertNotFound();

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_create_claim_with_write_scope(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read', 'claims:write']);
        $shipment = $this->createShipment($organization);

        $response = $this->withHeaders($this->keyHeaders($plain))
            ->postJson('/api/public/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'claim_category' => 'detention',
                'requested_amount' => 450.00,
                'currency' => 'USD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.shipment_id', $shipment->getKey())
            ->assertJsonPath('data.status', 'potential')
            ->assertJsonPath('data.requested_amount', '450.00');

        $this->assertDatabaseHas('claims', [
            'shipment_id' => $shipment->getKey(),
            'requested_amount' => 450.00,
        ]);
    }

    public function test_create_claim_without_write_scope_is_403(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->keyHeaders($plain))
            ->postJson('/api/public/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 450.00,
            ])
            ->assertForbidden();
    }

    public function test_free_plan_is_402(): void
    {
        [$user, $organization] = $this->setupOrg('free');
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded');
    }

    public function test_rotation_old_key_works_during_grace_then_stops(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $service = app(ApiKeyService::class);
        $rotated = $service->rotate($key, $user);
        $newPlain = $rotated['plain_text'];

        // The old key still works inside the grace window.
        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertOk();

        // The replacement works immediately.
        $this->withHeaders($this->keyHeaders($newPlain))
            ->getJson('/api/public/v1/claims')
            ->assertOk();

        // Past the grace window the old key is refused.
        $key->refresh();
        $key->update(['grace_ends_at' => now()->subMinute()]);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertUnauthorized();

        // The replacement still works.
        $this->withHeaders($this->keyHeaders($newPlain))
            ->getJson('/api/public/v1/claims')
            ->assertOk();
    }

    public function test_revoked_key_is_401_immediately(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        app(ApiKeyService::class)->revoke($key, $user);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims')
            ->assertUnauthorized();
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        config(['public_api.rate_limit_per_minute' => 2]);

        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $this->withHeaders($this->keyHeaders($plain))->getJson('/api/public/v1/claims')->assertOk();
        $this->withHeaders($this->keyHeaders($plain))->getJson('/api/public/v1/claims')->assertOk();

        $response = $this->withHeaders($this->keyHeaders($plain))->getJson('/api/public/v1/claims');

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_broker_key_reads_claims_and_broker_status_only(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey(
            $organization,
            ['claims:read', 'broker:status'],
            ApiKey::KIND_BROKER,
            'Test Broker LLC',
        );

        $shipment = $this->createShipment($organization);
        $claim = $organization->claims()->create([
            'claim_reference' => 'CT-BROKER-1',
            'shipment_id' => $shipment->getKey(),
            'claim_category' => 'detention',
            'requested_amount' => 300.00,
            'currency' => 'USD',
        ]);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/claims/'.$claim->getKey())
            ->assertOk();

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/broker/claims/'.$claim->getKey().'/status')
            ->assertOk()
            ->assertJsonPath('data.broker_name', 'Test Broker LLC')
            ->assertJsonPath('data.claim_reference', 'CT-BROKER-1')
            ->assertJsonPath('data.broker_status', 'not_submitted')
            ->assertJsonPath('data.live_available', false);

        // A broker key cannot write or read shipments.
        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/shipments')
            ->assertForbidden();

        $this->withHeaders($this->keyHeaders($plain))
            ->postJson('/api/public/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertForbidden();
    }

    public function test_standard_key_cannot_call_broker_status(): void
    {
        [$user, $organization] = $this->setupOrg();
        [$key, $plain] = $this->issueKey($organization, ['claims:read']);

        $shipment = $this->createShipment($organization);
        $claim = $organization->claims()->create([
            'claim_reference' => 'CT-STD-1',
            'shipment_id' => $shipment->getKey(),
            'claim_category' => 'detention',
            'requested_amount' => 300.00,
            'currency' => 'USD',
        ]);

        $this->withHeaders($this->keyHeaders($plain))
            ->getJson('/api/public/v1/broker/claims/'.$claim->getKey().'/status')
            ->assertForbidden();
    }

    public function test_key_management_needs_permission_and_shows_key_once(): void
    {
        [$user, $organization] = $this->setupOrg();

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/api-keys', [
                'name' => 'Integration key',
                'scopes' => ['claims:read'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Integration key')
            ->assertJsonPath('data.key_prefix', substr($response->json('plain_text_key'), 0, 12))
            ->assertJsonMissing(['key_hash' => $response->json('plain_text_key')]);

        $plainText = $response->json('plain_text_key');
        $this->assertStringStartsWith('ct_live_', $plainText);

        // The full key is never returned again: the show
        // response carries only the prefix.
        $keyId = $response->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/api-keys/'.$keyId)
            ->assertOk()
            ->assertJsonMissing(['plain_text_key'])
            ->assertJsonPath('data.key_prefix', substr($plainText, 0, 12));

        // The stored hash matches the shown key, and the
        // hash alone is never returned.
        $key = ApiKey::find($keyId);
        $this->assertSame(hash('sha256', $plainText), $key->key_hash);
    }

    public function test_key_management_rejects_unknown_and_broker_scopes(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/api-keys', [
                'name' => 'Bad key',
                'scopes' => ['claims:delete'],
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/api-keys', [
                'name' => 'Broker key without broker',
                'kind' => 'broker',
                'scopes' => ['claims:read', 'broker:status'],
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/api-keys', [
                'name' => 'Standard key with broker scope',
                'scopes' => ['claims:read', 'broker:status'],
            ])
            ->assertUnprocessable();
    }

    public function test_key_management_free_plan_is_402(): void
    {
        [$user, $organization] = $this->setupOrg('free');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/api-keys', [
                'name' => 'Free key',
                'scopes' => ['claims:read'],
            ])
            ->assertStatus(402);
    }
}
