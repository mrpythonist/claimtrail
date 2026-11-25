<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organization, per-year invoice number counter.
 *
 * Only BillingService touches this table. It locks the row inside
 * a transaction before incrementing, so concurrent invoices never
 * share a number. Mirrors ClaimSequence.
 */
class InvoiceSequence extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /**
     * The organization this counter belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
