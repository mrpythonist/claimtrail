<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * Delete records older than the configured retention.
 *
 * Without --organization every organization is purged, then the
 * global kinds (job executions, unattributed security events)
 * once. With --organization only that organization is purged and
 * the global kinds are left alone. --dry-run reports the counts
 * without deleting anything.
 */
class PurgeRetention extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'retention:purge {--dry-run : Report what would be deleted without deleting anything} {--organization= : UUID of one organization; omit to purge all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete records older than the configured retention';

    /**
     * Execute the console command.
     */
    public function handle(RetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('organization') !== null) {
            $organization = Organization::query()
                ->whereKey($this->option('organization'))
                ->first();

            if ($organization === null) {
                $this->error('Organization not found: '.$this->option('organization'));

                return self::FAILURE;
            }

            $counts = $retention->purge($organization, $dryRun);

            $this->line($organization->name.':');
            $this->printCounts($counts);

            return self::SUCCESS;
        }

        $result = $retention->purgeAll($dryRun);

        foreach ($result['organizations'] as $organizationId => $counts) {
            $organization = Organization::query()->whereKey($organizationId)->first();

            $this->line(($organization?->name ?? $organizationId).':');
            $this->printCounts($counts);
        }

        $this->line('Global:');
        $this->printCounts($result['global']);

        $this->info($dryRun ? 'Dry run complete: nothing was deleted.' : 'Retention purge complete.');

        return self::SUCCESS;
    }

    /**
     * Print one organization's per-kind counts.
     *
     * @param  array<string, int>  $counts
     */
    protected function printCounts(array $counts): void
    {
        foreach ($counts as $kind => $count) {
            $this->line('  '.$kind.': '.$count);
        }
    }
}
