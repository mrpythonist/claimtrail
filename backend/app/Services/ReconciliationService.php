<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\Organization;
use App\Models\ReconciliationReport;
use App\Models\User;
use App\NotificationType;
use App\PaymentMethod;
use App\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Financial reconciliation (v1.5.0): the claim payment ledger.
 *
 * The ledger records what the organization says it received. It
 * builds on the claim amounts that already exist
 * (requested_amount, accepted_amount, recovered_amount) and on
 * the record-payment flow from ClaimTransitionService; it does
 * not duplicate them, and it never moves real money. Billing
 * runs on a mock provider behind an interface, so no payment
 * here is verified against a bank, Stripe, or any processor.
 *
 * The outstanding rule, enforced here and nowhere else:
 *
 *   due         = accepted_amount ?? requested_amount
 *   paid        = recovered_amount (the authoritative total;
 *                 ledger rows are the detail of that total)
 *   outstanding = max(0, due - paid)
 *
 * accepted_amount is the approved amount. Where no approval is
 * recorded, the requested amount is the amount due. A claim is
 * fully paid when paid reaches due; a partial payment leaves the
 * claim in its accepted state and only the final payment moves
 * it to Paid, through the existing record-payment flow, so the
 * success-fee metering in v1.2.0 still runs exactly once.
 */
