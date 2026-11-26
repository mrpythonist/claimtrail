<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CSV data row of an import run (v1.3.1).
 *
 * data is the parsed shipment fields for the row. Statuses:
 * imported (a shipment was created), skipped (the row failed
 * validation and was not retried), duplicate (the internal
 * reference already existed, in the organization or earlier
 * in the same file), failed (the run itself broke before the
 * row was decided), corrected (the row was fixed with new
 * field values and a shipment was created on retry). errors
 * is the per-field validation messages, so the correction form
 * can show exactly what was wrong.
 */
class ImportRow extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CORRECTED = 'corrected';

    protected $fillable = [
        'organization_id',
        'import_run_id',
        'row_index',
        'data',
        'status',
        'errors',
        'shipment_id',
        'corrected_at',
        'corrected_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
            'corrected_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * Whether this row can still be corrected and retried.
     */
    public function isCorrectable(): bool
    {
        return in_array($this->status, [
            self::STATUS_SKIPPED,
            self::STATUS_DUPLICATE,
            self::STATUS_FAILED,
        ], true);
    }
}
