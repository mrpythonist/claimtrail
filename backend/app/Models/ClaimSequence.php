<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organization, per-year claim reference counter.
 *
 * Only ClaimReferenceService touches this table. It locks the row
 * inside a transaction before incrementing, so concurrent claims
 * never share a reference number.
 */
class ClaimSequence extends Model
{
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
