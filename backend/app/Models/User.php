<?php

namespace App\Models;

use App\Permission;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Organizations this user belongs to, via memberships.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Membership records for this user.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * Active memberships only.
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', 'active');
    }

    /**
     * The user's membership in an organization, or null.
     *
     * Bypasses the tenant scope: this lookup names its organization
     * explicitly, so the resolved current organization must not
     * filter it out. That matters during organization resolution
     * itself, when the resolved organization is the one being
     * checked.
     */
    public function membershipIn(Organization|string $organization): ?OrganizationMember
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->memberships()->withoutGlobalScope('organization')->where('organization_id', $id)->first();
    }

    /**
     * Whether the user is an active member of the organization.
     */
    public function isActiveMemberOf(Organization|string $organization): bool
    {
        $membership = $this->membershipIn($organization);

        return $membership !== null && $membership->isActive();
    }

    /**
     * Whether the user holds a permission in an organization.
     *
     * Permissions come only from the role map in config/permissions.php.
     * Inactive members hold no permissions. This is the single permission
     * check for the whole application; do not compare role names elsewhere.
     */
    public function hasPermissionIn(Organization|string $organization, Permission|string $permission): bool
    {
        $membership = $this->membershipIn($organization);

        if ($membership === null || ! $membership->isActive()) {
            return false;
        }

        $permissionValue = $permission instanceof Permission ? $permission->value : $permission;
        $allowed = config('permissions.'.$membership->role->value, []);

        return in_array($permissionValue, $allowed, true);
    }
}
