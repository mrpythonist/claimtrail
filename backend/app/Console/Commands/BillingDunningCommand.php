<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Dunning for past-due invoices (v1.2.0).
 *
 * Retries failed charges at the configured delays (3 and 7 days
 * after the failure). An invoice 14+ days past due downgrades the
 * organization to Free and is written off as uncollectible; no
 * data is ever deleted.
 *
 * Scheduled daily; safe to re-run (retries only fire when their
 * retry time has come).
 */
class BillingDunningCommand extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Retry past-due invoices and downgrade organizations 14+ days past due';

    public function handle(BillingService $billing): int
    {
        $counts = $billing->processDunning();

        $this->info(sprintf(
            'Dunning: %d retried, %d paid, %d downgraded to Free.',
            $counts['retried'],
            $counts['paid'],
            $counts['downgraded'],
        ));

        return self::SUCCESS;
    }
}
