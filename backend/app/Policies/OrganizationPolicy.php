<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Permission;

class OrganizationPolicy
{
    /**
     * Any authenticated user can list the organizations they belong to.
     * Membership filtering happens in the controller, not here.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user can view an organization they actively belong to.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->isActiveMemberOf($organization);
    }

    /**
     * Any authenticated user can create an organization. They become
     * its owner.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only members with the manage-organization permission can change
     * organization settings.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermissionIn($organization, Permission::ManageOrganization);
    }

    /**
     * Only the owner can delete an organization. Deletion is not
     * exposed in Milestone 1; this gate exists so the rule is defined
     * in one place when the endpoint lands.
     */
    public function delete(User $user, Organization $organization): bool
    {
        $membership = $user->membershipIn($organization);

        return $membership !== null
            && $membership->isActive()
            && $membership->role->value === 'owner';
    }

    /**
     * Only members with the manage-members permission can change
     * memberships (roles, activation, removal).
     */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasPermissionIn($organization, Permission::ManageMembers);
    }

    /**
     * Only members with the manage-invitations permission can invite
     * or revoke invitations.
     */
    public function manageInvitations(User $user, Organization $organization): bool
    {
        return $user->hasPermissionIn($organization, Permission::ManageInvitations);
    }
}
