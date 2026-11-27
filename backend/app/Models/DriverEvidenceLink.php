<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A driver evidence submission link (v1.3.3).
 *
 * A signed link that lets a driver submit evidence for one
 * shipment, or one claim, or one shipment plus its claim,
 * without an account. Only the sha256 of the token is
 * stored (token_hash); the raw token is shown once at
 * creation and sent in the email, exactly like an
 * organization invitation token.
 *
 * A link is usable only while it is not revoked, not
 * expired, and used_count is below max_uses (1 by default:
 * single-use). Expiry and revocation are enforced on
 * every public access; the link never grants access to
 * anything else in the organization.
 */
class DriverEvidenceLink extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'shipment_id',
        'claim_id',
        'token_hash',
        'driver_name',
        'driver_email',
        'expires_at',
        'max_uses',
        'used_count',
        'revoked_at',
        'expiry_notified_at',
        'created_by',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    /**
     * The shipment this link is scoped to, if any.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The claim this link is scoped to, if any.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The user who created this link.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The evidence submissions made through this link.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(DriverEvidenceSubmission::class, 'driver_evidence_link_id')->orderByDesc('created_at');
    }

    /**
     * Whether the link is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether the link has expired without being used up.
     */
    public function isExpired(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isPast();
    }

    /**
     * Whether the link has been used up: every allowed
     * submission spent.
     */
    public function isUsedUp(): bool
    {
        return $this->used_count >= $this->max_uses;
    }

    /**
     * Whether the link accepts another submission right
     * now: not revoked, not expired, uses left.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isUsedUp();
    }

    /**
     * The human-readable state: usable, used, expired, or
     * revoked. Used wins over expired when a link spent its
     * last use before its expiry passed.
     */
    public function state(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        if ($this->isUsedUp()) {
            return 'used';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'usable';
    }
}
