<?php

namespace Tests\Feature;

use App\MembershipStatus;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_routes_require_an_organization_header(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->getJson('/api/v1/dashboard')
            ->assertBadRequest()
            ->assertJsonPath('message', 'An organization must be selected.');
    }

    public function test_an_unknown_organization_id_returns_404(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders(['X-Organization-Id' => (string) Str::uuid()])
            ->getJson('/api/v1/dashboard')
            ->assertNotFound();
    }

    public function test_a_user_cannot_access_an_organization_they_do_not_belong_to(): void
    {
        [$user, $own] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $other = $this->createOrganization(['name' => 'Other Carriers Inc.']);

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/dashboard')
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/members')
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($other))
            ->postJson('/api/v1/invitations', [
                'email' => 'someone@example.com',
                'role' => 'viewer',
            ])->assertNotFound();
    }

    public function test_a_user_cannot_read_another_organizations_member_by_direct_id(): void
    {
        [$userA, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Carriers Inc.']);
        $userB = User::factory()->create();
        $memberB = $this->addMember($orgB, $userB, OrganizationRole::Viewer);

        // Direct member id from org B, with org A in the header.
        $this->withHeaders($this->orgHeaders($orgA))
            ->putJson('/api/v1/members/'.$memberB->id, ['status' => 'inactive'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($orgA))
            ->deleteJson('/api/v1/members/'.$memberB->id)
            ->assertNotFound();
    }

    public function test_an_inactive_member_cannot_access_the_organization(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->create();
        $this->addMember($organization, $user, OrganizationRole::Admin, MembershipStatus::Inactive);
        Sanctum::actingAs($user);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertNotFound();
    }

    public function test_dashboard_returns_real_counts_for_the_resolved_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $second = User::factory()->create();
        $this->addMember($organization, $second, OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.members_total', 2)
            ->assertJsonPath('data.counts.members_active', 2)
            ->assertJsonPath('data.counts.invitations_pending', 0)
            ->assertJsonPath('data.current_user_role', 'owner');
    }
}
