<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_shipment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $carrier = $this->createCarrier($organization, ['name' => 'Acme Lines LLC']);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-001',
                'external_load_reference' => 'BRK-1',
                'broker_or_customer_name' => 'Heartland Foods',
                'carrier_id' => $carrier->getKey(),
                'driver_name' => 'Joe Parker',
                'pickup_location' => 'Dallas, TX',
                'delivery_location' => 'Kansas City, MO',
                'pickup_appointment' => '2026-11-20 09:00',
                'base_rate' => 1450.00,
                'currency' => 'USD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.internal_reference', 'CT-001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_label', 'Draft')
            ->assertJsonPath('data.carrier.name', 'Acme Lines LLC');

        $this->assertDatabaseHas('shipments', [
            'organization_id' => $organization->getKey(),
            'internal_reference' => 'CT-001',
            'created_by' => $user->id,
        ]);
    }

    public function test_creating_a_shipment_validates_required_fields(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', ['notes' => 'No reference, no broker.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['internal_reference', 'broker_or_customer_name']);
    }

    public function test_internal_reference_must_be_unique_within_the_organization_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-DUP']);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-DUP',
                'broker_or_customer_name' => 'Someone',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['internal_reference']);

        $other = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($other, $user, OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($other))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-DUP',
                'broker_or_customer_name' => 'Someone',
            ])
            ->assertCreated();
    }

    public function test_a_viewer_cannot_create_a_shipment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-001',
                'broker_or_customer_name' => 'Someone',
            ])
            ->assertForbidden();
    }

    public function test_list_returns_only_the_organizations_shipments_with_filters(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $carrier = $this->createCarrier($organization);
        $this->createShipment($organization, [
            'internal_reference' => 'CT-SEARCH-ME',
            'status' => ShipmentStatus::Planned,
            'carrier_id' => $carrier->getKey(),
            'pickup_appointment' => '2026-11-20 09:00',
        ]);
        $this->createShipment($organization, [
            'internal_reference' => 'CT-OTHER',
            'status' => ShipmentStatus::Draft,
            'pickup_appointment' => '2026-12-01 09:00',
        ]);

        $other = $this->createOrganization(['name' => 'Other Org']);
        $this->createShipment($other, ['internal_reference' => 'CT-FOREIGN']);

        $list = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?search=SEARCH-ME')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.internal_reference', 'CT-SEARCH-ME');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?status=draft')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?carrier_id='.$carrier->getKey())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments?date_from=2026-11-19&date_to=2026-11-21')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_user_cannot_read_another_organizations_shipment_by_direct_id(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $shipmentB = $this->createShipment($orgB);

        $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/shipments/'.$shipmentB->getKey())
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($orgA))
            ->putJson('/api/v1/shipments/'.$shipmentB->getKey(), ['notes' => 'Nope.'])
            ->assertNotFound();
    }

    public function test_status_moves_forward_one_step_at_a_time(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);

        // Skipping a step is rejected.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/shipments/'.$shipment->getKey(), ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A Draft shipment cannot move to Delivered.');

        // One step forward works.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/shipments/'.$shipment->getKey(), ['status' => 'planned'])
            ->assertOk()
            ->assertJsonPath('data.status', 'planned');

        $this->assertSame(
            ShipmentStatus::Planned,
            $shipment->fresh()->status
        );
    }

    public function test_an_archived_shipment_is_read_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Archived]);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/shipments/'.$shipment->getKey(), ['notes' => 'Too late.'])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/archive')
            ->assertForbidden();
    }

    public function test_archiving_works_and_is_permission_gated(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Completed]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        [$dispatcher] = $this->actingAsMemberOf(OrganizationRole::Dispatcher, $organization);
        $second = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$second->getKey().'/archive')
            ->assertForbidden();
    }

    public function test_export_returns_csv_and_honors_filters(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::BillingStaff);
        $this->createShipment($organization, [
            'internal_reference' => 'CT-EXP-1',
            'status' => ShipmentStatus::Draft,
        ]);
        $this->createShipment($organization, [
            'internal_reference' => 'CT-EXP-2',
            'status' => ShipmentStatus::Planned,
        ]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/shipments/export?status=draft');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('internal_reference', $response->streamedContent());
        $this->assertStringContainsString('CT-EXP-1', $response->streamedContent());
        $this->assertStringNotContainsString('CT-EXP-2', $response->streamedContent());
    }

    public function test_a_viewer_cannot_export_shipments(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/shipments/export')
            ->assertForbidden();
    }

    public function test_dashboard_counts_include_shipments_events_and_evidence(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->createShipment($organization);
        $this->createEvent($shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.shipments_total', 2)
            ->assertJsonPath('data.counts.shipments_with_events', 1)
            ->assertJsonPath('data.counts.events_open', 1)
            ->assertJsonPath('data.counts.events_total', 1)
            ->assertJsonPath('data.counts.evidence_total', 0);
    }

    public function test_carriers_can_be_listed_created_and_updated(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $this->createCarrier($organization, ['name' => 'Listed Carrier']);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/carriers?search=Listed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/carriers', ['name' => 'New Carrier', 'dot_number' => 'DOT 999'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Carrier')
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/carriers/'.$created, ['contact_phone' => '(555) 010-0000'])
            ->assertOk()
            ->assertJsonPath('data.contact_phone', '(555) 010-0000');
    }

    public function test_a_viewer_cannot_create_a_carrier_and_cross_org_update_is_denied(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/carriers', ['name' => 'Nope'])
            ->assertForbidden();

        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $carrierB = $this->createCarrier($orgB);

        [$owner] = $this->actingAsMemberOf(OrganizationRole::Owner, $orgA);

        $this->withHeaders($this->orgHeaders($orgA))
            ->putJson('/api/v1/carriers/'.$carrierB->getKey(), ['name' => 'Nope'])
            ->assertNotFound();
    }
}
