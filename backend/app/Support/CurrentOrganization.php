<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Holds the organization resolved for the current request.
 *
 * The ResolveOrganization middleware sets this after verifying the
 * authenticated user is an active member of the organization named by
 * the X-Organization-Id header. Tenant-scoped queries read it through
 * the BelongsToOrganization trait. Outside a request with a resolved
 * organization (console, seeders, public endpoints) it stays null and
 * tenant scopes are no-ops.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->getKey();
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }
}
