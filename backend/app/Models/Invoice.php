<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice issued to an organization.
 *
 * Status: draft (being built, never shown), open (issued, awaiting
 * payment), paid, past_due (a charge failed; dunning is running),
 * void (superseded), uncollectible (written off after the 14-day
 * past-due downgrade to Free). Money is integer cents, USD.
 *
 * The number is INV-YYYY-NNNN per organization, from the
 * invoice_sequences counter, locked inside a transaction so two
 * invoices never share a number.
 */
class Invoice extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_VOID = 'void';

    public const STATUS_UNCOLLECTIBLE = 'uncollectible';

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'number',
        'status',
        'currency',
        'subtotal_cents',
        'total_cents',
        'amount_paid_cents',
        'period_start',
        'period_end',
        'due_date',
        'paid_at',
        'payment_attempts',
        'next_retry_at',
        'provider_invoice_id',
        'provider_charge_id',
        'failure_code',
        'failure_message',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_date' => 'datetime',
            'paid_at' => 'datetime',
            'payment_attempts' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * The organization this invoice bills.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The subscription this invoice was issued against, if any.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The invoice lines, in display order.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('created_at');
    }

    /**
     * Whether the invoice still needs money.
     */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PAST_DUE], true);
    }
}
