<?php

namespace Tests;

use App\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\OrganizationRole;
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
}
