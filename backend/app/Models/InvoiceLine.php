<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an invoice. Amounts are integer cents and may be
 * negative: proration credits and the success-fee cap adjustment
 * are negative lines, so the invoice stays auditable line by line.
 */
class InvoiceLine extends Model
{
    use HasUuids;

    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_PRORATION_CREDIT = 'proration_credit';

    public const KIND_PRORATION_CHARGE = 'proration_charge';

    public const KIND_SUCCESS_FEE = 'success_fee';

    public const KIND_CAP_ADJUSTMENT = 'cap_adjustment';

    public const KIND_CREDIT_APPLIED = 'credit_applied';

    protected $fillable = [
        'invoice_id',
        'kind',
        'description',
        'amount_cents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * The invoice this line belongs to.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
