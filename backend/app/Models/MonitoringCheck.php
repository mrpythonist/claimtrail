<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of the production monitoring check (v1.2.3).
 *
 * Monitoring is deployment-wide, not tenant-scoped: one row per
 * run of `php artisan monitoring:check` (hourly by schedule, or
 * on demand from the operations dashboard). The row stores the
 * rolled-up status plus the per-check results, so the operations
 * page can show the latest state and the recent history without
 * re-running anything.
 *
 * Statuses mirror HealthCheckService: ok, degraded, down.
 */
class MonitoringCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'checks',
        'failed_jobs',
        'queue_depth',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'failed_jobs' => 'integer',
            'queue_depth' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * Whether this run found anything wrong.
     */
    public function isFailing(): bool
    {
        return $this->status !== 'ok';
    }
}
