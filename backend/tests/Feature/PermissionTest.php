<?php

namespace Tests\Feature;

use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_viewer_can_list_members_but_cannot_manage_them(): void
    {
        [$viewer, $organization, $viewerMember] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $target = User::factory()->create();
        $targetMember = $this->addMember($organization, $target, OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/members')
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$targetMember->id, ['role' => 'viewer'])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/members/'.$targetMember->id)
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'someone@example.com',
                'role' => 'viewer',
            ])->assertForbidden();
    }

    public function test_a_dispatcher_cannot_manage_members_or_invitations(): void
    {
        [$dispatcher, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $target = User::factory()->create();
        $targetMember = $this->addMember($organization, $target, OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$targetMember->id, ['status' => 'inactive'])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/invitations')
            ->assertForbidden();
    }

    public function test_an_admin_can_manage_members_and_invitations(): void
    {
        [$admin, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $target = User::factory()->create();
        $targetMember = $this->addMember($organization, $target, OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$targetMember->id, ['role' => 'dispatcher'])
            ->assertOk()
            ->assertJsonPath('data.role', 'dispatcher');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/invitations')
            ->assertOk();
    }

    public function test_a_non_owner_cannot_change_an_owner_membership(): void
    {
        $organization = $this->createOrganization();
        $owner = User::factory()->create();
        $ownerMember = $this->addMember($organization, $owner, OrganizationRole::Owner);

        [$admin] = $this->actingAsMemberOf(OrganizationRole::Admin, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$ownerMember->id, ['status' => 'inactive'])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/members/'.$ownerMember->id)
            ->assertForbidden();
    }

    public function test_an_admin_cannot_grant_the_owner_role(): void
    {
        [$admin, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $target = User::factory()->create();
        $targetMember = $this->addMember($organization, $target, OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$targetMember->id, ['role' => 'owner'])
            ->assertForbidden();
    }

    public function test_the_last_owner_cannot_be_demoted_or_deactivated(): void
    {
        [$owner, $organization, $ownerMember] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$ownerMember->id, ['role' => 'admin'])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$ownerMember->id, ['status' => 'inactive'])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/members/'.$ownerMember->id)
            ->assertUnprocessable();
    }

    public function test_an_owner_can_grant_ownership_then_step_down(): void
    {
        [$owner, $organization, $ownerMember] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $successor = User::factory()->create();
        $successorMember = $this->addMember($organization, $successor, OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$successorMember->id, ['role' => 'owner'])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$ownerMember->id, ['role' => 'admin'])
            ->assertOk();
    }

    public function test_an_admin_can_deactivate_and_remove_a_regular_member(): void
    {
        [$admin, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $target = User::factory()->create();
        $targetMember = $this->addMember($organization, $target, OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/members/'.$targetMember->id, ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/members/'.$targetMember->id)
            ->assertOk();

        $this->assertDatabaseMissing('organization_members', ['id' => $targetMember->id]);
    }
}
