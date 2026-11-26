<?php

namespace App\Console\Commands;

use App\Models\TmsIntegration;
use App\Models\TmsSyncRun;
use App\Services\TmsService;
use Illuminate\Console\Command;

/**
 * Run due TMS auto-syncs (v1.3.2).
 *
 * Picks up active integrations with auto-sync on whose
 * last sync is older than their interval (or never synced)
 * and runs a full sync for each. A single integration can
 * be forced with --integration, and one organization with
 * --organization (UUID or slug). A failed sync records a
 * failed run and the command keeps going with the next
 * integration; the exit code is non-zero when any sync
 * failed.
 */
class RunDueTmsSyncsCommand extends Command
{
    protected $signature = 'tms:run-due {--organization= : UUID or slug of one organization} {--integration= : UUID of one integration to run now}';

    protected $description = 'Run due TMS auto-syncs';

    public function handle(TmsService $tms): int
    {
        $query = TmsIntegration::withoutGlobalScope('organization')
            ->with(['organization'])
            ->where('status', TmsIntegration::STATUS_ACTIVE);

        if ($this->option('integration') !== null) {
            $query->whereKey((string) $this->option('integration'));
        }

        if ($this->option('organization') !== null) {
            $identifier = (string) $this->option('organization');

            $query->whereHas('organization', fn ($q) => $q
                ->where('id', $identifier)
                ->orWhere('slug', $identifier));
        }

        $integrations = $query->get()->filter(fn (TmsIntegration $integration) => $this->option('integration') !== null || $integration->autoSyncDue());

        if ($integrations->isEmpty()) {
            $this->info('No TMS auto-syncs are due.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($integrations as $integration) {
            $run = $tms->runSync($integration, TmsSyncRun::KIND_FULL, null);

            if ($run->status === TmsSyncRun::STATUS_FAILED) {
                $failed++;
                $this->error(sprintf(
                    'Sync failed for "%s" (%s): %s',
                    $integration->name,
                    $integration->provider,
                    $run->error_message ?? 'unknown error',
                ));

                continue;
            }

            $summary = $run->summary ?? [];

            $this->info(sprintf(
                'Synced "%s" (%s): %d fetched, %d created, %d updated, %d skipped.',
                $integration->name,
                $integration->provider,
                $summary['fetched'] ?? 0,
                $summary['created'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['skipped'] ?? 0,
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
