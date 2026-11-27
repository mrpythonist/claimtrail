<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated reconciliation report (v1.5.0).
 *
 * A report is a snapshot: the period it covers, the totals
 * (requested, approved, paid, outstanding, counts) and the
 * discrepancies found at generation time, stored as JSON so the
 * report can be listed and re-read later. Generating a report
 * never moves money and never changes a claim; it only reads.
 */
class ReconciliationReport extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'period_start',
        'period_end',
        'totals',
        'discrepancies',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'totals' => 'array',
            'discrepancies' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * The user who generated this report, if it was generated
     * from the API rather than by the scheduled command.
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
