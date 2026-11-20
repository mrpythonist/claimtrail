<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup attempt (v1.1.3).
 *
 * Backups are deployment-wide: the whole database plus every
 * evidence and package file. The row attributes the run to the
 * organization that requested it; scheduled runs have no
 * organization. Status moves running -> completed, or running ->
 * failed with an error message. The checksum is the SHA-256 of
 * the database copy; per-file checksums live in the manifest.
 */
class BackupRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'driver',
        'status',
        'path',
        'size_bytes',
        'checksum',
        'error_message',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    /**
     * Whether the backup finished and passed its own checks.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * The organization that requested this backup, if any.
     */
    public function requestingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
