<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A disaster recovery drill (v2.0.3).
 *
 * kind is verify (re-hash a backup against its manifest) or
 * restore_dry_run (copy the backup database to a temporary
 * file, open it read-only, and count its rows, without ever
 * touching the live database). There is no real failover: the
 * drill proves a backup can be read back, not that traffic can
 * move to another host. result carries the measured outcome.
 */
class DisasterRecoveryDrill extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KIND_VERIFY = 'verify';

    public const KIND_RESTORE_DRY_RUN = 'restore_dry_run';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return [self::KIND_VERIFY, self::KIND_RESTORE_DRY_RUN];
    }

    protected $fillable = [
        'organization_id',
        'backup_run_id',
        'kind',
        'status',
        'result',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
