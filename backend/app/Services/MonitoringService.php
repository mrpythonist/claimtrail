<?php

namespace App\Services;

use App\Models\MonitoringCheck;
use Illuminate\Support\Facades\Log;

/**
 * Production monitoring (v1.2.3).
 *
 * One run of `php artisan monitoring:check` (hourly by schedule,
 * or on demand from the operations dashboard) runs the health
 * checks, counts failed jobs and queue depth, and stores the
 * result. The operations page shows the latest run and the
 * recent history.
 *
 * A failing check does not page anyone yet: there is no
 * alerting channel configured, so a failure is visible on the
 * operations dashboard and in the log, and the runbook in
 * docs/OPERATIONS.md says what to do next.
 */
class MonitoringService
{
    public function __construct(
        protected HealthCheckService $health,
        protected QueueMonitorService $queues,
    ) {}

    /**
     * Run the checks and store the result.
     */
    public function run(): MonitoringCheck
    {
        $result = $this->health->run();
        $stats = $this->queues->stats();

        $check = MonitoringCheck::create([
            'status' => $result['status'],
            'checks' => $result['checks'],
            'failed_jobs' => $this->queues->failedCount(),
            'queue_depth' => (int) ($stats['totals']['pending'] ?? 0),
            'checked_at' => now(),
        ]);

        if ($check->isFailing()) {
            Log::warning('Monitoring check failing', [
                'status' => $check->status,
                'checks' => $result['checks'],
                'failed_jobs' => $check->failed_jobs,
            ]);
        }

        return $check;
    }

    /**
     * The latest run, if any.
     */
    public function latest(): ?MonitoringCheck
    {
        return MonitoringCheck::orderByDesc('checked_at')->first();
    }

    /**
     * Recent runs, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitoringCheck>
     */
    public function history(int $limit = 24)
    {
        return MonitoringCheck::orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }

    /**
     * One check as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function payload(MonitoringCheck $check): array
    {
        return [
            'id' => $check->getKey(),
            'status' => $check->status,
            'checks' => $check->checks,
            'failed_jobs' => $check->failed_jobs,
            'queue_depth' => $check->queue_depth,
            'checked_at' => $check->checked_at->toIso8601String(),
        ];
    }
}
