<?php

namespace App\Console\Commands;

use App\Services\DeadlineStatusService;
use App\Support\Correlation;
use Illuminate\Console\Command;

/**
 * Recomputes every active claim deadline status.
 *
 * Scheduled hourly in routes/console.php. Due-soon and overdue
 * deadlines notify the organization's members (one notification
 * per deadline per day). Each run gets a fresh correlation id so
 * its audit rows can be traced back to the run.
 */
class RefreshDeadlineStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'claimtrail:refresh-deadline-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recompute the status of every active claim deadline';

    /**
     * Execute the console command.
     */
    public function handle(DeadlineStatusService $service): int
    {
        app(Correlation::class)->fresh();

        $result = $service->refreshAll();

        $this->info(sprintf(
            'Refreshed %d deadline(s), %d changed. Upcoming: %d, due soon: %d, overdue: %d.',
            $result['refreshed'],
            $result['changed'],
            $result['upcoming'],
            $result['due_soon'],
            $result['overdue'],
        ));

        return self::SUCCESS;
    }
}
