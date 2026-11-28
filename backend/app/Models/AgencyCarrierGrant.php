<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-carrier permission grant (v2.1.0).
 *
 * Owners and admins of the agency organization have access to
 * every linked carrier without a grant. Every other agency
 * member needs a grant for each carrier they may touch. The
 * scope is view (read the carrier's claims, dashboard, and
 * reports) or manage (view plus commission rules, reseller
 * settings, and grants for that carrier). A grant is tied to
 * the partner link, so suspending or removing the link removes
 * the access with it.
 */
class AgencyCarrierGrant extends Model
{
    use HasUuids;

    public const SCOPE_VIEW = 'view';

    public const SCOPE_MANAGE = 'manage';

    protected $fillable = [
        'partner_link_id',
        'user_id',
        'scope',
        'granted_by',
    ];

    /**
     * Every scope value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_VIEW,
            self::SCOPE_MANAGE,
        ];
    }

    /**
     * Whether this grant covers the given scope. Manage covers
     * view as well.
     */
    public function covers(string $scope): bool
    {
        if ($this->scope === self::SCOPE_MANAGE) {
            return true;
        }

        return $this->scope === $scope;
    }

    /**
     * The partner link this grant is tied to.
     */
    public function partnerLink(): BelongsTo
    {
        return $this->belongsTo(PartnerLink::class);
    }

    /**
     * The agency member the grant is for.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user who created the grant.
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
