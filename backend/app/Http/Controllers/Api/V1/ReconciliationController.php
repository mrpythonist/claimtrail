<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\Organization;
use App\Models\ReconciliationReport;
use App\PaymentMethod;
use App\Permission;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Financial reconciliation for one organization (v1.5.0).
 *
 * The payment ledger records what the organization says it
 * received against a claim. Any member may read the ledger,
 * balances, summary, and reports. Recording a payment, voiding
 * one, and generating a report need manage-reconciliation
 * (owner, admin, operations manager, dispatcher). Nothing
 * here moves real money: billing runs on a mock provider, so
 * a ledger payment is a record, not a verified transfer.
 */
class ReconciliationController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliation,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Organization-wide totals: requested, approved, paid, and
     * outstanding across every claim with an amount.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reconciliation->orgSummary($this->organization($request)),
        ]);
    }

    /**
     * The payment ledger, newest first.
     *
     * Query: claim_id, payment_method, from (paid_at), to (paid_at).
     * Any member may list.
     */
    public function ledger(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'claim_id' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', Rule::in(PaymentMethod::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $payments = ClaimPayment::forOrganization($organization)
            ->when($validated['claim_id'] ?? null, fn ($query, $claimId) => $query->where('claim_id', $claimId))
            ->when($validated['payment_method'] ?? null, fn ($query, $method) => $query->where('payment_method', $method))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('paid_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('paid_at', '<=', $to))
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $payments->map(fn (ClaimPayment $payment) => $this->reconciliation->paymentPayload($payment))->all(),
        ]);
    }

    /**
     * One ledger payment. Any member may read.
     */
    public function showPayment(Request $request, ClaimPayment $payment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($payment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->reconciliation->paymentPayload($payment),
        ]);
    }

    /**
     * Record a ledger payment against a claim.
     *
     * Partial payments are normal: the claim stays accepted
     * until the paid total reaches the amount due, and only
     * then does it move to Paid through the existing
     * record-payment flow.
     */
    public function recordPayment(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageReconciliation->value, $organization);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->reconciliation->recordPayment($claim, $request->user(), $validated);

        return response()->json([
            'data' => $this->reconciliation->paymentPayload($payment),
            'balance' => $this->reconciliation->balanceFor($claim->fresh()),
        ], 201);
    }

    /**
     * Void a ledger payment, taking its amount back out of the
     * claim's paid total. A payment on a paid or closed claim
     * cannot be voided.
     */
    public function voidPayment(Request $request, ClaimPayment $payment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($payment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageReconciliation->value, $organization);

        $this->reconciliation->voidPayment($payment, $request->user());

        return response()->json([
            'data' => ['voided' => true, 'payment_id' => $payment->getKey()],
        ]);
    }

    /**
     * One claim's balance with its payment history.
     * Any member may read.
     */
    public function claimBalance(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->reconciliation->claimBalancePayload($claim),
        ]);
    }

    /**
     * Generated reconciliation reports, newest first.
     * Any member may list.
     */
    public function reports(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $reports = ReconciliationReport::forOrganization($organization)
            ->orderByDesc('generated_at')
            ->get();

        return response()->json([
            'data' => $reports->map(fn (ReconciliationReport $report) => $this->reconciliation->reportPayload($report))->all(),
        ]);
    }

    /**
     * One generated report. Any member may read.
     */
    public function showReport(Request $request, ReconciliationReport $report): JsonResponse
    {
        $organization = $this->organization($request);

        if ($report->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->reconciliation->reportPayload($report),
        ]);
    }

    /**
     * Generate a reconciliation report for a period.
     *
     * Both dates default to the current calendar month.
     * Generating a report only reads claims and stores the
     * snapshot; it never changes a claim or moves money.
     */
    public function generateReport(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageReconciliation->value, $organization);

        $validated = $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $start = isset($validated['period_start'])
            ? CarbonImmutable::parse($validated['period_start'])->startOfDay()
            : now()->toImmutable()->startOfMonth();
        $end = isset($validated['period_end'])
            ? CarbonImmutable::parse($validated['period_end'])->startOfDay()
            : now()->toImmutable()->endOfMonth()->startOfDay();

        $report = $this->reconciliation->generateReport($organization, $start, $end, $request->user());

        return response()->json([
            'data' => $this->reconciliation->reportPayload($report),
        ], 201);
    }
}
