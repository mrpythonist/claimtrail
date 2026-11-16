<?php

namespace App\Console\Commands;

use App\Services\DeadlineStatusService;
use Illuminate\Console\Command;

/**
 * Recomputes every active claim deadline status.
 *
 * Scheduled hourly in routes/console.php. Notification delivery for
 * upcoming and overdue deadlines is a Milestone 4 concern; until then
 * this command logs the counts so the scheduler output shows the
 * state of play.
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
