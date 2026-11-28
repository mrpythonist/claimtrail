<?php

namespace Tests\Feature;

use App\Billing\PlanCatalog;
use App\Models\AgencyCarrierGrant;
use App\Models\AuditLog;
use App\Models\ClaimPayment;
use App\Models\CommissionRule;
use App\Models\Organization;
use App\Models\PartnerLink;
use App\Models\ReferralCode;
use App\Models\User;
use App\OrganizationRole;
use App\Services\AgencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Agency management (v2.1.0).
 *
 * Partner links with carrier-side approval, per-carrier
 * permission grants, the agency dashboard and consolidated
 * reporting computed only from recorded claims and payments,
 * referral codes with attribution, commission rules and
 * computed earnings, and reseller workflows (onboarding
 * approval and plan assignment). Every agency endpoint needs
 * manage-agency and the Agency plan: every other plan is
 * refused with a 402.
 */
class AgencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setupAgency(string $plan = 'agency', OrganizationRole $role = OrganizationRole::Owner): array
    {
        [$user, $organization] = $this->actingAsMemberOf($role);

        $this->subscribe($organization, $plan);

        return [$user, $organization];
    }

    protected function setupCarrier(string $plan = 'starter'): array
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->subscribe($organization, $plan);

        return [$user, $organization];
    }

    protected function memberOf(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->addMember($organization, $user, $role);

        return $user;
    }

    protected function actAs(User $user): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    protected function approvedLink(Organization $agency, Organization $carrier, ?string $code = null): PartnerLink
    {
        $agencyUser = $agency->members()->first()->user;
        $carrierUser = $carrier->members()->first()->user;

        $service = app(AgencyService::class);
        $link = $service->createLink($agency, $carrier->getKey(), $code, null, $agencyUser);

        return $service->approveLink($link, $carrierUser);
    }

    /**
     * Seed a carrier org with one carrier row, one shipment, and
     * claims with explicit amounts and statuses, plus one payment
     * per claim in $payments (claim index => amount).
     *
     * @param array<int, array{requested: float, accepted: float|null, recovered: float, status: \App\ClaimStatus, paid_at: string|null}> $claims
     * @param array<int, float> $payments
     */
    protected function seedCarrier(Organization $carrier, array $claims, array $payments = []): array
    {
        $carrierRow = $this->createCarrier($carrier);
        $shipment = $this->createShipment($carrier, ['carrier_id' => $carrierRow->getKey()]);

        $created = [];

        foreach ($claims as $index => $spec) {
            $claim = $this->createClaim($carrier, $shipment, [
                'requested_amount' => $spec['requested'],
                'accepted_amount' => $spec['accepted'],
                'recovered_amount' => $spec['recovered'],
                'status' => $spec['status'],
                'currency' => 'USD',
            ]);

            if (isset($payments[$index])) {
                $claim->payments()->create([
                    'organization_id' => $carrier->getKey(),
                    'amount' => $payments[$index],
                    'currency' => 'USD',
                    'payment_method' => 'ach',
                    'payment_reference' => 'PAY-'.$index,
                    'paid_at' => $spec['paid_at'] ?? now()->toDateString(),
                ]);
            }

            $created[] = $claim;
        }

        return $created;
    }

    protected function agencyHeaders(Organization $organization): array
    {
        return $this->orgHeaders($organization);
    }

    // -- Plan gate and permissions -----------------------------------------

    public function test_agency_dashboard_requires_agency_plan(): void
    {
        foreach (['free', 'starter', 'growth', 'fleet'] as $plan) {
            [$user, $organization] = $this->setupAgency($plan);
            $this->actAs($user);

            $this->withHeaders($this->agencyHeaders($organization))
                ->getJson('/api/v1/agency/dashboard')
                ->assertStatus(402)
                ->assertJsonPath('error', 'plan_limit_exceeded')
                ->assertJsonPath('limit.resource', 'agency');
        }
    }

    public function test_agency_dashboard_ok_on_agency_plan(): void
    {
        [$user, $organization] = $this->setupAgency('agency');
        $this->actAs($user);

        $this->withHeaders($this->agencyHeaders($organization))
            ->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.agency_organization_id', $organization->getKey())
            ->assertJsonPath('data.carriers', [])
            ->assertJsonPath('data.link_counts.total', 0)
            ->assertJsonPath('data.referrals.codes_total', 0);
    }

    public function test_dispatcher_cannot_use_agency_endpoints(): void
    {
        [$user, $organization] = $this->setupAgency('agency', OrganizationRole::Dispatcher);
        $this->actAs($user);

        $this->withHeaders($this->agencyHeaders($organization))
            ->getJson('/api/v1/agency/dashboard')
            ->assertForbidden();
    }

    public function test_viewer_cannot_use_agency_endpoints(): void
    {
        [$user, $organization] = $this->setupAgency('agency', OrganizationRole::Viewer);
        $this->actAs($user);

        $this->withHeaders($this->agencyHeaders($organization))
            ->getJson('/api/v1/agency/dashboard')
            ->assertForbidden();
    }

    // -- Partner links -----------------------------------------------------

    public function test_partner_link_created_pending(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', [
                'carrier_organization_id' => $carrier->getKey(),
                'notes' => 'Referred by a mutual broker.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.agency_organization_id', $agency->getKey())
            ->assertJsonPath('data.carrier_organization_id', $carrier->getKey());

        $this->assertSame(1, PartnerLink::count());
        $this->assertSame(1, AuditLog::where('action', 'partner_link.created')->count());
    }

    public function test_partner_link_to_self_is_rejected(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', [
                'carrier_organization_id' => $agency->getKey(),
            ])
            ->assertUnprocessable();
    }

    public function test_duplicate_partner_link_is_rejected(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', ['carrier_organization_id' => $carrier->getKey()])
            ->assertCreated();

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', ['carrier_organization_id' => $carrier->getKey()])
            ->assertUnprocessable();
    }

    public function test_carrier_owner_approves_link(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [$carrierUser, $carrier] = $this->setupCarrier();

        $service = app(AgencyService::class);
        $link = $service->createLink($agency, $carrier->getKey(), null, null, $agencyUser);

        // The agency cannot approve its own link.
        $this->actAs($agencyUser);
        $this->postJson('/api/v1/partner-links/'.$link->getKey().'/approve')
            ->assertForbidden();

        // The carrier owner can.
        $this->actAs($carrierUser);
        $this->postJson('/api/v1/partner-links/'.$link->getKey().'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $carrierUser->getKey());

        // Approving twice is a 422.
        $this->postJson('/api/v1/partner-links/'.$link->getKey().'/approve')
            ->assertUnprocessable();
    }

    public function test_carrier_owner_rejects_link(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [$carrierUser, $carrier] = $this->setupCarrier();

        $service = app(AgencyService::class);
        $link = $service->createLink($agency, $carrier->getKey(), null, null, $agencyUser);

        $this->actAs($carrierUser);
        $this->postJson('/api/v1/partner-links/'.$link->getKey().'/reject')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_pending_links_listed_for_carrier_only(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [$carrierUser, $carrier] = $this->setupCarrier();
        [$otherUser] = $this->setupCarrier();

        $service = app(AgencyService::class);
        $service->createLink($agency, $carrier->getKey(), null, null, $agencyUser);

        $this->actAs($carrierUser);
        $this->getJson('/api/v1/partner-links/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actAs($otherUser);
        $this->getJson('/api/v1/partner-links/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_link_suspend_reactivate_remove_lifecycle(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        // A suspended link grants no access: the carrier claims
        // endpoint refuses with 403.
        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims')
            ->assertForbidden();

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/reactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims')
            ->assertOk();

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/remove')
            ->assertOk()
            ->assertJsonPath('data.status', 'removed');

        // The row is kept, never deleted.
        $this->assertSame(1, PartnerLink::count());
    }

    public function test_cross_tenant_link_is_404(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);

        [$otherAgencyUser, $otherAgency] = $this->setupAgency();
        $this->actAs($otherAgencyUser);

        $this->withHeaders($this->agencyHeaders($otherAgency))
            ->getJson('/api/v1/agency/partners/'.$link->getKey())
            ->assertNotFound();
    }

    // -- Reseller plan assignment ------------------------------------------

    public function test_plan_assignment_changes_carrier_plan(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier('free');
        $link = $this->approvedLink($agency, $carrier);
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/plan', ['plan' => 'starter'])
            ->assertOk()
            ->assertJsonPath('data.assigned_plan', 'starter');

        $this->assertSame('starter', app(\App\Services\BillingService::class)->subscriptionFor($carrier)->plan);
    }

    public function test_plan_assignment_unknown_plan_is_422(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/plan', ['plan' => 'platinum'])
            ->assertUnprocessable();
    }

    public function test_plan_assignment_needs_active_link(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();

        $service = app(AgencyService::class);
        $link = $service->createLink($agency, $carrier->getKey(), null, null, $agencyUser);
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/plan', ['plan' => 'starter'])
            ->assertUnprocessable();
    }

    // -- Dashboard and consolidated reporting ------------------------------

    public function test_dashboard_aggregates_two_carriers(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrierA] = $this->setupCarrier();
        [, $carrierB] = $this->setupCarrier();

        $this->seedCarrier($carrierA, [
            ['requested' => 500.00, 'accepted' => 400.00, 'recovered' => 250.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => null],
            ['requested' => 300.00, 'accepted' => null, 'recovered' => 0.00, 'status' => \App\ClaimStatus::Potential, 'paid_at' => null],
        ], [0 => 250.00]);
        $this->seedCarrier($carrierB, [
            ['requested' => 1000.00, 'accepted' => 1000.00, 'recovered' => 1000.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => null],
        ], [0 => 1000.00]);

        $this->approvedLink($agency, $carrierA);
        $this->approvedLink($agency, $carrierB);
        $this->actAs($agencyUser);

        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['carriers']);
        $this->assertSame(2, $data['link_counts']['approved']);

        $carrierAData = collect($data['carriers'])->firstWhere('carrier.organization_id', $carrierA->getKey());
        $this->assertSame(2, $carrierAData['claims_total']);
        $this->assertSame(1, $carrierAData['by_status']['paid']);
        $this->assertSame('800.00', $carrierAData['totals'][0]['requested']);
        $this->assertSame('400.00', $carrierAData['totals'][0]['accepted']);
        $this->assertSame('250.00', $carrierAData['totals'][0]['recovered']);
        $this->assertSame('250.00', $carrierAData['totals'][0]['payments']);
        // Outstanding is computed on the bucket totals, not per
        // claim: the amount due is the accepted total when any
        // amount is accepted, else the requested total, minus
        // recovered. Here due is 400 (accepted) - 250 recovered
        // = 150; the potential claim's requested 300 has no
        // accepted amount, so it is not in the due.
        $this->assertSame('150.00', $carrierAData['totals'][0]['outstanding']);

        $totals = $data['totals_by_currency'][0];
        $this->assertSame('USD', $totals['currency']);
        $this->assertSame('1800.00', $totals['requested']);
        $this->assertSame('1250.00', $totals['payments']);
    }

    public function test_consolidated_report_filters_by_carrier_and_date(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrierA] = $this->setupCarrier();
        [, $carrierB] = $this->setupCarrier();

        $this->seedCarrier($carrierA, [
            ['requested' => 500.00, 'accepted' => 500.00, 'recovered' => 500.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => '2026-11-05'],
        ], [0 => 500.00]);
        $this->seedCarrier($carrierB, [
            ['requested' => 700.00, 'accepted' => null, 'recovered' => 0.00, 'status' => \App\ClaimStatus::Potential, 'paid_at' => null],
        ]);

        $this->approvedLink($agency, $carrierA);
        $this->approvedLink($agency, $carrierB);
        $this->actAs($agencyUser);

        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/reports/consolidated?carrier_organization_id='.$carrierA->getKey())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['carriers']);
        $this->assertSame('500.00', $data['totals_by_currency'][0]['payments']);

        // A date window after the payment excludes it.
        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/reports/consolidated?date_from=2026-11-10&date_to=2026-11-30')
            ->assertOk()
            ->json('data');

        $this->assertSame('0.00', $data['totals_by_currency'][0]['payments'] ?? '0.00');
    }

    public function test_consolidated_report_unlinked_carrier_is_404(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/reports/consolidated?carrier_organization_id='.$carrier->getKey())
            ->assertNotFound();
    }

    // -- Carrier claims (scoped read) --------------------------------------

    public function test_carrier_claims_scoped_by_grant(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $claims = $this->seedCarrier($carrier, [
            ['requested' => 500.00, 'accepted' => null, 'recovered' => 0.00, 'status' => \App\ClaimStatus::Potential, 'paid_at' => null],
        ]);

        // An operations manager without a grant is refused.
        $ops = $this->memberOf($agency, OrganizationRole::OperationsManager);
        $this->actAs($ops);
        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims')
            ->assertForbidden();

        // A view grant opens read access.
        $service = app(AgencyService::class);
        $service->createGrant($link, $ops, AgencyCarrierGrant::SCOPE_VIEW, $agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims/'.$claims[0]->getKey())
            ->assertOk()
            ->assertJsonPath('data.claim_reference', $claims[0]->claim_reference);
    }

    public function test_carrier_claims_unlinked_carrier_is_404(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/carriers/'.$carrier->getKey().'/claims')
            ->assertNotFound();
    }

    // -- Referral codes ----------------------------------------------------

    public function test_referral_code_create_list_show_and_deactivate(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $code = $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/referrals', ['notes' => 'Broker conference'])
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.uses_count', 0)
            ->json('data');

        $this->assertStringStartsWith('REF-', $code['code']);

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/referrals')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // A link created with the code records the attribution.
        $service = app(AgencyService::class);
        $link = $service->createLink($agency, $carrier->getKey(), $code['code'], null, $agencyUser);
        $this->assertSame($code['code'], $link->referred_by_code);

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/referrals/'.$code['id'])
            ->assertOk()
            ->assertJsonPath('data.uses_count', 1)
            ->assertJsonCount(1, 'data.attributed_links');

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/referrals/'.$code['id'].'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.active', false);

        // A deactivated code cannot attribute a new link.
        [, $carrierB] = $this->setupCarrier();
        $this->actAs($agencyUser);
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', [
                'carrier_organization_id' => $carrierB->getKey(),
                'referral_code' => $code['code'],
            ])
            ->assertUnprocessable();

        // But the old link keeps its attribution.
        $this->assertSame($code['code'], $link->fresh()->referred_by_code);
    }

    public function test_unknown_referral_code_is_422(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners', [
                'carrier_organization_id' => $carrier->getKey(),
                'referral_code' => 'REF-NOSUCH',
            ])
            ->assertUnprocessable();
    }

    // -- Commission rules and earnings -------------------------------------

    public function test_commission_default_rule_and_earnings(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->seedCarrier($carrier, [
            ['requested' => 500.00, 'accepted' => 500.00, 'recovered' => 500.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => null],
        ], [0 => 500.00]);
        $this->approvedLink($agency, $carrier);
        $this->actAs($agencyUser);

        // No rule yet: the configured default rate (10%) applies.
        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/commissions')
            ->assertOk()
            ->json('data');

        $this->assertSame('10.00', $data['carriers'][0]['rate_percent']);
        $this->assertSame('500.00', $data['carriers'][0]['by_currency'][0]['payments']);
        $this->assertSame('50.00', $data['carriers'][0]['by_currency'][0]['earned']);
        $this->assertSame('50.00', $data['totals_by_currency'][0]['earned']);

        // A default rule at 20% replaces it.
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', ['rate_percent' => 20])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/commissions')
            ->assertOk()
            ->json('data');

        $this->assertSame('100.00', $data['carriers'][0]['by_currency'][0]['earned']);

        // A second active default is refused.
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', ['rate_percent' => 15])
            ->assertUnprocessable();
    }

    public function test_commission_cap_bounds_earnings(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->seedCarrier($carrier, [
            ['requested' => 1000.00, 'accepted' => 1000.00, 'recovered' => 1000.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => null],
        ], [0 => 1000.00]);
        $link = $this->approvedLink($agency, $carrier);
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', [
                'partner_link_id' => $link->getKey(),
                'rate_percent' => 20,
                'cap_cents' => 15000,
            ])
            ->assertCreated();

        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/commissions')
            ->assertOk()
            ->json('data');

        // 20% of 1000 is 200, capped at 150.
        $this->assertSame('150.00', $data['carriers'][0]['by_currency'][0]['earned']);
        $this->assertTrue($data['carriers'][0]['by_currency'][0]['capped']);
    }

    public function test_commission_rule_update_and_delete(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        $this->actAs($agencyUser);

        $rule = $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', ['rate_percent' => 12.5])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->agencyHeaders($agency))
            ->putJson('/api/v1/agency/commission-rules/'.$rule['id'], ['rate_percent' => 15])
            ->assertOk()
            ->assertJsonPath('data.rate_percent', '15.00');

        $this->withHeaders($this->agencyHeaders($agency))
            ->deleteJson('/api/v1/agency/commission-rules/'.$rule['id'])
            ->assertOk();

        $this->assertSame(0, CommissionRule::count());
    }

    public function test_commission_rule_needs_manage_scope(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);

        $ops = $this->memberOf($agency, OrganizationRole::OperationsManager);
        $service = app(AgencyService::class);
        $service->createGrant($link, $ops, AgencyCarrierGrant::SCOPE_VIEW, $agencyUser);

        // A view grant does not cover rule creation.
        $this->actAs($ops);
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', [
                'partner_link_id' => $link->getKey(),
                'rate_percent' => 10,
            ])
            ->assertForbidden();

        // A manage grant does.
        $service->createGrant($link, $ops, AgencyCarrierGrant::SCOPE_MANAGE, $agencyUser);
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/commission-rules', [
                'partner_link_id' => $link->getKey(),
                'rate_percent' => 10,
            ])
            ->assertCreated();
    }

    public function test_agency_commissions_command(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $this->seedCarrier($carrier, [
            ['requested' => 400.00, 'accepted' => 400.00, 'recovered' => 400.00, 'status' => \App\ClaimStatus::Paid, 'paid_at' => null],
        ], [0 => 400.00]);
        $this->approvedLink($agency, $carrier);

        $exit = Artisan::call('agency:commissions', ['--organization' => $agency->getKey()]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString($carrier->name, $output);
        $this->assertStringContainsString('40.00 earned', $output);
    }

    // -- Grants ------------------------------------------------------------

    public function test_grants_create_replace_and_delete(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $ops = $this->memberOf($agency, OrganizationRole::OperationsManager);
        $this->actAs($agencyUser);

        $grant = $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/grants', [
                'user_id' => $ops->getKey(),
                'scope' => 'view',
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'view')
            ->json('data');

        // Granting again replaces the scope, it does not duplicate.
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/grants', [
                'user_id' => $ops->getKey(),
                'scope' => 'manage',
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'manage');

        $this->assertSame(1, AgencyCarrierGrant::count());

        $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/partners/'.$link->getKey().'/grants')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->agencyHeaders($agency))
            ->deleteJson('/api/v1/agency/partners/'.$link->getKey().'/grants/'.$grant['id'])
            ->assertOk();

        $this->assertSame(0, AgencyCarrierGrant::count());
    }

    public function test_grant_for_non_member_is_422(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $outsider = User::factory()->create();
        $this->actAs($agencyUser);

        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/grants', [
                'user_id' => $outsider->getKey(),
                'scope' => 'view',
            ])
            ->assertUnprocessable();
    }

    public function test_grant_administration_is_admin_only(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrier] = $this->setupCarrier();
        $link = $this->approvedLink($agency, $carrier);
        $ops = $this->memberOf($agency, OrganizationRole::OperationsManager);
        $other = $this->memberOf($agency, OrganizationRole::OperationsManager);

        $this->actAs($ops);
        $this->withHeaders($this->agencyHeaders($agency))
            ->postJson('/api/v1/agency/partners/'.$link->getKey().'/grants', [
                'user_id' => $other->getKey(),
                'scope' => 'view',
            ])
            ->assertForbidden();
    }

    public function test_dashboard_hides_ungranted_carriers_for_ops_manager(): void
    {
        [$agencyUser, $agency] = $this->setupAgency();
        [, $carrierA] = $this->setupCarrier();
        [, $carrierB] = $this->setupCarrier();
        $linkA = $this->approvedLink($agency, $carrierA);
        $this->approvedLink($agency, $carrierB);

        $ops = $this->memberOf($agency, OrganizationRole::OperationsManager);
        app(AgencyService::class)->createGrant($linkA, $ops, AgencyCarrierGrant::SCOPE_VIEW, $agencyUser);

        $this->actAs($ops);
        $data = $this->withHeaders($this->agencyHeaders($agency))
            ->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['carriers']);
        $this->assertSame($carrierA->getKey(), $data['carriers'][0]['carrier']['organization_id']);
    }
}
