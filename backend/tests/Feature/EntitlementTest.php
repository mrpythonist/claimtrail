<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Plan entitlements (v1.2.1, v0.5.0 sections 8 and 9.2).
 *
 * Limits are enforced by the backend, not the frontend: creating
 * a claim, inviting a member, uploading evidence, generating a
 * PDF package, or viewing reports above the plan's limits is
 * refused with HTTP 402 and an upgrade prompt naming the limit
 * hit and the tier that removes it. Shipments are deliberately
 * NOT capped (v0.5.0 section 9.2): the claim cap is the binding
 * limit.
 */
class EntitlementTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Create a claim through the API for the given shipment.
     */
    protected function postClaim(Organization $organization, $shipment, array $extra = [])
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', array_merge([
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ], $extra));
    }

    public function test_free_plan_allows_five_claims_then_refuses_the_sixth_with_402(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);

        for ($i = 0; $i < 5; $i++) {
            $this->postClaim($organization, $shipment)->assertCreated();
        }

        $this->postClaim($organization, $shipment)
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded')
            ->assertJsonPath('limit.resource', 'claims')
            ->assertJsonPath('limit.limit', 5)
            ->assertJsonPath('limit.used', 5)
            ->assertJsonPath('limit.plan', 'free')
            ->assertJsonPath('limit.upgrade_plan', 'starter');

        $this->assertEquals(5, $organization->claims()->count());
    }

    public function test_claims_from_another_organization_do_not_count_toward_the_cap(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipmentA = $this->createShipment($organizationA);

        for ($i = 0; $i < 5; $i++) {
            $this->postClaim($organizationA, $shipmentA)->assertCreated();
        }

        // Organization A is at its cap; organization B still has
        // its own five.
        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipmentB = $this->createShipment($organizationB);

        $this->postClaim($organizationB, $shipmentB)->assertCreated();
    }

    public function test_trial_organization_gets_growth_limits(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        // Five claims fill the Free cap; the trial lifts it.
        for ($i = 0; $i < 5; $i++) {
            $this->postClaim($organization, $shipment)->assertCreated();
        }

        $this->postClaim($organization, $shipment)->assertStatus(402);

        app(\App\Services\BillingService::class)->startTrial($organization, null);

        $this->postClaim($organization, $shipment)->assertCreated();
    }

    public function test_success_fee_plan_has_no_claim_cap(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $this->subscribe($organization, 'success_fee');
        $shipment = $this->createShipment($organization);

        for ($i = 0; $i < 6; $i++) {
            $this->postClaim($organization, $shipment)->assertCreated();
        }
    }

    public function test_free_plan_refuses_invitations_at_the_member_cap(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        // Free allows 1 member and the owner is that member.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', ['email' => 'second@example.com', 'role' => 'viewer'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded')
            ->assertJsonPath('limit.resource', 'members')
            ->assertJsonPath('limit.limit', 1)
            ->assertJsonPath('limit.used', 1)
            ->assertJsonPath('limit.upgrade_plan', 'starter');
    }

    public function test_starter_plan_allows_invitations_up_to_its_member_cap(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        // Starter allows 3 members: the owner plus two invites.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', ['email' => 'one@example.com', 'role' => 'viewer'])
            ->assertCreated();
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', ['email' => 'two@example.com', 'role' => 'viewer'])
            ->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', ['email' => 'three@example.com', 'role' => 'viewer'])
            ->assertStatus(402)
            ->assertJsonPath('limit.resource', 'members')
            ->assertJsonPath('limit.limit', 3)
            ->assertJsonPath('limit.used', 3)
            ->assertJsonPath('limit.upgrade_plan', 'growth');
    }

    public function test_upload_refused_when_storage_would_exceed_the_cap(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);

        // Free allows 100 MB. Seed 100 MB of evidence rows (no
        // files on disk: the entitlement check runs before the
        // upload is written, so only the recorded sizes matter).
        $this->createEvidence($organization, $shipment, ['file_size' => 100 * 1024 * 1024]);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'bill_of_lading',
                'file' => UploadedFile::fake()->create('small.pdf', 10),
            ])
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded')
            ->assertJsonPath('limit.resource', 'storage')
            ->assertJsonPath('limit.limit', 100 * 1024 * 1024)
            ->assertJsonPath('limit.upgrade_plan', 'starter');

        $this->assertEquals(1, $organization->evidenceFiles()->count());
    }

    public function test_free_plan_cannot_generate_pdf_packages_but_starter_can(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded')
            ->assertJsonPath('limit.resource', 'pdf_packages')
            ->assertJsonPath('limit.upgrade_plan', 'starter');

        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertStatus(202);
    }

    public function test_free_plan_cannot_view_reports_but_starter_can(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertStatus(402)
            ->assertJsonPath('error', 'plan_limit_exceeded')
            ->assertJsonPath('limit.resource', 'reports')
            ->assertJsonPath('limit.upgrade_plan', 'starter');

        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertOk();
    }

    public function test_downgrade_keeps_existing_claims_but_blocks_new_ones_over_the_lower_cap(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth');
        $shipment = $this->createShipment($organization);

        // Six claims fit under Growth (100) but not under Free (5).
        for ($i = 0; $i < 6; $i++) {
            $this->postClaim($organization, $shipment)->assertCreated();
        }

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/change-plan', ['plan' => 'free'])
            ->assertOk()
            ->assertJsonPath('data.effective', 'at_period_end');

        $this->endPeriod($subscription);
        Artisan::call('billing:renew');

        $this->assertSame('free', $subscription->fresh()->plan);

        // Nothing was deleted by the downgrade.
        $this->assertEquals(6, $organization->claims()->count());
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims')
            ->assertOk()
            ->assertJsonCount(6, 'data');

        // New creation above the Free cap is refused.
        $this->postClaim($organization, $shipment)
            ->assertStatus(402)
            ->assertJsonPath('limit.limit', 5)
            ->assertJsonPath('limit.used', 6);
    }

    public function test_claim_carryover_clears_at_the_next_renewal(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth');
        $shipment = $this->createShipment($organization);

        for ($i = 0; $i < 6; $i++) {
            $this->postClaim($organization, $shipment)->assertCreated();
        }

        // The claims were created days before the renewal in
        // reality. Backdate them so the test does not depend on the
        // renewal landing in a later second than the (sub-second)
        // API calls that created them: timestamps here have
        // one-second precision.
        \Illuminate\Support\Facades\DB::table('claims')
            ->where('organization_id', $organization->getKey())
            ->update(['created_at' => now()->subDays(5)]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/change-plan', ['plan' => 'free'])
            ->assertOk();

        $this->endPeriod($subscription);
        Artisan::call('billing:renew');

        // Still blocked in the downgrade period: the 6 old claims
        // carry over against the Free cap of 5.
        $this->postClaim($organization, $shipment)->assertStatus(402);

        // One more renewal clears the carryover: a fresh Free
        // period starts with a clean allowance.
        $this->endPeriod($subscription->fresh());
        Artisan::call('billing:renew');

        $this->assertSame(0, (int) $subscription->fresh()->claims_carryover);
        $this->postClaim($organization, $shipment)->assertCreated();
    }

    public function test_usage_endpoint_reports_limits_and_usage(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment);
        $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/usage')
            ->assertOk()
            ->assertJsonPath('data.entitlements.plan', 'free')
            ->assertJsonPath('data.entitlements.limits.claims_per_month', 5)
            ->assertJsonPath('data.entitlements.limits.members', 1)
            ->assertJsonPath('data.entitlements.limits.storage_bytes', 100 * 1024 * 1024)
            ->assertJsonPath('data.entitlements.limits.pdf_packages', false)
            ->assertJsonPath('data.entitlements.limits.reports', false)
            ->assertJsonPath('data.entitlements.usage.claims_created', 2)
            ->assertJsonPath('data.entitlements.usage.members', 1)
            ->assertJsonPath('data.entitlements.upgrade.claims.key', 'starter');
    }

    public function test_shipments_are_not_capped(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);

        // Ten shipments on the Free plan: the claim cap is the
        // binding limit, shipments are never refused (v0.5.0
        // section 9.2).
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson('/api/v1/shipments', [
                    'internal_reference' => 'CT-'.$i,
                    'broker_or_customer_name' => 'Test Broker Co.',
                ])
                ->assertCreated();
        }
    }

    public function test_agency_claim_cap_scales_with_carrier_count(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $this->subscribe($organization, 'agency');
        $this->createCarrier($organization);
        $shipment = $this->createShipment($organization);

        // Agency with one carrier: 100 claims per month.
        for ($i = 0; $i < 100; $i++) {
            $this->createClaim($organization, $shipment);
        }

        $this->postClaim($organization, $shipment)->assertStatus(402);

        // A second carrier doubles the cap to 200.
        $this->createCarrier($organization);

        $this->postClaim($organization, $shipment)->assertCreated();
    }

    /**
     * Backdate a subscription's period so the renewal is due.
     */
    protected function endPeriod($subscription, int $daysAgo = 1): void
    {
        \Illuminate\Support\Facades\DB::table('subscriptions')
            ->where('id', $subscription->getKey())
            ->update([
                'current_period_start' => now()->subDays(31 + $daysAgo),
                'current_period_end' => now()->subDays($daysAgo),
            ]);
    }
}