class ReconciliationService
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
        protected ClaimTransitionService $transitions,
    ) {}

    /**
     * The balance of one claim: requested, approved, due, paid,
     * outstanding, and the ledger detail behind the paid total.
     *
     * @return array<string, mixed>
     */
    public function balanceFor(Claim $claim): array
    {
        $requested = (string) ($claim->requested_amount ?? '0.00');
        $approved = $claim->accepted_amount !== null ? (string) $claim->accepted_amount : null;
        $due = $approved ?? $requested;
        $paid = (string) ($claim->recovered_amount ?? '0.00');
        $ledgerTotal = (string) $claim->payments()->sum('amount');
        $legacy = bcsub($paid, $ledgerTotal, 2);
        $outstanding = bccomp($paid, $due, 2) >= 0 ? '0.00' : bcsub($due, $paid, 2);

        if (bccomp($due, '0.00', 2) <= 0) {
            $status = 'unpaid';
        } elseif (bccomp($paid, $due, 2) > 0) {
            $status = 'overpaid';
        } elseif (bccomp($paid, $due, 2) === 0) {
            $status = 'paid';
        } elseif (bccomp($paid, '0.00', 2) > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        return [
            'claim_id' => $claim->getKey(),
            'claim_reference' => $claim->claim_reference,
            'status' => $claim->status->value,
            'currency' => $claim->currency,
            'requested_amount' => number_format((float) $requested, 2, '.', ''),
            'approved_amount' => $approved !== null ? number_format((float) $approved, 2, '.', '') : null,
            'due_amount' => number_format((float) $due, 2, '.', ''),
            'paid_amount' => number_format((float) $paid, 2, '.', ''),
            'outstanding_amount' => number_format((float) $outstanding, 2, '.', ''),
            'ledger_amount' => number_format((float) $ledgerTotal, 2, '.', ''),
            'legacy_amount' => number_format((float) $legacy, 2, '.', ''),
            'balance_status' => $status,
        ];
    }

    /**
     * Record a ledger payment against a claim.
     *
     * Only accepted and partially accepted claims can receive
     * payments, the same gate as the record-payment endpoint.
     * A partial payment updates recovered_amount and stays in
     * the accepted state; the payment that reaches the amount
     * due delegates to ClaimTransitionService::recordPayment,
     * which moves the claim to Paid, writes the status history,
     * and meters the success fee exactly once.
     *
     * @param  array<string, mixed>  $data  amount, payment_method, paid_at, payment_reference, notes
     */
    public function recordPayment(Claim $claim, User $actor, array $data): ClaimPayment
    {
        if (! in_array($claim->status, [ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an accepted claim can receive a payment. This claim is '.$claim->status->label().'.',
            ]);
        }

        $amount = $data['amount'];
        $balance = $this->balanceFor($claim->fresh());
        $newTotal = bcadd($balance['paid_amount'], (string) $amount, 2);
        $fullyPaid = bccomp($balance['due_amount'], '0.00', 2) > 0
            && bccomp($newTotal, $balance['due_amount'], 2) >= 0;

        $payment = $this->audit->withoutAuditing(fn () => DB::transaction(function () use ($claim, $actor, $data, $amount, $newTotal) {
            $payment = ClaimPayment::create([
                'organization_id' => $claim->organization_id,
                'claim_id' => $claim->getKey(),
                'amount' => number_format((float) $amount, 2, '.', ''),
                'currency' => $claim->currency,
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_at' => $data['paid_at'],
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->getKey(),
            ]);

            $claim->update([
                'recovered_amount' => $newTotal,
                'payment_reference' => $data['payment_reference'] ?? $claim->payment_reference,
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                $claim->organization,
                'reconciliation.payment_recorded',
                $claim,
                $actor,
                ['recovered_amount' => $claim->getOriginal('recovered_amount')],
                [
                    'recovered_amount' => $newTotal,
                    'currency' => $claim->currency,
                    'payment_method' => $data['payment_method'],
                    'payment_reference' => $data['payment_reference'] ?? null,
                ],
            );

            return $payment;
        }));

        if ($fullyPaid) {
            // The existing flow moves the claim to Paid, writes
            // the status history, audits claim.payment_recorded,
            // meters the success fee, and notifies payment.recorded.
            // recovered_amount is already the new total; passing
            // it again keeps the two records in agreement.
            $this->transitions->recordPayment($claim->fresh(), $actor, [
                'recovered_amount' => $newTotal,
                'payment_date' => $data['paid_at'],
                'payment_reference' => $data['payment_reference'] ?? $claim->payment_reference,
            ]);

            return $payment->fresh();
        }

        $fresh = $claim->fresh();
        $outstanding = $this->balanceFor($fresh)['outstanding_amount'];

        $this->notifications->notify(
            $fresh->organization,
            $this->notifications->membersWithPermission($fresh->organization, Permission::ViewClaims, $actor),
            NotificationType::ReconciliationPaymentRecorded,
            'Partial payment recorded for '.$fresh->claim_reference,
            'A payment of '.$fresh->currency.' '.number_format((float) $amount, 2).' was recorded for claim '.$fresh->claim_reference.'. '.$fresh->currency.' '.number_format((float) $outstanding, 2).' remains outstanding.',
            ['claim_id' => $fresh->getKey(), 'claim_reference' => $fresh->claim_reference, 'payment_id' => $payment->getKey()],
            null,
            $actor,
        );

        return $payment->fresh();
    }

    /**
     * Void a ledger payment: remove the row and take its amount
     * back out of the claim's paid total.
     *
     * A payment on a paid claim cannot be voided: the claim is
     * already settled and possibly metered for the success fee,
     * and Paid only moves to Closed. Record a correcting payment
     * instead by asking the team to fix the record upstream.
     */
    public function voidPayment(ClaimPayment $payment, User $actor): void
    {
        $claim = $payment->claim;

        if ($claim->status === ClaimStatus::Paid || $claim->status === ClaimStatus::Closed) {
            throw ValidationException::withMessages([
                'payment' => 'A payment on a '.$claim->status->label().' claim cannot be voided.',
            ]);
        }

        $this->audit->withoutAuditing(fn () => DB::transaction(function () use ($payment, $claim, $actor) {
            $before = (string) ($claim->recovered_amount ?? '0.00');
            $after = bcsub($before, (string) $payment->amount, 2);

            $payment->delete();

            $claim->update([
                'recovered_amount' => $after,
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                $claim->organization,
                'reconciliation.payment_voided',
                $claim,
                $actor,
                ['recovered_amount' => $before],
                [
                    'recovered_amount' => $after,
                    'currency' => $claim->currency,
                    'voided_payment_id' => $payment->getKey(),
                    'voided_amount' => number_format((float) $payment->amount, 2, '.', ''),
                ],
            );
        }));
    }

    /**
     * Organization-wide totals across every claim with an amount.
     *
     * @return array<string, mixed>
     */
    public function orgSummary(Organization $organization): array
    {
        $claims = Claim::forOrganization($organization)
            ->where(function ($query): void {
                $query->whereNotNull('requested_amount')
                    ->orWhereNotNull('accepted_amount')
                    ->orWhereNotNull('recovered_amount');
            })
            ->get();

        $requested = '0.00';
        $approved = '0.00';
        $paid = '0.00';
        $outstanding = '0.00';
        $byStatus = ['unpaid' => 0, 'partial' => 0, 'paid' => 0, 'overpaid' => 0];
        $currencies = [];

        foreach ($claims as $claim) {
            $balance = $this->balanceFor($claim);
            $requested = bcadd($requested, $balance['requested_amount'], 2);
            $approved = bcadd($approved, $balance['approved_amount'] ?? '0.00', 2);
            $paid = bcadd($paid, $balance['paid_amount'], 2);
            $outstanding = bcadd($outstanding, $balance['outstanding_amount'], 2);
            $byStatus[$balance['balance_status']]++;
            $currencies[$claim->currency] = true;
        }

        return [
            'organization_id' => $organization->getKey(),
            'claims_count' => $claims->count(),
            'requested_amount' => number_format((float) $requested, 2, '.', ''),
            'approved_amount' => number_format((float) $approved, 2, '.', ''),
            'paid_amount' => number_format((float) $paid, 2, '.', ''),
            'outstanding_amount' => number_format((float) $outstanding, 2, '.', ''),
            'by_balance_status' => $byStatus,
            'currencies' => array_keys($currencies),
        ];
    }

    /**
     * Generate a reconciliation report for one organization and
     * period, store it, and notify the team.
     *
     * Scope: claims created in the period, claims with a ledger
     * payment dated in the period, or claims marked paid in the
     * period. Discrepancies are flagged, never silently fixed:
     * overpaid (paid above due), legacy (paid total recorded
     * before the ledger existed), paid but still outstanding,
     * and payments on a claim that was never submitted.
     */
    public function generateReport(
        Organization $organization,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?User $actor = null,
    ): ReconciliationReport {
        $claims = Claim::forOrganization($organization)
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query->whereBetween('created_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
                    ->orWhereBetween('paid_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
                    ->orWhereHas('payments', fn ($payments) => $payments->whereBetween('paid_at', [$periodStart->toDateString(), $periodEnd->toDateString()]));
            })
            ->with('payments')
            ->orderBy('created_at')
            ->get();

        $requested = '0.00';
        $approved = '0.00';
        $paid = '0.00';
        $outstanding = '0.00';
        $paymentsCount = 0;
        $discrepancies = [];

        foreach ($claims as $claim) {
            $balance = $this->balanceFor($claim);
            $requested = bcadd($requested, $balance['requested_amount'], 2);
            $approved = bcadd($approved, $balance['approved_amount'] ?? '0.00', 2);
            $paid = bcadd($paid, $balance['paid_amount'], 2);
            $outstanding = bcadd($outstanding, $balance['outstanding_amount'], 2);
            $paymentsCount += $claim->payments->count();

            if ($balance['balance_status'] === 'overpaid') {
                $discrepancies[] = [
                    'kind' => 'overpaid',
                    'claim_id' => $claim->getKey(),
                    'claim_reference' => $claim->claim_reference,
                    'detail' => 'Paid '.$claim->currency.' '.$balance['paid_amount'].' against '.$claim->currency.' '.$balance['due_amount'].' due.',
                ];
            }

            if (bccomp($balance['legacy_amount'], '0.00', 2) > 0) {
                $discrepancies[] = [
                    'kind' => 'legacy_payment',
                    'claim_id' => $claim->getKey(),
                    'claim_reference' => $claim->claim_reference,
                    'detail' => $claim->currency.' '.$balance['legacy_amount'].' was recorded before the payment ledger existed and has no ledger row.',
                ];
            }

            if ($claim->status === ClaimStatus::Paid && bccomp($balance['outstanding_amount'], '0.00', 2) > 0) {
                $discrepancies[] = [
                    'kind' => 'paid_but_outstanding',
                    'claim_id' => $claim->getKey(),
                    'claim_reference' => $claim->claim_reference,
                    'detail' => 'Claim is Paid but '.$claim->currency.' '.$balance['outstanding_amount'].' is still outstanding.',
                ];
            }

            if ($claim->payments->isNotEmpty() && $claim->status->isPreSubmission()) {
                $discrepancies[] = [
                    'kind' => 'payments_on_unsubmitted',
                    'claim_id' => $claim->getKey(),
                    'claim_reference' => $claim->claim_reference,
                    'detail' => 'Ledger payments exist on a claim that was never submitted.',
                ];
            }
        }

        $report = ReconciliationReport::create([
            'organization_id' => $organization->getKey(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'totals' => [
                'claims_count' => $claims->count(),
                'payments_count' => $paymentsCount,
                'requested_amount' => number_format((float) $requested, 2, '.', ''),
                'approved_amount' => number_format((float) $approved, 2, '.', ''),
                'paid_amount' => number_format((float) $paid, 2, '.', ''),
                'outstanding_amount' => number_format((float) $outstanding, 2, '.', ''),
                'discrepancies_count' => count($discrepancies),
            ],
            'discrepancies' => $discrepancies,
            'generated_by' => $actor?->getKey(),
            'generated_at' => now(),
        ]);

        $this->notifications->notify(
            $organization,
            $this->notifications->membersWithPermission($organization, Permission::ManageReconciliation, $actor),
            NotificationType::ReconciliationCompleted,
            'Reconciliation report ready for '.$periodStart->toDateString().' to '.$periodEnd->toDateString(),
            'Requested '.$report->totals['requested_amount'].', approved '.$report->totals['approved_amount'].', paid '.$report->totals['paid_amount'].', outstanding '.$report->totals['outstanding_amount'].' across '.$report->totals['claims_count'].' claims. '.$report->totals['discrepancies_count'].' discrepancies flagged.',
            ['report_id' => $report->getKey()],
            'reconciliation:'.$organization->getKey().':'.$periodStart->toDateString().':'.$periodEnd->toDateString(),
            $actor,
        );

        return $report;
    }

    /**
     * Run reconciliation for organizations with ledger activity
     * in the current month: one report per organization, skipped
     * when one was already generated today for the same period,
     * so the daily command never spams duplicate reports.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runReconciliation(?Organization $organization = null, ?CarbonImmutable $period = null): array
    {
        $period ??= now()->toImmutable();
        $start = $period->startOfMonth();
        $end = $period->endOfMonth();

        $organizations = $organization !== null
            ? collect([$organization])
            : Organization::whereHas('claims.payments', fn ($payments) => $payments
                ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]))
                ->get();

        $results = [];

        foreach ($organizations as $org) {
            $already = ReconciliationReport::forOrganization($org)
                ->whereDate('period_start', $start->toDateString())
                ->whereDate('period_end', $end->toDateString())
                ->whereDate('generated_at', now()->toDateString())
                ->exists();

            if ($already) {
                $results[] = ['organization_id' => $org->getKey(), 'skipped' => true];

                continue;
            }

            $report = $this->generateReport($org, $start, $end);

            $results[] = [
                'organization_id' => $org->getKey(),
                'skipped' => false,
                'report_id' => $report->getKey(),
                'discrepancies' => $report->totals['discrepancies_count'],
            ];
        }

        return $results;
    }

    /**
     * API shape for one ledger payment.
     *
     * @return array<string, mixed>
     */
    public function paymentPayload(ClaimPayment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'organization_id' => $payment->organization_id,
            'claim_id' => $payment->claim_id,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method->value,
            'payment_method_label' => $payment->payment_method->label(),
            'payment_reference' => $payment->payment_reference,
            'paid_at' => $payment->paid_at->toDateString(),
            'notes' => $payment->notes,
            'recorded_by' => $payment->recorded_by,
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }

    /**
     * API shape for one stored report.
     *
     * @return array<string, mixed>
     */
    public function reportPayload(ReconciliationReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'organization_id' => $report->organization_id,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'totals' => $report->totals,
            'discrepancies' => $report->discrepancies,
            'generated_by' => $report->generated_by,
            'generated_at' => $report->generated_at->toIso8601String(),
            'created_at' => $report->created_at->toIso8601String(),
        ];
    }

    /**
     * API shape for a claim balance with its payment history.
     *
     * @return array<string, mixed>
     */
    public function claimBalancePayload(Claim $claim): array
    {
        $claim->loadMissing('payments');

        return array_merge($this->balanceFor($claim), [
            'payments' => $claim->payments->map(fn (ClaimPayment $payment) => $this->paymentPayload($payment))->all(),
        ]);
    }
}
