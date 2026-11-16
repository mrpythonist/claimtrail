<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;
use App\Permission;

class GeneratedDocumentPolicy
{
    /**
     * Any member with the claim view permission can download a
     * generated package in their organization.
     */
    public function view(User $user, GeneratedDocument $document): bool
    {
        return $user->hasPermissionIn($document->organization_id, Permission::ViewClaims);
    }
}
