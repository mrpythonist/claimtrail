<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_an_organization_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Prairie Freight LLC',
            'timezone' => 'America/Chicago',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Prairie Freight LLC');

        $organization = Organization::first();
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_creating_an_organization_requires_a_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_creating_an_organization_requires_authentication(): void
    {
        $this->postJson('/api/v1/organizations', ['name' => 'Prairie Freight LLC'])
            ->assertUnauthorized();
    }

    public function test_a_user_lists_only_their_own_organizations(): void
    {
        [$user, $own] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $other = $this->createOrganization(['name' => 'Other Carriers Inc.']);

        $response = $this->getJson('/api/v1/organizations');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains((string) $own->getKey(), $ids);
        $this->assertNotContains((string) $other->getKey(), $ids);
    }

    public function test_a_member_can_view_their_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->getJson('/api/v1/organizations/'.$organization->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', (string) $organization->getKey());
    }

    public function test_a_non_member_cannot_view_an_organization(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $organization = $this->createOrganization();

        $this->getJson('/api/v1/organizations/'.$organization->getKey())
            ->assertForbidden();
    }

    public function test_an_owner_can_update_organization_settings(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'name' => 'Renamed Trucking Co.',
            'timezone' => 'America/New_York',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed Trucking Co.');

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Renamed Trucking Co.',
            'timezone' => 'America/New_York',
        ]);
    }

    public function test_a_viewer_cannot_update_organization_settings(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'name' => 'Renamed Trucking Co.',
        ])->assertForbidden();
    }

    public function test_a_member_can_switch_to_their_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $this->postJson('/api/v1/organizations/'.$organization->getKey().'/switch')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $organization->getKey());
    }

    public function test_a_non_member_cannot_switch_to_an_organization(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $organization = $this->createOrganization();

        $this->postJson('/api/v1/organizations/'.$organization->getKey().'/switch')
            ->assertForbidden();
    }
}
