<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ledger payment recorded against a claim (v1.5.0).
 *
 * The ledger is append-only in spirit: a payment row is created
 * when the organization records money received, and voiding
 * removes the row (with an audit record) instead of editing it.
 * amount is in the claim's currency; the method is a label, not
 * a live payment rail. The claim's recovered_amount stays the
 * authoritative paid total and is kept in sync by
 * ReconciliationService, so a claim paid before the ledger
 * existed can still show a paid total with no rows here.
 */
class ClaimPayment extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'claim_id',
        'amount',
        'currency',
        'payment_method',
        'payment_reference',
        'paid_at',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'date',
        ];
    }

    /**
     * The claim this payment was recorded against.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The user who recorded this payment.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
