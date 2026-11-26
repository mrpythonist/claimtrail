<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Subscription administration for the operator (v1.2.3).
 *
 * Shows one organization's subscription as the billing system
 * sees it: plan, status, period, trial, dunning state, payment
 * method, and the recent invoices, refunds, and billing events.
 * This is the tool the operator reaches for when a support
 * ticket asks about a subscription. It changes nothing.
 */
class BillingAdminCommand extends Command
{
    protected $signature = 'billing:admin {organization : UUID or slug of the organization} {--invoices=5 : How many recent invoices to show} {--events=10 : How many recent billing events to show}';

    protected $description = 'Show an organization subscription for administration';

    public function handle(BillingService $billing): int
    {
        $identifier = (string) $this->argument('organization');

        $organization = Organization::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if ($organization === null) {
            $this->error('Organization not found: '.$identifier);

            return self::FAILURE;
        }

        $subscription = $billing->subscriptionFor($organization);
        $payload = $billing->subscriptionPayload($subscription);

        $this->info($organization->name.' ('.$organization->slug.')');
        $this->table(
            ['Field', 'Value'],
            [
                ['Plan', $payload['plan'].' ('.$payload['plan_name'].')'],
                ['Status', $payload['status']],
                ['Interval', $payload['interval']],
                ['Period', ($payload['current_period_start'] ?? '-').' to '.($payload['current_period_end'] ?? '-')],
                ['Trial ends', $payload['trial_ends_at'] ?? '-'],
                ['Cancel at period end', $payload['cancel_at_period_end'] ? 'yes' : 'no'],
                ['Payment method', $payload['payment_method_brand'] !== null
                    ? $payload['payment_method_brand'].' **** '.$payload['payment_method_last4']
                    : 'none'],
                ['Credit balance', '$'.number_format(($payload['credit_balance_cents'] ?? 0) / 100, 2)],
            ],
        );

        $invoices = $billing->invoiceHistory($organization, 1, max(1, (int) $this->option('invoices')));

        if ($invoices['data'] !== []) {
            $this->info('Recent invoices:');
            $this->table(
                ['Number', 'Status', 'Total', 'Paid', 'Refunded'],
                array_map(fn (array $invoice) => [
                    $invoice['number'],
                    $invoice['status'],
                    '$'.number_format($invoice['total_cents'] / 100, 2),
                    '$'.number_format($invoice['amount_paid_cents'] / 100, 2),
                    '$'.number_format($invoice['amount_refunded_cents'] / 100, 2),
                ], $invoices['data']),
            );
        } else {
            $this->info('No invoices yet.');
        }

        $events = $billing->billingEvents($organization, 1, max(1, (int) $this->option('events')));

        if ($events['data'] !== []) {
            $this->info('Recent billing events:');
            $this->table(
                ['When', 'Type', 'Status'],
                array_map(fn (array $event) => [
                    $event['created_at'],
                    $event['type'],
                    $event['status'],
                ], $events['data']),
            );
        }

        return self::SUCCESS;
    }
}
