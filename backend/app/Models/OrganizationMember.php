<?php

namespace App\Models;

use App\MembershipStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\OrganizationRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMember extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    /**
     * The user holding this membership.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user who sent the invitation, if any.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Whether this membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
