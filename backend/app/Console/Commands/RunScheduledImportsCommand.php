<?php

namespace App\Console\Commands;

use App\Models\ImportSchedule;
use App\Models\Organization;
use App\Services\ImportService;
use Illuminate\Console\Command;

/**
 * Run due scheduled imports (v1.3.1).
 *
 * Picks up active daily and weekly schedules whose next run
 * is due. Manual schedules never run here; they run on
 * demand from the API. A single schedule can be forced with
 * --schedule, and one organization with --organization (UUID
 * or slug). Each run is recorded as an import run with source
 * 'schedule', and the schedule advances its next run time.
 * A missing source file records a failed run; the command
 * keeps going with the next schedule.
 */
class RunScheduledImportsCommand extends Command
{
    protected $signature = 'imports:run-scheduled {--organization= : UUID or slug of one organization} {--schedule= : UUID of one schedule to run now}';

    protected $description = 'Run due scheduled imports';

    public function handle(ImportService $imports): int
    {
        $query = ImportSchedule::withoutGlobalScope('organization')
            ->with(['organization', 'mapping'])
            ->where('active', true);

        if ($this->option('schedule') !== null) {
            $query->whereKey((string) $this->option('schedule'));
        } else {
            $query->where('frequency', '!=', ImportSchedule::FREQUENCY_MANUAL)
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', now());
        }

        if ($this->option('organization') !== null) {
            $identifier = (string) $this->option('organization');

            $query->whereHas('organization', fn ($q) => $q
                ->where('id', $identifier)
                ->orWhere('slug', $identifier));
        }

        $schedules = $query->orderBy('next_run_at')->get();

        if ($schedules->isEmpty()) {
            $this->info('No scheduled imports are due.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($schedules as $schedule) {
            $organization = $schedule->organization;

            if ($organization === null) {
                continue;
            }

            $actor = $organization->activeMembers()
                ->where('role', 'owner')
                ->orderBy('joined_at')
                ->first()?->user;

            if ($actor === null) {
                $this->error('Schedule "'.$schedule->name.'" has no owner to run as; skipped.');

                $failed++;

                continue;
            }

            $run = $imports->runSchedule($schedule, $actor);

            $this->info(sprintf(
                'Schedule "%s" (%s): %s, %d imported, %d skipped.',
                $schedule->name,
                $organization->slug,
                $run->status,
                $run->imported_rows,
                $run->skipped_rows,
            ));

            if ($run->status === 'failed') {
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
