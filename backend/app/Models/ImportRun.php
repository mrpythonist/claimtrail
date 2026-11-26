<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One import execution, whatever started it (v1.3.1).
 *
 * source is 'upload' (a two-step preview/confirm), 'schedule'
 * (a scheduled import), or 'sync' (a synchronization workflow
 * run). Statuses: pending (created, not yet run), running,
 * completed (every row imported), partial (some rows imported,
 * some skipped), failed (nothing ran: the file or the run
 * itself broke). The per-outcome counts are the import history;
 * the rows carry the per-row detail for error correction.
 */
class ImportRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_SCHEDULE = 'schedule';

    public const SOURCE_SYNC = 'sync';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'source',
        'import_mapping_id',
        'import_schedule_id',
        'sync_run_id',
        'filename',
        'status',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'duplicate_rows',
        'error_rows',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ImportMapping::class, 'import_mapping_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ImportSchedule::class, 'import_schedule_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_run_id')->orderBy('row_index');
    }
}
