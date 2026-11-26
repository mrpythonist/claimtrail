<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A synchronization workflow run (v1.3.1).
 *
 * A sync workflow is a named, repeatable import over a saved
 * mapping and a stored file: the same shape as a scheduled
 * import, but with its own lifecycle and history. Statuses:
 * pending (created, never run), running (an import is in
 * flight), completed (the linked import run finished cleanly
 * or partially), failed (the import run failed or the file
 * was missing). The linked import run carries the per-row
 * detail; the summary here mirrors its outcome counts.
 * No TMS or other external system is involved: sync runs
 * import files the organization already uploaded. TMS
 * integrations are v1.3.2.
 */
class SyncRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'name',
        'import_mapping_id',
        'source_file_path',
        'status',
        'import_run_id',
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

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
