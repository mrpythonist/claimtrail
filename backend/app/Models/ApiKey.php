<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Bearer key for the public API (v2.0.2).
 *
 * Only the SHA-256 hash of the key is stored; the full key is
 * shown exactly once at creation and never again, not even to
 * its creator. key_prefix is the first characters of the key
 * (e.g. ct_live_9f2a) so a key can be recognised in lists
 * without ever revealing it. scopes is the list of granted
 * scopes (see ApiKeyService::SCOPES). status is active,
 * rotating (an old key inside its rotation grace window), or
 * revoked. kind is standard or broker: broker keys are
 * read/status only and must name the broker.
 */
class ApiKey extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KIND_STANDARD = 'standard';

    public const KIND_BROKER = 'broker';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ROTATING = 'rotating';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'key_prefix',
        'key_hash',
        'scopes',
        'status',
        'broker_name',
        'rotated_from_id',
        'grace_ends_at',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_by',
        'revoked_by',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'grace_ends_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Whether the key may authenticate right now: active, or
     * rotating and still inside its grace window, and not
     * past an expiry.
     */
    public function isUsable(): bool
    {
        if ($this->status === self::STATUS_REVOKED) {
            return false;
        }

        if ($this->status === self::STATUS_ROTATING) {
            if ($this->grace_ends_at === null || $this->grace_ends_at->isPast()) {
                return false;
            }
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->status === self::STATUS_ACTIVE || $this->status === self::STATUS_ROTATING;
    }

    /**
     * Whether the key grants a scope.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * The key this one replaced, if any.
     */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    /**
     * The user who created the key.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
