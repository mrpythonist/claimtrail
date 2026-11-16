<?php

namespace App\Policies;

use App\Models\ClaimDeadline;
use App\Models\User;
use App\Permission;

class ClaimDeadlinePolicy
{
    /**
     * Any member with the deadline view permission can read a
     * deadline in their organization.
     */
    public function view(User $user, ClaimDeadline $deadline): bool
    {
        return $user->hasPermissionIn($deadline->organization_id, Permission::ViewDeadlines);
    }

    /**
     * Managing a deadline needs the manage permission. Superseded
     * rows are history and cannot be edited.
     */
    public function update(User $user, ClaimDeadline $deadline): bool
    {
        return $deadline->isActive()
            && $user->hasPermissionIn($deadline->organization_id, Permission::ManageDeadlines);
    }
}
