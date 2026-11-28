<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner link (v2.1.0): one agency organization managing one
 * carrier organization.
 *
 * The link is created pending by the agency and only becomes
 * active when an owner or admin of the CARRIER organization
 * approves it, so an agency cannot claim a carrier
 * unilaterally. Suspended links keep their history but grant
 * no access; removed links are kept as a record, never deleted,
 * so referral attribution and commission history survive. A
 * carrier organization can belong to several agencies at once.
 */
class PartnerLink extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'agency_organization_id',
        'carrier_organization_id',
        'status',
        'referred_by_code',
        'assigned_plan',
        'approved_by',
        'approved_at',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Every status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_SUSPENDED,
            self::STATUS_REJECTED,
            self::STATUS_REMOVED,
        ];
    }

    /**
     * Whether the link grants the agency access to the carrier
     * right now: approved only. A suspended link keeps its
     * history but grants nothing until reactivated.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * The agency organization managing the carrier.
     */
    public function agencyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'agency_organization_id');
    }

    /**
     * The carrier organization being managed.
     */
    public function carrierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'carrier_organization_id');
    }

    /**
     * The user who approved the link, on the carrier side.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The user who created the link, on the agency side.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Per-carrier permission grants under this link.
     */
    public function grants(): HasMany
    {
        return $this->hasMany(AgencyCarrierGrant::class);
    }

    /**
     * Commission rules scoped to this carrier link.
     */
    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    /**
     * Links for one agency organization.
     */
    public function scopeForAgency($query, string $agencyOrganizationId)
    {
        return $query->where('agency_organization_id', $agencyOrganizationId);
    }

    /**
     * Links where the organization is the carrier.
     */
    public function scopeForCarrier($query, string $carrierOrganizationId)
    {
        return $query->where('carrier_organization_id', $carrierOrganizationId);
    }

    /**
     * Links that currently grant access.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
