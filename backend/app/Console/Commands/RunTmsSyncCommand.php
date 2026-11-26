<?php

namespace App\Console\Commands;

use App\Models\TmsIntegration;
use App\Models\TmsSyncRun;
use App\Services\TmsService;
use Illuminate\Console\Command;

/**
 * Run a TMS sync for one integration (v1.3.2).
 *
 * The operator runs a sync on demand with this command:
 * tms:sync {integration-uuid} --kind=full (the default) or
 * one of shipments, status, appointments, documents. The
 * run is recorded as a TmsSyncRun exactly like an API run,
 * and the command reports its final status and summary.
 * A run over a schema-only provider fails with the live
 * unavailable message; the command reports the failure
 * and exits non-zero.
 */
class RunTmsSyncCommand extends Command
{
    protected $signature = 'tms:sync {integration : UUID of the TMS integration} {--kind=full : Sync kind: shipments, status, appointments, documents, or full}';

    protected $description = 'Run a TMS sync for one integration';

    public function handle(TmsService $tms): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, TmsSyncRun::kinds(), true)) {
            $this->error(sprintf(
                'Unknown kind "%s". Use one of: %s.',
                $kind,
                implode(', ', TmsSyncRun::kinds()),
            ));

            return self::FAILURE;
        }

        $integration = TmsIntegration::withoutGlobalScope('organization')
            ->find((string) $this->argument('integration'));

        if ($integration === null) {
            $this->error('No TMS integration with that id.');

            return self::FAILURE;
        }

        $run = $tms->runSync($integration, $kind, null);

        if ($run->status === TmsSyncRun::STATUS_FAILED) {
            $this->error('Sync failed: '.($run->error_message ?? 'unknown error'));

            return self::FAILURE;
        }

        $summary = $run->summary ?? [];

        $this->info(sprintf(
            'Sync %s finished: %d fetched, %d created, %d updated, %d skipped, %d documents stored.',
            $run->kind,
            $summary['fetched'] ?? 0,
            $summary['created'] ?? 0,
            $summary['updated'] ?? 0,
            $summary['skipped'] ?? 0,
            $summary['documents_created'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
