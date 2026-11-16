<?php

namespace App\Policies;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\User;
use App\Permission;

class ClaimPolicy
{
    /**
     * Any member with the claim view permission can read a claim in
     * their organization.
     */
    public function view(User $user, Claim $claim): bool
    {
        return $user->hasPermissionIn($claim->organization_id, Permission::ViewClaims);
    }

    /**
     * Editing needs the edit permission. Closed and paid claims are
     * immutable; the controller further restricts which fields may
     * change once a claim is submitted.
     */
    public function update(User $user, Claim $claim): bool
    {
        return ! $claim->isClosed()
            && ! $claim->isPaid()
            && $user->hasPermissionIn($claim->organization_id, Permission::EditClaims);
    }

    /**
     * Whether the user may move the claim to the given status.
     *
     * The permission depends on the target: submitting needs the
     * submit permission, review outcomes (ready, accepted, partially
     * accepted, rejected) need the review permission, and the
     * remaining moves need the edit permission. Paid never passes
     * here: payment goes through recordPayment only.
     */
    public function transitionTo(User $user, Claim $claim, ClaimStatus $target): bool
    {
        $permission = match ($target) {
            ClaimStatus::Submitted => Permission::SubmitClaims,
            ClaimStatus::ReadyToSubmit,
            ClaimStatus::Accepted,
            ClaimStatus::PartiallyAccepted,
            ClaimStatus::Rejected => Permission::ReviewClaims,
            ClaimStatus::Paid => null,
            default => Permission::EditClaims,
        };

        if ($permission === null) {
            return false;
        }

        return $user->hasPermissionIn($claim->organization_id, $permission);
    }

    /**
     * Recording a payment needs the payment permission and an
     * accepted or partially accepted claim.
     */
    public function recordPayment(User $user, Claim $claim): bool
    {
        return in_array($claim->status, [ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted], true)
            && $user->hasPermissionIn($claim->organization_id, Permission::RecordClaimPayments);
    }

    /**
     * Attaching or detaching evidence needs the attach permission.
     * Closed claims cannot change their evidence.
     */
    public function attachEvidence(User $user, Claim $claim): bool
    {
        return ! $claim->isClosed()
            && $user->hasPermissionIn($claim->organization_id, Permission::AttachClaimEvidence);
    }

    /**
     * Overriding a readiness assessment is a review decision.
     */
    public function overrideReadiness(User $user, Claim $claim): bool
    {
        return ! $claim->isClosed()
            && $user->hasPermissionIn($claim->organization_id, Permission::ReviewClaims);
    }

    /**
     * Generating a claim package needs the generate permission.
     */
    public function generatePackage(User $user, Claim $claim): bool
    {
        return $user->hasPermissionIn($claim->organization_id, Permission::GenerateClaimPackages);
    }
}
