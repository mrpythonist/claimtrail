<?php

namespace Tests;

use App\MembershipStatus;
use App\Models\Carrier;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create an organization with a random slug.
     */
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Test Trucking Co.',
            'slug' => 'test-trucking-'.Str::lower(Str::random(8)),
            'timezone' => 'UTC',
        ], $attributes));
    }

    /**
     * Add a user to an organization with the given role.
     */
    protected function addMember(
        Organization $organization,
        User $user,
        OrganizationRole $role,
        MembershipStatus $status = MembershipStatus::Active,
    ): OrganizationMember {
        return $organization->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    /**
     * Create a user, an organization, and a membership, then authenticate
     * as that user with Sanctum.
     *
     * @return array{User, Organization, OrganizationMember}
     */
    protected function actingAsMemberOf(
        OrganizationRole $role,
        ?Organization $organization = null,
    ): array {
        $organization ??= $this->createOrganization();
        $user = User::factory()->create();
        $member = $this->addMember($organization, $user, $role);

        Sanctum::actingAs($user);

        return [$user, $organization, $member];
    }

    /**
     * Headers that select an organization for tenant-scoped requests.
     *
     * @return array<string, string>
     */
    protected function orgHeaders(Organization $organization): array
    {
        return ['X-Organization-Id' => (string) $organization->getKey()];
    }

    /**
     * Create a carrier in an organization.
     */
    protected function createCarrier(Organization $organization, array $attributes = []): Carrier
    {
        return $organization->carriers()->create(array_merge([
            'name' => 'Test Carrier '.Str::random(6),
        ], $attributes));
    }

    /**
     * Create a shipment in an organization.
     */
    protected function createShipment(Organization $organization, array $attributes = []): Shipment
    {
        return $organization->shipments()->create(array_merge([
            'internal_reference' => 'CT-TEST-'.Str::upper(Str::random(8)),
            'broker_or_customer_name' => 'Test Broker Co.',
            'status' => ShipmentStatus::Draft,
        ], $attributes));
    }

    /**
     * Find or create a system event category by slug.
     */
    protected function systemCategory(string $slug = 'detention'): EventCategory
    {
        return EventCategory::firstOrCreate(
            ['slug' => $slug, 'organization_id' => null],
            ['label' => Str::headline($slug), 'description' => null]
        );
    }

    /**
     * Create an event on a shipment.
     */
    protected function createEvent(Shipment $shipment, array $attributes = []): ShipmentEvent
    {
        return $shipment->events()->create(array_merge([
            'organization_id' => $shipment->organization_id,
            'event_category_id' => $this->systemCategory()->getKey(),
            'event_date' => now()->toDateString(),
        ], $attributes));
    }
}
