<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metered usage record for the success-fee plan.
 *
 * Created when a payment is recorded against a claim while the
 * organization is on the success-fee plan. amount_cents is the
 * cash recorded as paid; fee_cents is 15% of it. One record per
 * claim (unique claim_id): a claim is paid once, so it is metered
 * once. The $49/mo first-year cap is NOT applied here; it is
 * applied at invoice time as a cap-adjustment line, so the fee
 * stays auditable line by line.
 */
class BillingUsageRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'claim_id',
        'amount_cents',
        'fee_cents',
        'period_start',
        'period_end',
        'recorded_at',
        'invoiced_at',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'recorded_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    /**
     * The organization that was metered.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The subscription the usage was recorded against.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The claim whose payment was metered.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The arrears invoice that collected this record, once invoiced.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Whether the record has been invoiced yet.
     */
    public function isInvoiced(): bool
    {
        return $this->invoiced_at !== null;
    }
}
