<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A claim in a given status with known money.
     */
    protected function claimInStatus($organization, $shipment, ClaimStatus $status, array $attributes = [])
    {
        return $this->createClaim($organization, $shipment, array_merge([
            'status' => $status,
        ], $attributes));
    }

    public function test_summary_counts_claims_by_status_and_event_type(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, ['claim_category' => 'detention']);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, ['claim_category' => 'lumper']);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Submitted, ['claim_category' => 'detention']);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertOk();

        $byStatus = collect($response->json('data.counts_by_status'))->keyBy('status');
        $this->assertSame(2, $byStatus['potential']['count']);
        $this->assertSame(1, $byStatus['submitted']['count']);
        $this->assertSame(0, $byStatus['paid']['count']);
        $this->assertCount(10, $byStatus);

        $byType = collect($response->json('data.counts_by_event_type'))->keyBy('category');
        $this->assertSame(2, $byType['detention']['count']);
        $this->assertSame(1, $byType['lumper']['count']);
        $this->assertSame(0, $byType['tonu']['count']);
        $this->assertCount(7, $byType);
    }

    public function test_summary_money_follows_the_financial_definitions(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        // Potential: requested 250, still being prepared.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, ['requested_amount' => 250.00]);
        // Submitted: requested 100.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Submitted, [
            'requested_amount' => 100.00,
            'submitted_at' => now(),
        ]);
        // Accepted: requested 400, accepted 300.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Accepted, [
            'requested_amount' => 400.00,
            'accepted_amount' => 300.00,
            'submitted_at' => now(),
        ]);
        // Paid: requested 500, accepted 500, recovered 500.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Paid, [
            'requested_amount' => 500.00,
            'accepted_amount' => 500.00,
            'recovered_amount' => 500.00,
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);
        // Rejected: requested 75.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Rejected, [
            'requested_amount' => 75.00,
            'submitted_at' => now(),
        ]);
        // Closed after submission: requested 50, counts as submitted.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Closed, [
            'requested_amount' => 50.00,
            'submitted_at' => now()->subDay(),
        ]);
        // Closed without submission: requested 999, counts nowhere.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Closed, ['requested_amount' => 999.00]);

        $money = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->json('data.money');

        $this->assertSame([['currency' => 'USD', 'amount' => '250.00']], $money['potential']);
        // Submitted: 100 + 400 + 500 + 75 + 50 (closed after submission).
        $this->assertSame([['currency' => 'USD', 'amount' => '1125.00']], $money['submitted']);
        // Accepted: 300 + 500.
        $this->assertSame([['currency' => 'USD', 'amount' => '800.00']], $money['accepted']);
        $this->assertSame([['currency' => 'USD', 'amount' => '500.00']], $money['recovered']);
        $this->assertSame([['currency' => 'USD', 'amount' => '75.00']], $money['rejected']);
        // Outstanding: accepted 800 - recovered 500.
        $this->assertSame([['currency' => 'USD', 'amount' => '300.00']], $money['outstanding']);
    }

    public function test_currencies_are_never_mixed(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, [
            'requested_amount' => 250.00, 'currency' => 'USD',
        ]);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, [
            'requested_amount' => 100.00, 'currency' => 'USD',
        ]);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, [
            'requested_amount' => 400.00, 'currency' => 'EUR',
        ]);

        $money = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->json('data.money');

        $this->assertSame([
            ['currency' => 'EUR', 'amount' => '400.00'],
            ['currency' => 'USD', 'amount' => '350.00'],
        ], $money['potential']);
    }

    public function test_summary_honors_the_date_range(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $old = $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, ['requested_amount' => 250.00]);
        $old->created_at = now()->subMonths(3);
        $old->save();

        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, ['requested_amount' => 100.00]);

        $money = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary?date_from='.now()->subMonth()->toDateString())
            ->assertOk()
            ->json('data.money');

        // Only the recent claim is inside the range.
        $this->assertSame([['currency' => 'USD', 'amount' => '100.00']], $money['potential']);
    }

    public function test_recovery_history_groups_paid_claims_by_month_and_currency(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->claimInStatus($organization, $shipment, ClaimStatus::Paid, [
            'requested_amount' => 500.00,
            'accepted_amount' => 500.00,
            'recovered_amount' => 500.00,
            'paid_at' => now()->startOfMonth()->addDay(),
        ]);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Paid, [
            'requested_amount' => 200.00,
            'accepted_amount' => 200.00,
            'recovered_amount' => 200.00,
            'currency' => 'EUR',
            'paid_at' => now()->subMonth()->startOfMonth()->addDays(5),
        ]);
        // An accepted-but-unpaid claim contributes nothing.
        $this->claimInStatus($organization, $shipment, ClaimStatus::Accepted, [
            'requested_amount' => 999.00,
            'accepted_amount' => 999.00,
        ]);

        $history = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/recovery-history')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $history);
        $this->assertSame(now()->subMonth()->format('Y-m'), $history[0]['month']);
        $this->assertSame([['currency' => 'EUR', 'amount' => '200.00']], $history[0]['amounts']);
        $this->assertSame(now()->format('Y-m'), $history[1]['month']);
        $this->assertSame([['currency' => 'USD', 'amount' => '500.00']], $history[1]['amounts']);
    }

    public function test_report_export_contains_summary_and_claim_rows(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->claimInStatus($organization, $shipment, ClaimStatus::Potential, [
            'claim_reference' => 'CLM-2026-0099',
            'requested_amount' => 250.00,
        ]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/reports/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('section,metric,currency,amount', $content);
        $this->assertStringContainsString('summary,potential,USD,250.00', $content);
        $this->assertStringContainsString('claim_reference,shipment_reference', $content);
        $this->assertStringContainsString('CLM-2026-0099', $content);
    }

    public function test_reports_are_permission_gated(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        // Viewers can read reports.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reports/summary')
            ->assertOk();

        // Viewers cannot export them.
        $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/reports/export')
            ->assertForbidden();
    }

    public function test_reports_are_org_scoped(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organizationA);
        $this->claimInStatus($organizationA, $shipment, ClaimStatus::Potential, ['requested_amount' => 250.00]);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $money = $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->json('data.money');

        $this->assertSame([], $money['potential']);
    }
}
