<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\Invoice;
use App\Models\Organization;
use App\Permission;
use App\Services\BillingService;
use App\Services\EntitlementService;
use App\Services\ProrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Subscription billing for one organization (v1.2.0).
 *
 * Everything here is tenant-scoped through the organization
 * header and gated on manage-billing (Owner, Admin, Billing
 * Staff), except the plan catalog, which any member may read.
 * The provider is the mock: checkout is completed in-app, cards
 * are the Stripe test numbers, and no real money moves.
 */
class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected ProrationService $proration,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * The plan catalog: every plan with prices and limits.
     */
    public function plans(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->billing->plans()]);
    }

    /**
     * The current subscription, with the current period's metered
     * usage for the success-fee plan.
     */
    public function subscription(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $subscription = $this->billing->subscriptionFor($organization);

        return response()->json([
            'data' => array_merge(
                $this->billing->subscriptionPayload($subscription),
                ['usage' => $this->billing->usageSummary($organization)],
            ),
        ]);
    }

    /**
     * Open a mock checkout session for a plan purchase.
     */
    public function startCheckout(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'plan' => ['required', 'string'],
            'interval' => ['sometimes', 'string', 'in:monthly,annual'],
            'extra_carriers' => ['sometimes', 'integer', 'min:0', 'max:90'],
        ]);

        $session = $this->billing->startCheckout(
            $organization,
            $validated['plan'],
            $validated['interval'] ?? 'monthly',
            $validated['extra_carriers'] ?? 0,
        );

        return response()->json([
            'data' => [
                'id' => $session->getKey(),
                'provider_session_id' => $session->provider_session_id,
                'plan' => $session->plan,
                'interval' => $session->interval,
                'extra_carriers' => $session->extra_carriers,
                'status' => $session->status,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Complete a checkout session: the mock "customer pays" step.
     */
    public function completeCheckout(Request $request, CheckoutSession $session): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        if ($session->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = $this->billing->completeCheckout(
            $session,
            $validated['payment_method'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->billing->subscriptionPayload($subscription),
        ]);
    }

    /**
     * Start the 30-day no-card trial on the Growth tier.
     */
    public function startTrial(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = $this->billing->startTrial(
            $organization,
            $validated['payment_method'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->billing->subscriptionPayload($subscription),
        ], 201);
    }

    /**
     * Change plan. With preview=true, returns the proration
     * preview without changing anything.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'plan' => ['required', 'string'],
            'interval' => ['sometimes', 'string', 'in:monthly,annual'],
            'extra_carriers' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'preview' => ['sometimes', 'boolean'],
        ]);

        $subscription = $this->billing->subscriptionFor($organization);

        $preview = $this->proration->preview(
            $subscription,
            $validated['plan'],
            $validated['interval'] ?? $subscription->interval,
            $validated['extra_carriers'] ?? $subscription->extra_carriers,
        );

        if ($validated['preview'] ?? false) {
            return response()->json([
                'data' => [
                    'proration' => $preview,
                    'effective' => $preview['is_upgrade'] ? 'immediately' : 'at_period_end',
                    'current_plan' => $subscription->plan,
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                ],
            ]);
        }

        $result = $this->billing->changePlan(
            $organization,
            $validated['plan'],
            $validated['interval'] ?? $subscription->interval,
            $validated['extra_carriers'] ?? $subscription->extra_carriers,
            $request->user(),
        );

        return response()->json([
            'data' => [
                'subscription' => $this->billing->subscriptionPayload($result['subscription']),
                'invoice' => $result['invoice'] !== null
                    ? $this->billing->invoicePayload($result['invoice'])
                    : null,
                'proration' => $result['proration'],
                'effective' => $result['effective'],
            ],
        ]);
    }

    /**
     * Cancel at the end of the current period, or immediately.
     */
    public function cancel(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'immediate' => ['sometimes', 'boolean'],
        ]);

        $subscription = $this->billing->cancelSubscription(
            $organization,
            $validated['immediate'] ?? false,
            $request->user(),
        );

        return response()->json([
            'data' => $this->billing->subscriptionPayload($subscription),
        ]);
    }

    /**
     * Undo a cancel-at-period-end before the period ends.
     */
    public function resume(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $subscription = $this->billing->resumeSubscription($organization, $request->user());

        return response()->json([
            'data' => $this->billing->subscriptionPayload($subscription),
        ]);
    }

    /**
     * Invoice history, newest first.
     */
    public function invoices(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $history = $this->billing->invoiceHistory(
            $organization,
            (int) $request->input('page', 1),
            $this->perPage($request, 15),
        );

        return response()->json([
            'data' => $history['data'],
            'meta' => $history['meta'],
        ]);
    }

    /**
     * One invoice with its lines.
     */
    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $detail = $this->billing->invoiceDetail($organization, $invoice);

        if ($detail === null) {
            abort(404);
        }

        return response()->json(['data' => $detail]);
    }

    /**
     * Pay an open or past-due invoice.
     */
    public function payInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $paid = $this->billing->payInvoice(
            $organization,
            $invoice,
            $validated['payment_method'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->billing->invoicePayload($paid),
        ]);
    }

    /**
     * Store a dummy payment method (mock test card or token).
     */
    public function updatePaymentMethod(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'max:32'],
        ]);

        $subscription = $this->billing->updatePaymentMethod(
            $organization,
            $validated['payment_method'],
            $request->user(),
        );

        return response()->json([
            'data' => $this->billing->subscriptionPayload($subscription),
        ]);
    }

    /**
     * Open a mock billing portal session.
     */
    public function createPortalSession(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        $session = $this->billing->createPortalSession($organization);

        return response()->json(['data' => $session], 201);
    }

    /**
     * The current period's metered usage (success-fee plan).
     */
    public function usage(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageBilling->value, $organization);

        // The success-fee usage records plus the plan
        // entitlements: limits, current usage, and the upgrade
        // that removes each limit (v1.2.1).
        return response()->json(['data' => array_merge(
            $this->billing->usageSummary($organization),
            ['entitlements' => $this->entitlements->summary($organization)],
        )]);
    }
}
