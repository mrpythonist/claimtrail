<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_filters_cover_dates_amounts_events_and_broker(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $carrier = $this->createCarrier($organization, ['name' => 'Acme Lines LLC']);

        $withDetention = $this->createShipment($organization, [
            'internal_reference' => 'CT-F-1',
            'broker_or_customer_name' => 'Heartland Foods',
            'carrier_id' => $carrier->getKey(),
            'base_rate' => 1200.00,
            'delivery_appointment' => '2026-11-20 09:00',
        ]);
        $this->createEvent($withDetention); // system detention category

        $plain = $this->createShipment($organization, [
            'internal_reference' => 'CT-F-2',
            'broker_or_customer_name' => 'Ozark Supply',
            'base_rate' => 300.00,
            'delivery_appointment' => '2026-12-05 09:00',
        ]);

        // Delivery date range.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?delivery_date_from=2026-11-19&delivery_date_to=2026-11-21')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_reference', 'CT-F-1');

        // Amount range.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?amount_min=1000&amount_max=1500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_reference', 'CT-F-1');

        // Event type.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?event_type=detention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_reference', 'CT-F-1');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?event_type=layover')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Broker substring.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?broker=ozark')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_reference', 'CT-F-2');

        // Search now matches the carrier name too.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?search=Acme')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_reference', 'CT-F-1');
    }

    public function test_claim_filters_cover_amounts_deadlines_events_broker_and_carrier(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $carrier = $this->createCarrier($organization, ['name' => 'Acme Lines LLC']);

        $shipmentA = $this->createShipment($organization, [
            'internal_reference' => 'CT-C-1',
            'broker_or_customer_name' => 'Heartland Foods',
            'carrier_id' => $carrier->getKey(),
        ]);
        $event = $this->createEvent($shipmentA); // detention
        $overdue = $this->createClaim($organization, $shipmentA, [
            'shipment_event_id' => $event->getKey(),
            'requested_amount' => 900.00,
            'status' => ClaimStatus::UnderReview,
            'submission_deadline_at' => now()->subDays(2),
        ]);

        $shipmentB = $this->createShipment($organization, [
            'internal_reference' => 'CT-C-2',
            'broker_or_customer_name' => 'Ozark Supply',
        ]);
        $upcoming = $this->createClaim($organization, $shipmentB, [
            'requested_amount' => 150.00,
            'status' => ClaimStatus::Potential,
            'submission_deadline_at' => now()->addDays(3),
        ]);
        $noDeadline = $this->createClaim($organization, $shipmentB, [
            'requested_amount' => 75.00,
            'status' => ClaimStatus::Potential,
            'submission_deadline_at' => null,
        ]);

        // Amount range.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?amount_min=800&amount_max=1000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->getKey());

        // Deadline status: overdue, upcoming, none.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?deadline_status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?deadline_status=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?deadline_status=none')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $noDeadline->getKey());

        // Deadline date range.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?deadline_from='.now()->addDays(1)->toDateString().'&deadline_to='.now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->getKey());

        // Event type through the claim event.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?event_type=detention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->getKey());

        // Broker and carrier through the shipment.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?broker=ozark')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?carrier_id='.$carrier->getKey())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->getKey());

        // Search now matches the carrier name too.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?search=Acme')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->getKey());
    }

    public function test_a_submitted_claim_with_a_past_deadline_is_not_overdue(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Submitted,
            'submission_deadline_at' => now()->subDays(2),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?deadline_status=overdue')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
