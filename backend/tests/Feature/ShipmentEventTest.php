<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_can_be_created_and_duration_is_computed(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $category = $this->systemCategory('detention');

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category->getKey(),
                'event_date' => '2026-11-12',
                'start_time' => '14:00',
                'end_time' => '18:15',
                'location' => 'Kansas City, MO',
                'description' => 'Held at the receiver.',
                'estimated_amount' => 250.00,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.duration_minutes', 255)
            ->assertJsonPath('data.status', 'potential')
            ->assertJsonPath('data.category.slug', 'detention')
            ->assertJsonPath('data.start_time', '14:00')
            ->assertJsonPath('data.end_time', '18:15');

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->getKey(),
            'duration_minutes' => 255,
            'reported_by' => $user->id,
        ]);
    }

    public function test_an_explicit_duration_is_kept_when_no_times_are_given(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $this->systemCategory('layover')->getKey(),
                'event_date' => '2026-11-12',
                'duration_minutes' => 480,
            ])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 480);
    }

    public function test_an_end_time_before_the_start_time_is_rejected(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $this->systemCategory()->getKey(),
                'event_date' => '2026-11-12',
                'start_time' => '18:00',
                'end_time' => '14:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    }

    public function test_a_single_time_is_allowed_and_duration_stays_null(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $this->systemCategory('tonu')->getKey(),
                'event_date' => '2026-11-12',
                'start_time' => '09:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', null);
    }

    public function test_events_are_listed_for_their_shipment_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);
        $other = $this->createShipment($organization);
        $this->createEvent($shipment);
        $this->createEvent($other);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/shipments/'.$shipment->getKey().'/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_user_cannot_read_another_organizations_event_by_direct_id(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $shipmentB = $this->createShipment($orgB);
        $eventB = $this->createEvent($shipmentB);

        $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/events/'.$eventB->getKey())
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($orgA))
            ->deleteJson('/api/v1/events/'.$eventB->getKey())
            ->assertNotFound();
    }

    public function test_updating_an_event_recomputes_the_duration(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $event = $this->createEvent($shipment, [
            'start_time' => '14:00',
            'end_time' => '15:00',
            'duration_minutes' => 60,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/events/'.$event->getKey(), [
                'end_time' => '17:30',
                'status' => 'under_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 210)
            ->assertJsonPath('data.status', 'under_review');
    }

    public function test_deleting_an_event_is_permission_gated(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $event = $this->createEvent($shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/events/'.$event->getKey())
            ->assertForbidden();

        [$owner] = $this->actingAsMemberOf(OrganizationRole::Owner, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/events/'.$event->getKey())
            ->assertOk()
            ->assertJsonPath('message', 'Event deleted.');

        $this->assertDatabaseMissing('shipment_events', ['id' => $event->getKey()]);
    }

    public function test_events_cannot_be_added_to_or_changed_on_an_archived_shipment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Archived]);
        $event = $this->createEvent($shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $this->systemCategory()->getKey(),
                'event_date' => '2026-11-12',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Events cannot be added to an archived shipment.');

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/events/'.$event->getKey(), ['notes' => 'Too late.'])
            ->assertForbidden();
    }

    public function test_a_category_from_another_organization_is_rejected(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $foreign = EventCategory::create([
            'organization_id' => $orgB->getKey(),
            'slug' => 'their-category',
            'label' => 'Their Category',
        ]);
        $shipment = $this->createShipment($orgA);

        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $foreign->getKey(),
                'event_date' => '2026-11-12',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_category_id']);
    }

    public function test_event_categories_can_be_listed_and_custom_ones_added(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->systemCategory('detention');
        $this->systemCategory('lumper');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/event-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/event-categories', ['label' => 'Weigh Station Fee'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'weigh-station-fee')
            ->assertJsonPath('data.is_system', false)
            ->json('data.id');

        // A second category with the same name collides on slug.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/event-categories', ['label' => 'Weigh Station Fee'])
            ->assertUnprocessable();

        // The custom category can be used for an event.
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $created,
                'event_date' => '2026-11-12',
            ])
            ->assertCreated();
    }

    public function test_a_viewer_cannot_create_an_event(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $this->systemCategory()->getKey(),
                'event_date' => '2026-11-12',
            ])
            ->assertForbidden();
    }
}
