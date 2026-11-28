<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commission rule (v2.1.0).
 *
 * One default rule per agency (a null partner_link_id) plus
 * optional per-carrier overrides. The rate applies to recorded
 * payments only: the earned commission for a carrier is the
 * sum of its ClaimPayment rows times the rate, optionally
 * bounded by the cap. The basis is always "paid"; the column
 * exists so the rule states its basis plainly instead of
 * leaving it implicit.
 *
 * Commission is a recorded and calculated figure. Billing
 * runs on the mock provider, no real money moves, and nothing
 * here pays a partner out. A rule never creates a payment
 * obligation; it only describes how earned commission is
 * computed from what the carrier already recorded.
 */
class CommissionRule extends Model
{
    use HasUuids;

    public const BASIS_PAID = 'paid';

    protected $fillable = [
        'agency_organization_id',
        'partner_link_id',
        'rate_percent',
        'cap_cents',
        'basis',
        'active',
        'effective_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'cap_cents' => 'integer',
            'active' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    /**
     * Whether this is the agency-wide default rule.
     */
    public function isDefault(): bool
    {
        return $this->partner_link_id === null;
    }

    /**
     * The agency organization that owns the rule.
     */
    public function agencyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'agency_organization_id');
    }

    /**
     * The carrier link this rule overrides, or null for the
     * agency default.
     */
    public function partnerLink(): BelongsTo
    {
        return $this->belongsTo(PartnerLink::class);
    }

    /**
     * The user who created the rule.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
