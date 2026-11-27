<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Run reconciliation for the current month (v1.5.0).
 *
 * One report per organization that recorded ledger payments
 * this month; an organization that already has a report
 * generated today for the same period is skipped, so the
 * daily schedule never spams duplicate reports. Generating
 * a report only reads claims and stores the snapshot.
 */
class RunReconciliationCommand extends Command
{
    protected $signature = 'reconciliation:run
        {--organization= : Only reconcile one organization ID}
        {--period= : Month to reconcile, YYYY-MM (defaults to the current month)}';

    protected $description = 'Generate the current month reconciliation report for organizations with ledger payments';

    public function __construct(
        protected ReconciliationService $reconciliation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->option('period') !== null
            ? CarbonImmutable::createFromFormat('Y-m', (string) $this->option('period'))->startOfMonth()
            : now()->toImmutable()->startOfMonth();

        $organization = $this->option('organization') !== null
            ? Organization::find($this->option('organization'))
            : null;

        if ($this->option('organization') !== null && $organization === null) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $results = $this->reconciliation->runReconciliation($organization, $period);

        foreach ($results as $result) {
            if ($result['skipped']) {
                $this->line('Skipped '.$result['organization_id'].' (report already generated today).');
            } else {
                $this->line('Reconciled '.$result['organization_id'].': report '.$result['report_id'].' with '.$result['discrepancies'].' discrepancies.');
            }
        }

        $this->info('Reconciliation complete: '.count($results).' organizations.');

        return self::SUCCESS;
    }
}
