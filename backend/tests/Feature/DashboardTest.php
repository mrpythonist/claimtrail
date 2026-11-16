<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_cover_opportunities_evidence_and_decisions(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->createEvent($shipment);
        $plain = $this->createShipment($organization);

        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::EvidenceRequired]);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::ReadyToSubmit]);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Submitted]);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Accepted]);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Rejected]);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Paid]);

        $data = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $counts = $data['counts'];

        $this->assertSame(2, $counts['shipments_total']);
        $this->assertSame(1, $counts['shipments_with_events']);
        // Only the shipment with a potential event is an opportunity.
        $this->assertSame(1, $counts['shipments_with_opportunities']);
        $this->assertSame(6, $counts['claims_total']);
        $this->assertSame(2, $counts['claims_open']);
        // Open opportunities are potential, evidence required, and
        // under review; ready to submit is not one.
        $this->assertSame(1, $counts['claims_open_opportunities']);
        $this->assertSame(1, $counts['claims_awaiting_evidence']);
        $this->assertSame(1, $counts['claims_ready']);
        $this->assertSame(1, $counts['claims_submitted']);
        $this->assertSame(1, $counts['claims_approved']);
        $this->assertSame(1, $counts['claims_rejected']);
        $this->assertSame(1, $counts['claims_paid']);

        $byStatus = collect($data['claims_by_status'])->keyBy('status');
        $this->assertSame(1, $byStatus['evidence_required']['count']);
        $this->assertSame(0, $byStatus['closed']['count']);
        $this->assertCount(10, $byStatus);
    }

    public function test_dashboard_financials_are_labeled_and_per_currency(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Potential,
            'requested_amount' => 250.00,
            'currency' => 'USD',
        ]);
        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Potential,
            'requested_amount' => 400.00,
            'currency' => 'EUR',
        ]);
        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Paid,
            'requested_amount' => 500.00,
            'accepted_amount' => 500.00,
            'recovered_amount' => 500.00,
            'currency' => 'USD',
        ]);

        $financials = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.financials');

        $this->assertSame([
            ['currency' => 'EUR', 'amount' => '400.00'],
            ['currency' => 'USD', 'amount' => '250.00'],
        ], $financials['potential']);
        $this->assertSame([['currency' => 'USD', 'amount' => '500.00']], $financials['submitted']);
        $this->assertSame([['currency' => 'USD', 'amount' => '500.00']], $financials['accepted']);
        $this->assertSame([['currency' => 'USD', 'amount' => '500.00']], $financials['recovered']);
    }

    public function test_dashboard_date_filter_applies_to_shipments_and_claims(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $oldClaim = $this->createClaim($organization, $shipment, ['requested_amount' => 250.00]);
        $oldClaim->created_at = now()->subMonths(3);
        $oldClaim->save();

        $this->createClaim($organization, $shipment, ['requested_amount' => 100.00]);

        $data = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard?date_from='.now()->subMonth()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['counts']['claims_total']);
        $this->assertSame([['currency' => 'USD', 'amount' => '100.00']], $data['financials']['potential']);
        $this->assertSame(now()->subMonth()->toDateString(), $data['filters']['date_from']);
    }

    public function test_dashboard_status_filter_adds_a_filtered_section(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Submitted,
            'requested_amount' => 300.00,
        ]);
        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Potential,
            'requested_amount' => 250.00,
        ]);

        $data = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard?status=submitted')
            ->assertOk()
            ->json('data');

        // The named counts are unchanged by the filter.
        $this->assertSame(2, $data['counts']['claims_total']);
        $this->assertSame(1, $data['counts']['claims_submitted']);

        $this->assertSame('submitted', $data['claims_filtered']['status']);
        $this->assertSame(1, $data['claims_filtered']['count']);
        $this->assertSame(
            [['currency' => 'USD', 'amount' => '300.00']],
            $data['claims_filtered']['requested_amounts']
        );

        // Without a status filter there is no filtered section.
        $unfiltered = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('claims_filtered', $unfiltered);
    }

    public function test_dashboard_lists_upcoming_deadlines(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['claim_reference' => 'CLM-2026-0007']);

        $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->addDays(5),
            'timezone' => 'UTC',
            'status' => 'due_soon',
        ]);

        $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->addDays(60),
            'timezone' => 'UTC',
            'status' => 'upcoming',
        ]);

        $data = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['counts']['deadlines_next_14_days']);
        $this->assertCount(1, $data['upcoming_deadlines']);
        $this->assertSame('CLM-2026-0007', $data['upcoming_deadlines'][0]['claim_reference']);
        $this->assertSame('due_soon', $data['upcoming_deadlines'][0]['status']);
    }

    public function test_dashboard_rejects_an_invalid_status_filter(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard?status=not-a-status')
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard?date_from=2026-11-10&date_to=2026-11-01')
            ->assertUnprocessable();
    }
}
