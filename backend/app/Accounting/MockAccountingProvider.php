<?php

namespace App\Accounting;

use App\Models\ClaimPayment;
use App\Models\Organization;

/**
 * The mock accounting provider (v2.0.2).
 *
 * One class serves both modelled providers (QuickBooks and
 * Xero) through the $provider argument: the two ledgers
 * differ in naming only at this level, and modelling them
 * as one mock keeps the dummy data honest instead of
 * pretending two different integrations exist. Every id it
 * returns is recognisably fake (QB-MOCK-*, XERO-MOCK-*).
 *
 * The dummy export is deterministic: the same payment
 * always maps to the same external id, so re-running a sync
 * over the same payments changes nothing the second time
 * (dedupe on the external reference), which is what the
 * idempotency checks assert. No request ever leaves the app.
 */
class MockAccountingProvider implements AccountingProvider
{
    public function __construct(protected string $provider = 'mock_quickbooks') {}

    public function name(): string
    {
        return $this->provider;
    }

    public function label(): string
    {
        return $this->provider === 'mock_xero' ? 'Xero (Mock)' : 'QuickBooks (Mock)';
    }

    public function liveAvailable(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            'export_payments' => true,
            'import_invoices' => false,
            'two_way_sync' => false,
            'live_available' => false,
        ];
    }

    public function testConnection(array $settings): array
    {
        $company = trim((string) ($settings['company_name'] ?? ''));

        if ($company === '') {
            return [
                'ok' => false,
                'live_available' => false,
                'message' => 'A company name is required to model the connection.',
            ];
        }

        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'Mock provider connected. No live accounting system is contacted; exports run against dummy data.',
        ];
    }

    public function exportPayments(Organization $organization, array $paymentIds): array
    {
        $prefix = $this->provider === 'mock_xero' ? 'XERO-MOCK-' : 'QB-MOCK-';

        $payments = ClaimPayment::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $paymentIds)
            ->orderBy('created_at')
            ->get();

        $records = [];
        $skipped = 0;

        foreach ($payments as $payment) {
            // A payment with no amount is not a ledger entry:
            // it is skipped and counted, never invented.
            if ($payment->amount === null || (float) $payment->amount <= 0) {
                $skipped++;
                continue;
            }

            $records[] = [
                'external_id' => $prefix.strtoupper(substr(hash('sha256', $payment->getKey()), 0, 12)),
                'claim_payment_id' => $payment->getKey(),
                'claim_id' => $payment->claim_id,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency ?? 'USD',
                'payment_method' => $payment->payment_method?->value,
                'reference' => $payment->payment_reference,
                'recorded_at' => $payment->created_at?->toIso8601String(),
            ];
        }

        return [
            'exported' => count($records),
            'skipped' => $skipped,
            'records' => $records,
        ];
    }
}
