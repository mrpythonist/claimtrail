<?php

namespace Tests\Feature;

use App\Models\SavedView;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_save_list_and_apply_saved_views(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Open detention claims',
                'resource_type' => 'claim',
                'filters' => ['status' => 'under_review', 'claim_category' => 'detention'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Open detention claims')
            ->assertJsonPath('data.resource_type', 'claim')
            ->assertJsonPath('data.filters.status', 'under_review')
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/saved-views?resource_type=claim')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created);

        // The shipment list is a different resource type.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/saved-views?resource_type=shipment')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_saved_view_filters_are_validated_and_cleaned(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);

        // Unknown keys are dropped, empty values are dropped.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Cleaned',
                'resource_type' => 'shipment',
                'filters' => [
                    'status' => 'planned',
                    'bogus_key' => 'x',
                    'broker' => '',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.filters', ['status' => 'planned']);

        // A nested filter value is rejected, not stored.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Nested',
                'resource_type' => 'shipment',
                'filters' => ['status' => ['planned']],
            ])
            ->assertUnprocessable();

        // An unknown resource type is rejected.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Bad type',
                'resource_type' => 'invoice',
                'filters' => [],
            ])
            ->assertUnprocessable();
    }

    public function test_saved_views_are_personal_within_an_organization(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $viewId = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Mine',
                'resource_type' => 'shipment',
                'filters' => ['status' => 'draft'],
            ])
            ->assertCreated()
            ->json('data.id');

        // Another member of the same organization sees none of them.
        [$admin] = $this->actingAsMemberOf(OrganizationRole::Admin, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/saved-views')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And cannot update or delete someone else's view.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/saved-views/'.$viewId, ['name' => 'Theirs'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/saved-views/'.$viewId)
            ->assertNotFound();

        $this->assertDatabaseHas('saved_views', ['id' => $viewId, 'name' => 'Mine']);
    }

    public function test_a_saved_view_can_be_renamed_and_deleted_by_its_owner(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);

        $viewId = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Old name',
                'resource_type' => 'claim',
                'filters' => ['status' => 'potential'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/saved-views/'.$viewId, [
                'name' => 'New name',
                'filters' => ['status' => 'submitted'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.filters.status', 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/saved-views/'.$viewId)
            ->assertOk();

        $this->assertSame(0, SavedView::count());
    }

    public function test_saved_views_are_tenant_scoped(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($orgB, $user, OrganizationRole::Owner);

        $viewId = $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/saved-views', [
                'name' => 'Org A view',
                'resource_type' => 'shipment',
                'filters' => [],
            ])
            ->assertCreated()
            ->json('data.id');

        // The same user, switched to org B, sees no views there.
        $this->withHeaders($this->orgHeaders($orgB))
            ->getJson('/api/v1/saved-views')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->orgHeaders($orgB))
            ->deleteJson('/api/v1/saved-views/'.$viewId)
            ->assertNotFound();
    }
}
