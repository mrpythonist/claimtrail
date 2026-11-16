<?php

namespace App\Policies;

use App\Models\EvidenceFile;
use App\Models\User;
use App\Permission;

class EvidenceFilePolicy
{
    /**
     * Any member with the evidence view permission can read evidence
     * metadata and download the file in their organization.
     */
    public function view(User $user, EvidenceFile $evidence): bool
    {
        return $user->hasPermissionIn($evidence->organization_id, Permission::ViewEvidence);
    }

    /**
     * Deleting needs the delete permission. The file itself is removed
     * from storage by the controller.
     */
    public function delete(User $user, EvidenceFile $evidence): bool
    {
        return $user->hasPermissionIn($evidence->organization_id, Permission::DeleteEvidence);
    }

    /**
     * Changing the verification status needs the verify permission.
     */
    public function verify(User $user, EvidenceFile $evidence): bool
    {
        return $user->hasPermissionIn($evidence->organization_id, Permission::VerifyEvidence);
    }
}
