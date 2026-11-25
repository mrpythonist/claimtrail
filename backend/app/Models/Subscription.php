<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One subscription per organization.
 *
 * An organization always has exactly one row, created lazily as
 * Free by BillingService. Status: trialing (30-day Growth trial),
 * active, past_due (a renewal failed and dunning is running), or
 * canceled (kept for history; a canceled org gets a fresh Free row
 * only through a new subscription, never by deleting data).
 *
 * Downgrades are scheduled, not immediate: pending_plan and friends
 * apply at the next renewal, so the buyer keeps the higher tier
 * until the period they paid for ends. Upgrades apply immediately
 * with proration.
 */
class Subscription extends Model
{
    use HasUuids;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'organization_id',
        'plan',
        'status',
        'interval',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'plan_started_at',
        'success_fee_cap_until',
        'cancel_at_period_end',
        'canceled_at',
        'pending_plan',
        'pending_interval',
        'pending_extra_carriers',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
        'payment_method_token',
        'payment_method_brand',
        'payment_method_last4',
        'extra_carriers',
        'credit_balance_cents',
        'claims_carryover',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'plan_started_at' => 'datetime',
            'success_fee_cap_until' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'extra_carriers' => 'integer',
            'pending_extra_carriers' => 'integer',
            'credit_balance_cents' => 'integer',
            'claims_carryover' => 'integer',
        ];
    }

    /**
     * The organization this subscription bills.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Invoices issued against this subscription.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Metered usage records (success-fee plan only).
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(BillingUsageRecord::class);
    }

    /**
     * Whether the subscription is currently usable: trialing or
     * active. A past-due subscription keeps access while dunning
     * runs; a canceled one does not.
     */
    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE], true);
    }

    /**
     * Whether the success-fee first-year cap still applies.
     */
    public function successFeeCapped(): bool
    {
        return $this->plan === 'success_fee'
            && $this->success_fee_cap_until !== null
            && now()->lt($this->success_fee_cap_until);
    }

    /**
     * Whether a downgrade is scheduled for the next renewal.
     */
    public function hasPendingDowngrade(): bool
    {
        return $this->pending_plan !== null;
    }
}
