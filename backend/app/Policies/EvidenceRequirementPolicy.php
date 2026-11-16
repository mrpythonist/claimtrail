<?php

namespace App\Policies;

use App\Models\EvidenceRequirement;
use App\Models\User;
use App\Permission;

class EvidenceRequirementPolicy
{
    /**
     * Any member with the claim view permission can read the
     * organization's evidence requirements.
     */
    public function view(User $user, EvidenceRequirement $requirement): bool
    {
        return $user->hasPermissionIn($requirement->organization_id, Permission::ViewClaims);
    }

    /**
     * Changing requirements needs the manage permission (owner,
     * admin, operations manager).
     */
    public function update(User $user, EvidenceRequirement $requirement): bool
    {
        return $user->hasPermissionIn($requirement->organization_id, Permission::ManageEvidenceRequirements);
    }

    /**
     * Deleting a requirement needs the manage permission.
     */
    public function delete(User $user, EvidenceRequirement $requirement): bool
    {
        return $user->hasPermissionIn($requirement->organization_id, Permission::ManageEvidenceRequirements);
    }
}
