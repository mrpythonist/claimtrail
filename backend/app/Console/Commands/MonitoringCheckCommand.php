<?php

namespace App\Console\Commands;

use App\Services\MonitoringService;
use Illuminate\Console\Command;

/**
 * Run the production monitoring check (v1.2.3).
 *
 * Runs the health checks (database, cache, queue, storage),
 * counts failed jobs and queue depth, and stores the result for
 * the operations dashboard. Exit code 0 when everything is ok,
 * 1 when degraded or down. Scheduled hourly; a failing run also
 * writes a warning to the log.
 */
class MonitoringCheckCommand extends Command
{
    protected $signature = 'monitoring:check';

    protected $description = 'Run the production monitoring check and store the result';

    public function handle(MonitoringService $monitoring): int
    {
        $check = $monitoring->run();

        $this->info('Monitoring check: '.$check->status.' at '.$check->checked_at);

        foreach ($check->checks as $name => $result) {
            $this->line(sprintf(
                '  %-10s %-9s %s',
                $name,
                $result['status'],
                $result['detail'] ?? '',
            ));
        }

        $this->line(sprintf(
            '  failed jobs: %d, queue depth: %d',
            $check->failed_jobs,
            $check->queue_depth,
        ));

        return $check->isFailing() ? self::FAILURE : self::SUCCESS;
    }
}
