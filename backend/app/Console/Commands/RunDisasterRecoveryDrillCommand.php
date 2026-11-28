<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\DisasterRecoveryService;
use Illuminate\Console\Command;

/**
 * Run a disaster recovery drill (v2.0.3).
 *
 * verify re-hashes a backup against its manifest; restore_dry_run
 * opens a temporary copy of the backup database read-only and
 * counts its rows, without ever touching the live database.
 * There is no failover to test: the drill proves a backup can be
 * read back. Exit code 1 when the drill fails or no completed
 * backup exists.
 */
class RunDisasterRecoveryDrillCommand extends Command
{
    protected $signature = 'dr:drill {--kind=verify : Drill kind: verify or restore_dry_run} {--run= : UUID of the backup run to drill against (default: latest completed)} {--organization= : UUID of the organization the drill is attributed to}';

    protected $description = 'Run a disaster recovery drill (verify or restore dry-run)';

    public function handle(DisasterRecoveryService $disasterRecovery): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, \App\Models\DisasterRecoveryDrill::kinds(), true)) {
            $this->error('Unknown drill kind: '.$kind);

            return self::FAILURE;
        }

        $organization = null;

        if ($this->option('organization') !== null) {
            $organization = Organization::query()
                ->whereKey((string) $this->option('organization'))
                ->first();

            if ($organization === null) {
                $this->error('Organization not found: '.$this->option('organization'));

                return self::FAILURE;
            }
        } else {
            $organization = Organization::query()->orderBy('name')->first();

            if ($organization === null) {
                $this->error('No organization exists yet. Create one first.');

                return self::FAILURE;
            }
        }

        $drill = $disasterRecovery->runDrill(
            $organization,
            $kind,
            $this->option('run') !== null ? (string) $this->option('run') : null,
        );

        $result = (array) ($drill->result ?? []);

        if ($drill->status === 'completed') {
            $this->info('Drill '.$kind.' completed. '.($result['note'] ?? ''));

            if (isset($result['total_rows'])) {
                $this->line(sprintf(
                    '  %d tables, %d rows read back from the backup copy.',
                    $result['table_count'] ?? 0,
                    $result['total_rows'],
                ));
            }

            return self::SUCCESS;
        }

        $this->error('Drill '.$kind.' failed: '.($result['error'] ?? 'unknown reason.'));

        return self::FAILURE;
    }
}
