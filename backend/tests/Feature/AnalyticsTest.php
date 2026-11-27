<?php

namespace Tests\Feature;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\Claim;
use App\Models\Shipment;
use App\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Advanced analytics (v1.5.1).
 *
 * Every metric is computed from the organization's own recorded
 * claims, shipments, and payments. Rates use the submitted
 * population (claims whose current status reached submission);
 * claims still being prepared never enter a denominator.
 * Recovery vs requested is paid over requested, recovery vs
 * approved is paid over approved, rejection is rejected over
 * submitted. Average submission time is submitted_at minus
 * created_at; average payment time is paid_at minus approved_at
 * (or submitted_at when no approval is recorded). Money is
 * summed as recorded, with no conversion, and every average
 * carries its sample size. Rates are null when their
 * denominator is zero.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A claim with known money, category, status, and
     * timestamps, on the given shipment.
     */
    protected function seedClaim($organization, Shipment $shipment, array $attributes = []): Claim
    {
        $claim = $this->createClaim($organization, $shipment, $attributes);

        $claim->forceFill([
            'created_at' => $attributes['created_at'] ?? CarbonImmutable::parse('2026-01-01 09:00:00'),
            'updated_at' => $attributes['created_at'] ?? CarbonImmutable::parse('2026-01-01 09:00:00'),
        ])->save();

        return $claim->fresh();
    }

    /**
     * The January fixture: one paid detention claim, one
     * rejected lumper claim, one accepted tonu claim with a
     * partial payment, and one potential claim that never
     * entered the submitted population.
     */
    protected function seedJanuary($organization): array
    {
        $brokerA = $this->createShipment($organization, ['broker_or_customer_name' => 'Alpha Brokers']);
        $brokerB = $this->createShipment($organization, ['broker_or_customer_name' => 'Beta Freight']);
        $carrier = $this->createCarrier($organization, ['name' => 'Acme Carriers']);
        $brokerB->update(['carrier_id' => $carrier->getKey()]);

        $paid = $this->seedClaim($organization, $brokerA, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Paid,
            'requested_amount' => 200.00,
            'accepted_amount' => 200.00,
            'recovered_amount' => 200.00,
            'created_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-04 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2026-01-05 09:00:00'),
            'paid_at' => CarbonImmutable::parse('2026-01-10 09:00:00'),
        ]);

        $rejected = $this->seedClaim($organization, $brokerB, [
            'claim_category' => ClaimCategory::Lumper,
            'status' => ClaimStatus::Rejected,
            'requested_amount' => 100.00,
            'created_at' => CarbonImmutable::parse('2026-01-02 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-03 09:00:00'),
            'rejected_at' => CarbonImmutable::parse('2026-01-06 09:00:00'),
        ]);

        $partial = $this->seedClaim($organization, $brokerA, [
            'claim_category' => ClaimCategory::Tonu,
            'status' => ClaimStatus::Accepted,
            'requested_amount' => 100.00,
            'accepted_amount' => 100.00,
            'recovered_amount' => 50.00,
            'created_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-02 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2026-01-02 09:00:00'),
        ]);

        $potential = $this->seedClaim($organization, $brokerB, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Potential,
            'requested_amount' => 500.00,
            'created_at' => CarbonImmutable::parse('2026-01-05 09:00:00'),
        ]);

        return compact('paid', 'rejected', 'partial', 'potential', 'brokerA', 'brokerB', 'carrier');
    }

    protected function analyticsGet($organization, string $path): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->orgHeaders($organization))->getJson($path);
    }

    public function test_overview_is_empty_for_a_fresh_organization(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->analyticsGet($organization, '/api/v1/analytics/overview?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.counts.claims', 0)
            ->assertJsonPath('data.counts.submitted', 0)
            ->assertJsonPath('data.rates.recovery_rate_vs_requested', null)
            ->assertJsonPath('data.rates.rejection_rate', null)
            ->assertJsonPath('data.average_submission_days.days', null)
            ->assertJsonPath('data.average_submission_days.sample_size', 0)
            ->assertJsonPath('data.average_payment_days.days', null)
            ->assertJsonPath('data.average_payment_days.sample_size', 0)
            ->assertJsonPath('data.money.requested_amount', '0.00');
    }

    public function test_overview_rates_and_averages(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $data = $this->analyticsGet($organization, '/api/v1/analytics/overview?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->json('data');

        // Four claims, but the potential one never entered the
        // submitted population.
        $this->assertSame(4, $data['counts']['claims']);
        $this->assertSame(3, $data['counts']['submitted']);
        $this->assertSame(1, $data['counts']['paid']);
        $this->assertSame(1, $data['counts']['rejected']);

        // Requested over the submitted population: 200 + 100 + 100.
        // Paid: 200 + 50. Approved: 200 + 100.
        $this->assertSame('400.00', $data['money']['requested_amount']);
        $this->assertSame('300.00', $data['money']['approved_amount']);
        $this->assertSame('250.00', $data['money']['paid_amount']);
        $this->assertSame('50.00', $data['money']['outstanding_amount']);
        $this->assertEquals(62.5, $data['rates']['recovery_rate_vs_requested']);
        $this->assertEquals(83.33, $data['rates']['recovery_rate_vs_approved']);
        $this->assertEquals(33.33, $data['rates']['rejection_rate']);

        // Submission times: 3 days, 1 day, 1 day. Mean 1.67.
        $this->assertEquals(1.67, $data['average_submission_days']['days']);
        $this->assertSame(3, $data['average_submission_days']['sample_size']);

        // Payment time: only the paid claim, approved to paid is
        // 5 days.
        $this->assertEquals(5.0, $data['average_payment_days']['days']);
        $this->assertSame(1, $data['average_payment_days']['sample_size']);
    }

    public function test_overview_compares_against_the_preceding_window(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        // One more paid claim in December, so the January window
        // has something to compare against.
        $shipment = $this->createShipment($organization, ['broker_or_customer_name' => 'Alpha Brokers']);
        $this->seedClaim($organization, $shipment, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Paid,
            'requested_amount' => 100.00,
            'accepted_amount' => 100.00,
            'recovered_amount' => 100.00,
            'created_at' => CarbonImmutable::parse('2025-12-15 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2025-12-16 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2025-12-17 09:00:00'),
            'paid_at' => CarbonImmutable::parse('2025-12-20 09:00:00'),
        ]);

        $data = $this->analyticsGet($organization, '/api/v1/analytics/overview?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->json('data.comparison');

        $this->assertSame('2026-01-01', $data['from']);
        $this->assertSame('2026-01-31', $data['to']);
        $this->assertSame('2025-12-01', $data['previous_from']);
        $this->assertSame('2025-12-31', $data['previous_to']);
        $this->assertSame(4, $data['current']['claims']);
        $this->assertSame(1, $data['previous']['claims']);
        $this->assertSame(3, $data['change']['claims']);
        $this->assertEquals(100.0, $data['previous']['recovery_rate_vs_requested']);
        $this->assertEquals(-37.5, $data['change']['recovery_rate_vs_requested_pp']);
    }

    public function test_brokers_groups_by_shipment_broker_name(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->analyticsGet($organization, '/api/v1/analytics/brokers?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        // Alpha Brokers has the paid and the partial claim: two
        // claims, both submitted, recovery 250 over 300.
        $alpha = collect($rows)->firstWhere('broker', 'Alpha Brokers');
        $this->assertSame(2, $alpha['claims']);
        $this->assertSame(2, $alpha['submitted']);
        $this->assertSame('300.00', $alpha['requested_amount']);
        $this->assertSame('250.00', $alpha['paid_amount']);
        $this->assertEquals(83.33, $alpha['recovery_rate_vs_requested']);

        // Beta Freight has the rejected claim and the potential
        // one: two claims, one submitted, nothing recovered.
        $beta = collect($rows)->firstWhere('broker', 'Beta Freight');
        $this->assertSame(2, $beta['claims']);
        $this->assertSame(1, $beta['submitted']);
        $this->assertEquals(0.0, $beta['recovery_rate_vs_requested']);
        $this->assertEquals(100.0, $beta['rejection_rate']);
    }

    public function test_carriers_groups_by_carrier_and_unnamed(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->analyticsGet($organization, '/api/v1/analytics/carriers?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        $acme = collect($rows)->firstWhere('carrier', 'Acme Carriers');
        $this->assertSame(2, $acme['claims']);
        $this->assertSame(1, $acme['submitted']);

        $none = collect($rows)->firstWhere('carrier', 'No carrier assigned');
        $this->assertNull($none['carrier_id']);
        $this->assertSame(2, $none['claims']);
    }

    public function test_event_types_includes_all_categories_with_zero_rows(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->analyticsGet($organization, '/api/v1/analytics/event-types?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(7, $rows);

        $detention = collect($rows)->firstWhere('category', 'detention');
        $this->assertSame('Detention', $detention['category_label']);
        $this->assertSame(2, $detention['claims']);
        $this->assertSame(1, $detention['submitted']);

        $tonu = collect($rows)->firstWhere('category', 'tonu');
        $this->assertSame(1, $tonu['claims']);
        $this->assertEquals(50.0, $tonu['recovery_rate_vs_requested']);

        $empty = collect($rows)->firstWhere('category', 'additional_stop');
        $this->assertSame(0, $empty['claims']);
        $this->assertNull($empty['recovery_rate_vs_requested']);

        $lumper = collect($rows)->firstWhere('category', 'lumper');
        $this->assertEquals(100.0, $lumper['rejection_rate']);
    }

    public function test_monthly_series_buckets_by_created_month(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $shipment = $this->createShipment($organization, ['broker_or_customer_name' => 'Alpha Brokers']);
        $this->seedClaim($organization, $shipment, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Paid,
            'requested_amount' => 100.00,
            'accepted_amount' => 100.00,
            'recovered_amount' => 100.00,
            'created_at' => CarbonImmutable::parse('2026-02-10 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-02-11 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2026-02-12 09:00:00'),
            'paid_at' => CarbonImmutable::parse('2026-02-15 09:00:00'),
        ]);

        $points = $this->analyticsGet($organization, '/api/v1/analytics/monthly?from=2026-01-01&to=2026-02-28')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $points);
        $this->assertSame('2026-01', $points[0]['month']);
        $this->assertSame(4, $points[0]['claims_created']);
        $this->assertEquals(62.5, $points[0]['recovery_rate_vs_requested']);
        $this->assertSame('2026-02', $points[1]['month']);
        $this->assertSame(1, $points[1]['claims_created']);
        $this->assertEquals(100.0, $points[1]['recovery_rate_vs_requested']);
    }

    public function test_yearly_series_buckets_by_year(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $shipment = $this->createShipment($organization, ['broker_or_customer_name' => 'Alpha Brokers']);
        $this->seedClaim($organization, $shipment, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Paid,
            'requested_amount' => 100.00,
            'accepted_amount' => 100.00,
            'recovered_amount' => 100.00,
            'created_at' => CarbonImmutable::parse('2025-06-10 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2025-06-11 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2025-06-12 09:00:00'),
            'paid_at' => CarbonImmutable::parse('2025-06-15 09:00:00'),
        ]);

        $points = $this->analyticsGet($organization, '/api/v1/analytics/yearly?from=2025-01-01&to=2026-12-31')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $points);
        $this->assertSame('2025', $points[0]['year']);
        $this->assertSame(1, $points[0]['claims_created']);
        $this->assertSame('2026', $points[1]['year']);
        $this->assertSame(4, $points[1]['claims_created']);
    }

    public function test_export_overview_csv(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $response = $this->analyticsGet($organization, '/api/v1/analytics/export?type=overview&from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('section,metric,value', $body);
        $this->assertStringContainsString('summary,claims,4', $body);
        $this->assertStringContainsString('summary,recovery_rate_vs_requested,62.5', $body);
        $this->assertStringContainsString('2026-01,4,3,1,1,400.00,300.00,250.00,62.5', $body);
    }

    public function test_export_brokers_csv(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $response = $this->analyticsGet($organization, '/api/v1/analytics/export?type=brokers&from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('broker,claims,submitted,paid,rejected', $body);
        $this->assertStringContainsString('"Alpha Brokers",2,2,1,0,300.00,300.00,250.00', $body);
    }

    public function test_viewer_may_read_but_not_export(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/analytics/overview?from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/analytics/export?type=overview')
            ->assertForbidden();
    }

    public function test_free_plan_is_refused_with_402(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->seedJanuary($organization);

        $this->analyticsGet($organization, '/api/v1/analytics/overview')
            ->assertStatus(402);
    }

    public function test_other_organization_sees_nothing_and_cannot_use_its_header(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        [, $other] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($other, 'starter');

        // A member of the other organization sees only their own
        // (empty) analytics.
        $this->analyticsGet($other, '/api/v1/analytics/overview?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.counts.claims', 0);

        // And they cannot use the first organization's header.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/analytics/overview')
            ->assertNotFound();
    }

    public function test_invalid_range_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->analyticsGet($organization, '/api/v1/analytics/overview?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable();
    }
}
