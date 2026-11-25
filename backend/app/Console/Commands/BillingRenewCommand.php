<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Renew subscriptions whose period has ended (v1.2.0).
 *
 * Trials convert to paid when a payment method is stored, or
 * lapse to Free without one. Cancellations at period end become
 * Free. Pending downgrades apply. Paid plans are charged for the
 * next period; a failed charge moves the subscription to past due
 * and dunning takes over. Success-fee plans get their monthly
 * arrears invoice for the ended period.
 *
 * Scheduled daily; safe to re-run (a period is only renewed
 * once, because the period end moves forward on success).
 */
class BillingRenewCommand extends Command
{
    protected $signature = 'billing:renew';

    protected $description = 'Renew subscriptions whose billing period has ended';

    public function handle(BillingService $billing): int
    {
        $counts = $billing->renewDue();

        $this->info(sprintf(
            'Renewals: %d renewed, %d trials converted, %d trials lapsed, %d canceled, %d downgrades applied, %d metered invoices, %d failed.',
            $counts['renewed'],
            $counts['trials_converted'],
            $counts['trials_lapsed'],
            $counts['canceled'],
            $counts['downgrades_applied'],
            $counts['metered_invoiced'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }
}
