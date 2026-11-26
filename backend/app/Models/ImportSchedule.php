<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled import (v1.3.1).
 *
 * A schedule re-runs a file the organization already uploaded
 * with a saved mapping. No live SFTP, URL, or provider source
 * is involved: the file lives on the private disk under
 * imports/{organization}/schedules. Frequencies: daily,
 * weekly, manual (on-demand only). next_run_at advances after
 * each run; the scheduler command picks up active schedules
 * whose next run is due. last_import_run_id points at the
 * most recent execution, so the history shows what happened.
 */
class ImportSchedule extends Model
{
    use BelongsToOrganization, HasUuids;

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'name',
        'import_mapping_id',
        'source_file_path',
        'frequency',
        'next_run_at',
        'last_run_at',
        'last_import_run_id',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ImportMapping::class, 'import_mapping_id');
    }

    public function lastImportRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'last_import_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the scheduler should pick this schedule up now.
     */
    public function isDue(): bool
    {
        return $this->active
            && $this->frequency !== self::FREQUENCY_MANUAL
            && $this->next_run_at !== null
            && $this->next_run_at->isPast();
    }

    /**
     * The next run time after a run now, or null for manual.
     */
    public function nextRunAfterNow(): ?\DateTimeInterface
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => now()->addDay(),
            self::FREQUENCY_WEEKLY => now()->addWeek(),
            default => null,
        };
    }
}
