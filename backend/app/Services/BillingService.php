<?php

namespace App\Services;

use App\Billing\BillingProvider;
use App\Billing\MockBillingProvider;
use App\Billing\PlanCatalog;
use App\Models\BillingEvent;
use App\Models\BillingUsageRecord;
use App\Models\CheckoutSession;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSequence;
use App\Models\Organization;
use App\Models\PortalSession;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Subscription billing for one organization (v1.2.0).
 *
 * The provider (mock by default) sits behind BillingProvider, so
 * the money movement is provider-shaped but the app's records are
 * the source of truth: subscriptions, invoices, usage records, and
 * webhook events all live in the database. Real Stripe later is a
 * provider swap, not a redesign.
 *
 * Rules, from docs/validation/0.5.0-pricing-validation.md section
 * 9.1 and section 6:
 * - Five plans (Free/Starter/Growth/Fleet/Agency) plus the
 *   success-fee plan as a non-listed alternative. Monthly and
 *   annual billing (annual 10x monthly). Checkout, 30-day no-card
 *   Growth trial, upgrades immediate with proration, downgrades at
 *   period end, renewals, cancellations, invoice history, dunning
 *   (retry, then downgrade to Free after 14 days past due, never
 *   delete data), simulated webhooks, mocked billing portal.
 * - Success-fee plan: $0 base, 15% of amounts recorded as paid,
 *   invoiced monthly in arrears, capped at $49/mo for the first
 *   year, applied in the billing code.
 * - Entitlements and limits enforcement are v1.2.1, not here: the
 *   plan is recorded and displayed, but no creation endpoint reads
 *   it yet.
 */
class BillingService
{
    public function __construct(
        protected BillingProvider $provider,
        protected ProrationService $proration,
        protected AuditService $audit,
    ) {}

    /**
     * The billing provider for the configured driver.
     */
    public function provider(): BillingProvider
    {
        return $this->provider;
    }

    // ------------------------------------------------------------------
    // Subscriptions
    // ------------------------------------------------------------------

    /**
     * The organization's subscription, creating a Free one when the
     * organization has never had billing. An organization always
     * has exactly one subscription row.
     */
    public function subscriptionFor(Organization $organization): Subscription
    {
        $subscription = Subscription::firstOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'plan' => PlanCatalog::FREE,
                'status' => Subscription::STATUS_ACTIVE,
                'interval' => PlanCatalog::INTERVAL_MONTHLY,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'plan_started_at' => now(),
                'provider' => $this->provider->name(),
            ],
        );

        if ($subscription->provider_customer_id === null) {
            $subscription->update([
                'provider_customer_id' => $this->provider->createCustomer($organization),
            ]);
        }

        return $subscription->fresh();
    }

    /**
     * The plan catalog for the API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plans(): array
    {
        return PlanCatalog::all();
    }

    // ------------------------------------------------------------------
    // Checkout and trials
    // ------------------------------------------------------------------

    /**
     * Open a mock checkout session for a plan purchase.
     *
     * Checkout is for moving off Free (or off the success-fee plan,
     * whose base is $0): a paid plan is changed with changePlan,
     * which prorates. A trialing organization checks out to convert
     * the trial early.
     *
     * @throws ValidationException
     */
    public function startCheckout(
        Organization $organization,
        string $plan,
        string $interval,
        int $extraCarriers = 0,
    ): CheckoutSession {
        $this->validatePlanChange($plan, $interval, $extraCarriers);

        $subscription = $this->subscriptionFor($organization);

        if (! in_array($subscription->plan, [PlanCatalog::FREE, PlanCatalog::SUCCESS_FEE], true)
            && $subscription->status !== Subscription::STATUS_TRIALING) {
            throw ValidationException::withMessages([
                'plan' => 'Your organization already has a paid plan. Use change-plan to move between paid plans.',
            ]);
        }

        if ($subscription->status === Subscription::STATUS_PAST_DUE) {
            throw ValidationException::withMessages([
                'plan' => 'Your subscription is past due. Pay the outstanding invoice first.',
            ]);
        }

        $session = $this->provider->createCheckoutSession(
            $organization,
            $subscription->provider_customer_id,
            $plan,
            $interval,
            $extraCarriers,
        );

        return CheckoutSession::create([
            'organization_id' => $organization->getKey(),
            'provider_session_id' => $session['session_id'],
            'plan' => $plan,
            'interval' => $interval,
            'extra_carriers' => $extraCarriers,
            'status' => CheckoutSession::STATUS_OPEN,
            'expires_at' => $session['expires_at'],
        ]);
    }

    /**
     * Complete a checkout session: the mock "customer pays" step.
     *
     * The first period is charged through the provider (except for
     * the Free and success-fee plans, whose base is $0). A failed
     * charge leaves the session open so the buyer can retry with
     * another card. On success the subscription activates, a paid
     * invoice is issued, and a checkout.session.completed event is
     * recorded as delivered through the webhook pipeline.
     *
     * @throws ValidationException
     */
    public function completeCheckout(
        CheckoutSession $session,
        ?string $paymentMethodInput,
        ?User $actor = null,
    ): Subscription {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'checkout_session' => 'This checkout session is no longer open.',
            ]);
        }

        $organization = $session->organization;
        $subscription = $this->subscriptionFor($organization);

        $method = $paymentMethodInput !== null && $paymentMethodInput !== ''
            ? $this->resolvePaymentMethod($paymentMethodInput)
            : null;

        $fullAmount = PlanCatalog::periodCents($session->plan, $session->interval, $session->extra_carriers);
        $creditApplied = min($fullAmount, $subscription->credit_balance_cents);
        $amount = $fullAmount - $creditApplied;

        if ($amount > 0 && $method === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'A payment method is required for this plan.',
            ]);
        }

        // Charge first, outside the transaction: a failed charge
        // touches no database rows, and a successful charge is
        // recorded exactly once by the transaction below.
        $charge = $amount > 0
            ? $this->provider->charge(
                $organization,
                $subscription->provider_customer_id,
                $method['token'],
                $amount,
                "ClaimTrail {$session->plan} ({$session->interval})",
                "checkout:{$session->provider_session_id}",
            )
            : ['success' => true, 'charge_id' => null, 'failure_code' => null, 'failure_message' => null];

        if (! $charge['success']) {
            throw ValidationException::withMessages([
                'payment_method' => $charge['failure_message'] ?? 'Your card was declined.',
            ]);
        }

        return DB::transaction(function () use ($session, $organization, $subscription, $method, $fullAmount, $creditApplied, $amount, $charge, $actor) {
            $now = now();

            $subscription->update([
                'plan' => $session->plan,
                'status' => Subscription::STATUS_ACTIVE,
                'interval' => $session->interval,
                'current_period_start' => $now,
                'current_period_end' => $session->interval === PlanCatalog::INTERVAL_ANNUAL
                    ? $now->copy()->addYear()
                    : $now->copy()->addMonth(),
                'trial_ends_at' => null,
                'plan_started_at' => $now,
                'success_fee_cap_until' => $session->plan === PlanCatalog::SUCCESS_FEE
                    ? $now->copy()->addMonths((int) config('billing.success_fee_cap_months', 12))
                    : null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'pending_plan' => null,
                'pending_interval' => null,
                'pending_extra_carriers' => null,
                'extra_carriers' => $session->extra_carriers,
                'provider_subscription_id' => 'sub_mock_'.Str::random(14),
                'payment_method_token' => $method['token'] ?? $subscription->payment_method_token,
                'payment_method_brand' => $method['brand'] ?? $subscription->payment_method_brand,
                'payment_method_last4' => $method['last4'] ?? $subscription->payment_method_last4,
                'credit_balance_cents' => max(0, $subscription->credit_balance_cents - PlanCatalog::periodCents($session->plan, $session->interval, $session->extra_carriers)),
            ]);

            $lines = [
                [
                    'kind' => InvoiceLine::KIND_SUBSCRIPTION,
                    'description' => PlanCatalog::name($session->plan).' ('.$session->interval.')'
                        .($session->extra_carriers > 0 ? " + {$session->extra_carriers} extra carriers" : ''),
                    'amount_cents' => $fullAmount,
                ],
            ];

            if ($creditApplied > 0) {
                $lines[] = [
                    'kind' => InvoiceLine::KIND_CREDIT_APPLIED,
                    'description' => 'Credit balance applied',
                    'amount_cents' => -$creditApplied,
                ];
            }

            $invoice = $this->createInvoice($organization, $subscription, $lines, [
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'memo' => 'Checkout '.$session->provider_session_id,
                'provider_charge_id' => $charge['charge_id'],
            ]);

            if ($amount > 0) {
                $this->markInvoicePaid($invoice, $charge['charge_id']);
            } else {
                $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $now, 'amount_paid_cents' => 0]);
            }

            $session->update([
                'status' => CheckoutSession::STATUS_COMPLETED,
                'completed_at' => $now,
            ]);

            $this->recordBillingEvent($organization, 'checkout.session.completed', [
                'checkout_session_id' => $session->provider_session_id,
                'plan' => $session->plan,
                'interval' => $session->interval,
                'invoice_id' => $invoice->getKey(),
            ]);

            $this->audit->record(
                $organization,
                'billing.plan_changed',
                $subscription,
                $actor,
                ['plan' => 'free'],
                ['plan' => $session->plan, 'interval' => $session->interval, 'invoice' => $invoice->number],
            );

            return $subscription->fresh();
        });
    }

    /**
     * Start the 30-day no-card trial on the Growth tier.
     *
     * Only from Free. An optional payment method can be stored so
     * the trial can convert to paid at the end; without one, the
     * trial lapses back to Free.
     *
     * @throws ValidationException
     */
    public function startTrial(Organization $organization, ?string $paymentMethodInput, ?User $actor = null): Subscription
    {
        $subscription = $this->subscriptionFor($organization);

        if ($subscription->plan !== PlanCatalog::FREE || $subscription->status !== Subscription::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'trial' => 'Trials are only available from the Free plan.',
            ]);
        }

        $method = $paymentMethodInput !== null && $paymentMethodInput !== ''
            ? $this->resolvePaymentMethod($paymentMethodInput)
            : null;

        $now = now();
        $trialDays = (int) config('billing.trial_days', 30);

        $subscription->update([
            'plan' => PlanCatalog::GROWTH,
            'status' => Subscription::STATUS_TRIALING,
            'interval' => PlanCatalog::INTERVAL_MONTHLY,
            'current_period_start' => $now,
            'current_period_end' => $now->copy()->addDays($trialDays),
            'trial_ends_at' => $now->copy()->addDays($trialDays),
            'plan_started_at' => $now,
            'payment_method_token' => $method['token'] ?? $subscription->payment_method_token,
            'payment_method_brand' => $method['brand'] ?? $subscription->payment_method_brand,
            'payment_method_last4' => $method['last4'] ?? $subscription->payment_method_last4,
        ]);

        $this->recordBillingEvent($organization, 'trial.started', [
            'plan' => PlanCatalog::GROWTH,
            'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
        ]);

        $this->audit->record(
            $organization,
            'billing.trial_started',
            $subscription,
            $actor,
            ['plan' => 'free'],
            ['plan' => PlanCatalog::GROWTH, 'trial_ends_at' => $subscription->trial_ends_at->toIso8601String()],
        );

        return $subscription->fresh();
    }

    // ------------------------------------------------------------------
    // Plan changes, cancellation
    // ------------------------------------------------------------------

    /**
     * Change plan: upgrades apply immediately with proration, a
     * downgrade is scheduled for the next renewal.
     *
     * @return array{subscription: Subscription, invoice: Invoice|null, proration: array<string, mixed>, effective: string}
     *
     * @throws ValidationException
     */
    public function changePlan(
        Organization $organization,
        string $plan,
        string $interval,
        int $extraCarriers = 0,
        ?User $actor = null,
    ): array {
        $this->validatePlanChange($plan, $interval, $extraCarriers);

        $subscription = $this->subscriptionFor($organization);

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            throw ValidationException::withMessages([
                'plan' => 'This subscription is canceled. Start a new checkout to subscribe again.',
            ]);
        }

        if ($subscription->status === Subscription::STATUS_PAST_DUE) {
            throw ValidationException::withMessages([
                'plan' => 'Your subscription is past due. Pay the outstanding invoice first.',
            ]);
        }

        if ($subscription->status === Subscription::STATUS_TRIALING) {
            throw ValidationException::withMessages([
                'plan' => 'You are on a trial. Complete checkout to convert the trial to a paid plan.',
            ]);
        }

        if ($subscription->plan === $plan
            && $subscription->interval === $interval
            && $subscription->extra_carriers === $extraCarriers) {
            throw ValidationException::withMessages([
                'plan' => 'You are already on this plan.',
            ]);
        }

        $preview = $this->proration->preview($subscription, $plan, $interval, $extraCarriers);

        // A downgrade (or a move to a cheaper monthly-equivalent
        // price) is scheduled for the next renewal: the buyer keeps
        // the tier they paid for until the period ends.
        if (! $preview['is_upgrade']) {
            $before = $subscription->only(['plan', 'interval']);

            $subscription->update([
                'pending_plan' => $plan,
                'pending_interval' => $interval,
                'pending_extra_carriers' => $extraCarriers,
            ]);

            $this->audit->record(
                $organization,
                'billing.downgrade_scheduled',
                $subscription,
                $actor,
                $before,
                ['pending_plan' => $plan, 'effective' => $subscription->current_period_end->toIso8601String()],
            );

            return [
                'subscription' => $subscription->fresh(),
                'invoice' => null,
                'proration' => $preview,
                'effective' => 'at_period_end',
            ];
        }

        // An upgrade applies immediately, with proration. Charge
        // first, outside the transaction: a failed charge touches
        // no database rows.
        $net = $preview['net_cents'] - max(0, min($preview['net_cents'], $subscription->credit_balance_cents));
        $net = max(0, $net);

        if ($net > 0 && $subscription->payment_method_token === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'Add a payment method before upgrading.',
            ]);
        }

        $charge = ['success' => true, 'charge_id' => null, 'failure_code' => null, 'failure_message' => null];

        if ($net > 0) {
            $charge = $this->provider->charge(
                $organization,
                $subscription->provider_customer_id,
                $subscription->payment_method_token,
                $net,
                'Plan change to '.PlanCatalog::name($plan),
                "plan-change:{$subscription->getKey()}:{$plan}:{$interval}:{$subscription->current_period_start->format('Ymd')}",
            );

            if (! $charge['success']) {
                throw ValidationException::withMessages([
                    'payment_method' => $charge['failure_message'] ?? 'Your card was declined.',
                ]);
            }
        }

        return DB::transaction(function () use ($organization, $subscription, $plan, $interval, $extraCarriers, $preview, $net, $charge, $actor) {
            $lines = [];

            if ($preview['credit_cents'] > 0) {
                $lines[] = [
                    'kind' => InvoiceLine::KIND_PRORATION_CREDIT,
                    'description' => 'Unused time on '.PlanCatalog::name($subscription->plan)
                        ." ({$preview['remaining_days']} of {$preview['period_days']} days)",
                    'amount_cents' => -$preview['credit_cents'],
                ];
            }

            if ($preview['charge_cents'] > 0) {
                $lines[] = [
                    'kind' => InvoiceLine::KIND_PRORATION_CHARGE,
                    'description' => PlanCatalog::name($plan).' ('.$interval.')'
                        ." ({$preview['remaining_days']} of {$preview['period_days']} days)",
                    'amount_cents' => $preview['charge_cents'],
                ];
            }

            $creditApplied = max(0, min($preview['net_cents'], $subscription->credit_balance_cents));

            if ($creditApplied > 0) {
                $lines[] = [
                    'kind' => InvoiceLine::KIND_CREDIT_APPLIED,
                    'description' => 'Credit balance applied',
                    'amount_cents' => -$creditApplied,
                ];
            }

            $invoice = $this->createInvoice($organization, $subscription, $lines, [
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'memo' => 'Plan change to '.PlanCatalog::name($plan),
                'provider_charge_id' => $charge['charge_id'],
            ]);

            if ($net > 0) {
                $this->markInvoicePaid($invoice, $charge['charge_id']);
            } else {
                $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now(), 'amount_paid_cents' => $invoice->total_cents]);
            }

            $before = $subscription->only(['plan', 'interval', 'extra_carriers']);

            $subscription->update([
                'plan' => $plan,
                'interval' => $interval,
                'extra_carriers' => $extraCarriers,
                'plan_started_at' => now(),
                'success_fee_cap_until' => $plan === PlanCatalog::SUCCESS_FEE
                    ? now()->addMonths((int) config('billing.success_fee_cap_months', 12))
                    : null,
                'pending_plan' => null,
                'pending_interval' => null,
                'pending_extra_carriers' => null,
                'credit_balance_cents' => max(0, $subscription->credit_balance_cents - $preview['net_cents']),
            ]);

            $this->recordBillingEvent($organization, 'customer.subscription.updated', [
                'plan' => $plan,
                'interval' => $interval,
                'invoice_id' => $invoice->getKey(),
            ]);

            $this->audit->record(
                $organization,
                'billing.plan_changed',
                $subscription,
                $actor,
                $before,
                ['plan' => $plan, 'interval' => $interval, 'invoice' => $invoice->number],
            );

            return [
                'subscription' => $subscription->fresh(),
                'invoice' => $invoice->fresh(),
                'proration' => $preview,
                'effective' => 'immediately',
            ];
        });
    }

    /**
     * Cancel: at the end of the current period by default, or
     * immediately. Canceling never deletes data; at period end the
     * subscription becomes Free.
     *
     * @throws ValidationException
     */
    public function cancelSubscription(Organization $organization, bool $immediate = false, ?User $actor = null): Subscription
    {
        $subscription = $this->subscriptionFor($organization);

        if ($subscription->plan === PlanCatalog::FREE && $subscription->status === Subscription::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'subscription' => 'Your organization is already on the Free plan.',
            ]);
        }

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is already canceled.',
            ]);
        }

        if ($immediate) {
            $before = $subscription->only(['plan', 'status']);

            $subscription->update([
                'plan' => PlanCatalog::FREE,
                'status' => Subscription::STATUS_ACTIVE,
                'interval' => PlanCatalog::INTERVAL_MONTHLY,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'trial_ends_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => now(),
                'pending_plan' => null,
                'pending_interval' => null,
                'pending_extra_carriers' => null,
                'extra_carriers' => 0,
                'claims_carryover' => $this->claimsSincePeriodStart($subscription, now()),
            ]);

            $this->recordBillingEvent($organization, 'customer.subscription.deleted', [
                'plan' => $before['plan'],
                'immediate' => true,
            ]);

            $this->audit->record($organization, 'billing.subscription_canceled', $subscription, $actor, $before, ['plan' => 'free']);
        } else {
            $subscription->update(['cancel_at_period_end' => true]);

            $this->audit->record(
                $organization,
                'billing.cancellation_scheduled',
                $subscription,
                $actor,
                ['cancel_at_period_end' => false],
                ['cancel_at_period_end' => true, 'effective' => $subscription->current_period_end->toIso8601String()],
            );
        }

        return $subscription->fresh();
    }

    /**
     * Undo a cancel-at-period-end before the period ends.
     *
     * @throws ValidationException
     */
    public function resumeSubscription(Organization $organization, ?User $actor = null): Subscription
    {
        $subscription = $this->subscriptionFor($organization);

        if (! $subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is not scheduled for cancellation.',
            ]);
        }

        $subscription->update(['cancel_at_period_end' => false]);

        $this->audit->record(
            $organization,
            'billing.cancellation_resumed',
            $subscription,
            $actor,
            ['cancel_at_period_end' => true],
            ['cancel_at_period_end' => false],
        );

        return $subscription->fresh();
    }

    // ------------------------------------------------------------------
    // Payment method and portal
    // ------------------------------------------------------------------

    /**
     * Store a dummy payment method (mock test card or token).
     *
     * @throws ValidationException
     */
    public function updatePaymentMethod(Organization $organization, string $paymentMethodInput, ?User $actor = null): Subscription
    {
        $method = $this->resolvePaymentMethod($paymentMethodInput);
        $subscription = $this->subscriptionFor($organization);

        $subscription->update([
            'payment_method_token' => $method['token'],
            'payment_method_brand' => $method['brand'],
            'payment_method_last4' => $method['last4'],
        ]);

        $this->audit->record(
            $organization,
            'billing.payment_method_updated',
            $subscription,
            $actor,
            null,
            ['brand' => $method['brand'], 'last4' => $method['last4']],
        );

        return $subscription->fresh();
    }

    /**
     * Open a mock billing portal session. The returned URL opens
     * the in-app billing page in portal mode; the token proves the
     * session and expires after 30 minutes.
     *
     * @return array{url: string, token: string, expires_at: string}
     */
    public function createPortalSession(Organization $organization): array
    {
        $subscription = $this->subscriptionFor($organization);

        $session = $this->provider->createPortalSession($organization, $subscription->provider_customer_id);
        $token = $session['session_id'];

        PortalSession::create([
            'organization_id' => $organization->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => $session['expires_at'],
        ]);

        return [
            'url' => $session['url'],
            'token' => $token,
            'expires_at' => $session['expires_at']->toIso8601String(),
        ];
    }

    /**
     * Validate a portal token and return the billing summary it
     * opens. Returns null for an unknown or expired token.
     *
     * @return array<string, mixed>|null
     */
    public function portalSummary(string $token): ?array
    {
        $session = PortalSession::where('token_hash', hash('sha256', $token))->first();

        if ($session === null || ! $session->isValid()) {
            return null;
        }

        $session->update(['used_at' => now()]);

        $organization = $session->organization;
        $subscription = $this->subscriptionFor($organization);

        return [
            'organization' => ['id' => $organization->getKey(), 'name' => $organization->name],
            'subscription' => $this->subscriptionPayload($subscription),
            'invoices' => $this->invoicePayloads($organization, 5),
            'portal_session_expires_at' => $session->expires_at->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    /**
     * Invoice history for the organization, newest first.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function invoiceHistory(Organization $organization, int $page = 1, int $perPage = 15): array
    {
        $paginator = Invoice::where('organization_id', $organization->getKey())
            ->with('lines')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(fn (Invoice $invoice) => $this->invoicePayload($invoice), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * One invoice with its lines, or null when it belongs to
     * another organization.
     */
    public function invoiceDetail(Organization $organization, Invoice $invoice): ?array
    {
        if ($invoice->organization_id !== $organization->getKey()) {
            return null;
        }

        $invoice->load('lines');

        return $this->invoicePayload($invoice);
    }

    /**
     * Pay an open or past-due invoice with the stored payment
     * method (or one supplied now). A paid past-due invoice
     * returns the subscription to active.
     *
     * @throws ValidationException
     */
    public function payInvoice(
        Organization $organization,
        Invoice $invoice,
        ?string $paymentMethodInput,
        ?User $actor = null,
    ): Invoice {
        if ($invoice->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice not found.',
            ]);
        }

        if (! in_array($invoice->status, [Invoice::STATUS_OPEN, Invoice::STATUS_PAST_DUE], true)) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice cannot be paid in its current state.',
            ]);
        }

        $subscription = $this->subscriptionFor($organization);

        $method = $paymentMethodInput !== null && $paymentMethodInput !== ''
            ? $this->resolvePaymentMethod($paymentMethodInput)
            : null;

        $token = $method['token'] ?? $subscription->payment_method_token;

        if ($token === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'Add a payment method before paying this invoice.',
            ]);
        }

        $amount = $invoice->total_cents - $invoice->amount_paid_cents;

        $charge = $this->provider->charge(
            $organization,
            $subscription->provider_customer_id,
            $token,
            $amount,
            'Invoice '.$invoice->number,
            "invoice:{$invoice->getKey()}",
        );

        if (! $charge['success']) {
            $invoice->update([
                'status' => Invoice::STATUS_PAST_DUE,
                'payment_attempts' => $invoice->payment_attempts + 1,
                'next_retry_at' => now()->addDays((int) (config('billing.dunning_retry_delays_days')[0] ?? 3)),
                'failure_code' => $charge['failure_code'],
                'failure_message' => $charge['failure_message'],
            ]);

            if ($subscription->status === Subscription::STATUS_ACTIVE) {
                $subscription->update(['status' => Subscription::STATUS_PAST_DUE]);
            }

            $this->recordBillingEvent($organization, 'invoice.payment_failed', [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->number,
            ]);

            throw ValidationException::withMessages([
                'payment_method' => $charge['failure_message'] ?? 'Your card was declined.',
            ]);
        }

        $this->markInvoicePaid($invoice->fresh(), $charge['charge_id']);

        if ($subscription->status === Subscription::STATUS_PAST_DUE) {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        if ($method !== null) {
            $subscription->update([
                'payment_method_token' => $method['token'],
                'payment_method_brand' => $method['brand'],
                'payment_method_last4' => $method['last4'],
            ]);
        }

        $this->recordBillingEvent($organization, 'invoice.paid', [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
        ]);

        $this->audit->record($organization, 'billing.invoice_paid', $subscription, $actor, null, ['invoice' => $invoice->number]);

        return $invoice->fresh();
    }

    /**
     * Refund part or all of a paid invoice (v1.2.3).
     *
     * The amount defaults to everything still refundable
     * (paid minus already refunded). The reason is required:
     * until Aqib publishes a written refund policy, refunds are
     * issued case by case, and the reason is the record of why.
     * The provider is asked first, with an idempotency key tied
     * to the refund row, so a retry never refunds twice. A
     * provider failure is recorded on the row and reported; the
     * invoice is untouched. A success updates the invoice's
     * refunded total and status, records a billing event, and
     * writes an audit row.
     *
     * A refund does not change metered success-fee usage: the fee
     * is on claim payments recorded as paid, not on subscription
     * invoices, so refunding a fee invoice is just money back.
     *
     * @throws ValidationException
     */
    public function refundInvoice(
        Organization $organization,
        Invoice $invoice,
        ?int $amountCents,
        string $reason,
        ?User $actor = null,
    ): Refund {
        if ($invoice->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice not found.',
            ]);
        }

        if (! in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_PARTIALLY_REFUNDED], true)) {
            throw ValidationException::withMessages([
                'invoice' => 'Only a paid invoice can be refunded.',
            ]);
        }

        $remaining = $invoice->amount_paid_cents - $invoice->amount_refunded_cents;
        $amount = $amountCents ?? $remaining;

        if ($amount <= 0 || $amount > $remaining) {
            throw ValidationException::withMessages([
                'amount_cents' => 'The refund amount must be between 1 and the amount still refundable.',
            ]);
        }

        $subscription = $this->subscriptionFor($organization);

        $refund = DB::transaction(function () use ($organization, $invoice, $amount, $reason, $actor, $subscription) {
            $refund = Refund::create([
                'organization_id' => $organization->getKey(),
                'invoice_id' => $invoice->getKey(),
                'requested_by' => $actor?->getKey(),
                'amount_cents' => $amount,
                'reason' => $reason,
                'status' => Refund::STATUS_PENDING,
            ]);

            $result = $this->provider->refund(
                $organization,
                $subscription->provider_customer_id,
                $invoice->provider_charge_id,
                $amount,
                $reason,
                "refund:{$refund->getKey()}",
            );

            if (! $result['success']) {
                $refund->update([
                    'status' => Refund::STATUS_FAILED,
                    'failure_code' => $result['failure_code'],
                    'failure_message' => $result['failure_message'],
                ]);

                return $refund;
            }

            $refund->update([
                'status' => Refund::STATUS_SUCCEEDED,
                'provider_refund_id' => $result['refund_id'],
            ]);

            $refundedTotal = $invoice->amount_refunded_cents + $amount;

            $invoice->update([
                'amount_refunded_cents' => $refundedTotal,
                'status' => $refundedTotal >= $invoice->amount_paid_cents
                    ? Invoice::STATUS_REFUNDED
                    : Invoice::STATUS_PARTIALLY_REFUNDED,
            ]);

            $this->recordBillingEvent($organization, 'invoice.refunded', [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->number,
                'refund_id' => $refund->getKey(),
                'amount_cents' => $amount,
            ]);

            $this->audit->record(
                $organization,
                'billing.invoice_refunded',
                $invoice,
                $actor,
                ['amount_refunded_cents' => $invoice->amount_refunded_cents],
                ['amount_refunded_cents' => $refundedTotal, 'refund_id' => $refund->getKey()],
            );

            return $refund;
        });

        if ($refund->status === Refund::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'refund' => $refund->failure_message ?? 'The refund failed.',
            ]);
        }

        return $refund->fresh();
    }

    /**
     * Billing events for one organization, newest first: every
     * checkout, renewal, payment, failure, dunning step, refund,
     * and cancellation the service recorded. This is the
     * subscription administration view: an admin can see what
     * happened to the subscription without asking the operator.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function billingEvents(Organization $organization, int $page = 1, int $perPage = 25): array
    {
        $paginator = BillingEvent::where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(fn (BillingEvent $event) => [
                'id' => $event->getKey(),
                'type' => $event->type,
                'status' => $event->status,
                'payload' => $event->payload,
                'error_message' => $event->error_message,
                'created_at' => $event->created_at->toIso8601String(),
            ], $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * One refund as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function refundPayload(Refund $refund): array
    {
        return [
            'id' => $refund->getKey(),
            'invoice_id' => $refund->invoice_id,
            'amount_cents' => $refund->amount_cents,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'provider_refund_id' => $refund->provider_refund_id,
            'failure_message' => $refund->failure_message,
            'created_at' => $refund->created_at->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------
    // Metered usage (success-fee plan)
    // ------------------------------------------------------------------

    /**
     * Record metered usage for a payment recorded on a claim.
     *
     * Called from ClaimTransitionService::recordPayment, the single
     * place a claim is marked paid. Only the success-fee plan is
     * metered, and only while its subscription is usable. The fee
     * is 15% of the cash recorded as paid; the $49/mo first-year
     * cap is applied at invoice time, not here, so the fee stays
     * auditable line by line. Returns null when nothing was
     * metered (wrong plan, attribution window passed, or the claim
     * was already metered).
     */
    public function recordUsageForPayment(Claim $claim): ?BillingUsageRecord
    {
        $organization = $claim->organization;
        $subscription = $this->subscriptionFor($organization);

        if ($subscription->plan !== PlanCatalog::SUCCESS_FEE || ! $subscription->isUsable()) {
            return null;
        }

        if (BillingUsageRecord::where('claim_id', $claim->getKey())->exists()) {
            return null;
        }

        // Attribution: the fee applies to payments recorded against
        // claims created in ClaimTrail within 12 months of the
        // claim's creation (v0.5.0 section 6.3 condition 2).
        $attributionMonths = (int) config('billing.success_fee_attribution_months', 12);

        if ($claim->created_at->lt(now()->subMonths($attributionMonths))) {
            return null;
        }

        $amountCents = (int) round((float) $claim->recovered_amount * 100);
        $feeCents = (int) round($amountCents * (float) config('billing.success_fee_rate', 0.15));

        return BillingUsageRecord::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'claim_id' => $claim->getKey(),
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Usage summary for the current period: uninvoiced records,
     * the accrued fee, and how much of the first-year cap remains.
     *
     * @return array<string, mixed>
     */
    public function usageSummary(Organization $organization): array
    {
        $subscription = $this->subscriptionFor($organization);

        $records = BillingUsageRecord::where('organization_id', $organization->getKey())
            ->whereNull('invoiced_at')
            ->orderBy('recorded_at')
            ->get();

        $accruedFee = $records->sum('fee_cents');
        $capCents = $subscription->successFeeCapped()
            ? (int) config('billing.success_fee_monthly_cap_cents', 4900)
            : null;

        return [
            'plan' => $subscription->plan,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'records' => $records->map(fn (BillingUsageRecord $record) => [
                'id' => $record->getKey(),
                'claim_id' => $record->claim_id,
                'amount_cents' => $record->amount_cents,
                'fee_cents' => $record->fee_cents,
                'recorded_at' => $record->recorded_at->toIso8601String(),
            ])->all(),
            'recovered_cents' => $records->sum('amount_cents'),
            'accrued_fee_cents' => $accruedFee,
            'cap_cents' => $capCents,
            'cap_remaining_cents' => $capCents !== null ? max(0, $capCents - $accruedFee) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Renewals and dunning (scheduled commands)
    // ------------------------------------------------------------------

    /**
     * Renew every subscription whose period has ended.
     *
     * For each due subscription: trials convert to paid (or lapse
     * to Free without a payment method), cancellations at period
     * end become Free, pending downgrades apply, paid plans are
     * charged for the next period, and success-fee plans get their
     * monthly arrears invoice. A failed charge moves the
     * subscription to past due and starts dunning.
     *
     * @return array{renewed: int, trials_converted: int, trials_lapsed: int, canceled: int, downgrades_applied: int, metered_invoiced: int, failed: int}
     */
    public function renewDue(?CarbonInterface $now = null): array
    {
        $now ??= now();

        $counts = [
            'renewed' => 0,
            'trials_converted' => 0,
            'trials_lapsed' => 0,
            'canceled' => 0,
            'downgrades_applied' => 0,
            'metered_invoiced' => 0,
            'failed' => 0,
        ];

        $due = Subscription::whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->where('current_period_end', '<=', $now)
            ->orderBy('current_period_end')
            ->get();

        foreach ($due as $subscription) {
            $result = DB::transaction(function () use ($subscription, $now) {
                return $this->renewOne($subscription, $now);
            });

            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Renew one subscription. Returns the count key to increment.
     */
    protected function renewOne(Subscription $subscription, CarbonInterface $now): string
    {
        $organization = $subscription->organization;

        // A trial converts to paid when a payment method is stored;
        // without one it lapses back to Free (v0.5.0 section 7: the
        // trial needs no card).
        if ($subscription->status === Subscription::STATUS_TRIALING) {
            if ($subscription->payment_method_token === null) {
                $this->lapseTrialToFree($subscription, $now);

                return 'trials_lapsed';
            }

            $amount = PlanCatalog::periodCents($subscription->plan, $subscription->interval, $subscription->extra_carriers);

            $charge = $this->provider->charge(
                $organization,
                $subscription->provider_customer_id,
                $subscription->payment_method_token,
                $amount,
                'Trial conversion: '.PlanCatalog::name($subscription->plan),
                "trial-convert:{$subscription->getKey()}:{$subscription->current_period_end->format('Ymd')}",
            );

            if (! $charge['success']) {
                $this->lapseTrialToFree($subscription, $now);

                return 'trials_lapsed';
            }

            $invoice = $this->createInvoice($organization, $subscription, [
                [
                    'kind' => InvoiceLine::KIND_SUBSCRIPTION,
                    'description' => PlanCatalog::name($subscription->plan).' ('.$subscription->interval.')',
                    'amount_cents' => $amount,
                ],
            ], [
                'period_start' => $now,
                'period_end' => $this->nextPeriodEnd($subscription->interval, $now),
                'memo' => 'Trial conversion',
                'provider_charge_id' => $charge['charge_id'],
            ]);

            $this->markInvoicePaid($invoice, $charge['charge_id']);

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => $now,
                'current_period_end' => $this->nextPeriodEnd($subscription->interval, $now),
                'trial_ends_at' => null,
                'claims_carryover' => 0,
            ]);

            $this->recordBillingEvent($organization, 'trial.converted', [
                'plan' => $subscription->plan,
                'invoice_id' => $invoice->getKey(),
            ]);

            return 'trials_converted';
        }

        // A cancellation scheduled for period end becomes Free.
        if ($subscription->cancel_at_period_end) {
            $this->applyFreePlan($subscription, $now);
            $subscription->update(['canceled_at' => $now]);

            $this->recordBillingEvent($organization, 'customer.subscription.deleted', [
                'plan' => 'free',
                'at_period_end' => true,
            ]);

            return 'canceled';
        }

        // A pending downgrade applies now, charged at the new price.
        if ($subscription->hasPendingDowngrade()) {
            $counts = $this->applyPendingDowngrade($subscription, $now);

            return $counts;
        }

        // The success-fee plan has no base charge: invoice the
        // metered usage for the ended period in arrears.
        if ($subscription->plan === PlanCatalog::SUCCESS_FEE) {
            $invoice = $this->invoiceMeteredUsage(
                $subscription,
                $subscription->current_period_start,
                $subscription->current_period_end,
            );

            $subscription->update([
                'current_period_start' => $now,
                'current_period_end' => $this->nextPeriodEnd($subscription->interval, $now),
                'claims_carryover' => 0,
            ]);

            return $invoice !== null ? 'metered_invoiced' : 'renewed';
        }

        // A paid plan renews: charge the period amount, less any
        // credit balance, and extend the period.
        $amount = PlanCatalog::periodCents($subscription->plan, $subscription->interval, $subscription->extra_carriers);
        $creditApplied = min($amount, $subscription->credit_balance_cents);
        $amount -= $creditApplied;

        if ($amount > 0 && $subscription->payment_method_token === null) {
            // A paid plan with no payment method cannot renew: the
            // invoice goes past due and dunning takes over.
            $invoice = $this->createInvoice($organization, $subscription, [
                [
                    'kind' => InvoiceLine::KIND_SUBSCRIPTION,
                    'description' => PlanCatalog::name($subscription->plan).' ('.$subscription->interval.')',
                    'amount_cents' => $amount,
                ],
            ], [
                'period_start' => $now,
                'period_end' => $this->nextPeriodEnd($subscription->interval, $now),
                'memo' => 'Renewal',
                'failure_code' => 'no_payment_method',
                'failure_message' => 'No payment method on file.',
            ]);

            $this->markInvoicePastDue($invoice, $subscription, 'no_payment_method', 'No payment method on file.');

            return 'failed';
        }

        $charge = $amount > 0
            ? $this->provider->charge(
                $organization,
                $subscription->provider_customer_id,
                $subscription->payment_method_token,
                $amount,
                'Renewal: '.PlanCatalog::name($subscription->plan),
                "renewal:{$subscription->getKey()}:{$subscription->current_period_end->format('Ymd')}",
            )
            : ['success' => true, 'charge_id' => null, 'failure_code' => null, 'failure_message' => null];

        $lines = [
            [
                'kind' => InvoiceLine::KIND_SUBSCRIPTION,
                'description' => PlanCatalog::name($subscription->plan).' ('.$subscription->interval.')'
                    .($subscription->extra_carriers > 0 ? " + {$subscription->extra_carriers} extra carriers" : ''),
                'amount_cents' => PlanCatalog::periodCents($subscription->plan, $subscription->interval, $subscription->extra_carriers),
            ],
        ];

        if ($creditApplied > 0) {
            $lines[] = [
                'kind' => InvoiceLine::KIND_CREDIT_APPLIED,
                'description' => 'Credit balance applied',
                'amount_cents' => -$creditApplied,
            ];
        }

        $invoice = $this->createInvoice($organization, $subscription, $lines, [
            'period_start' => $now,
            'period_end' => $this->nextPeriodEnd($subscription->interval, $now),
            'memo' => 'Renewal',
            'provider_charge_id' => $charge['charge_id'] ?? null,
        ]);

        if (! $charge['success']) {
            $this->markInvoicePastDue($invoice, $subscription, $charge['failure_code'], $charge['failure_message']);

            return 'failed';
        }

        $this->markInvoicePaid($invoice, $charge['charge_id']);

        $subscription->update([
            'current_period_start' => $now,
            'current_period_end' => $this->nextPeriodEnd($subscription->interval, $now),
            'credit_balance_cents' => max(0, $subscription->credit_balance_cents - $creditApplied),
            'claims_carryover' => 0,
        ]);

        $this->recordBillingEvent($organization, 'invoice.paid', [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'renewal' => true,
        ]);

        return 'renewed';
    }

    /**
     * Apply a scheduled downgrade at renewal, charging the new
     * (lower) price for the next period.
     */
    protected function applyPendingDowngrade(Subscription $subscription, CarbonInterface $now): string
    {
        $organization = $subscription->organization;
        $newPlan = $subscription->pending_plan;
        $newInterval = $subscription->pending_interval ?? $subscription->interval;
        $newExtra = $subscription->pending_extra_carriers ?? 0;

        // Claims created since the old period began carry into the
        // new period: a downgrade must block new claims above the
        // lower limit (v0.5.0 section 9.2), and the renewal starts
        // a fresh period, so without the carryover the old claims
        // would stop counting the moment the downgrade applies.
        $carryover = $this->claimsSincePeriodStart($subscription, $now);

        $amount = PlanCatalog::periodCents($newPlan, $newInterval, $newExtra);
        $creditApplied = min($amount, $subscription->credit_balance_cents);
        $amount -= $creditApplied;

        $charge = ['success' => true, 'charge_id' => null, 'failure_code' => null, 'failure_message' => null];

        if ($amount > 0 && $subscription->payment_method_token !== null) {
            $charge = $this->provider->charge(
                $organization,
                $subscription->provider_customer_id,
                $subscription->payment_method_token,
                $amount,
                'Downgrade to '.PlanCatalog::name($newPlan),
                "downgrade:{$subscription->getKey()}:{$subscription->current_period_end->format('Ymd')}",
            );
        }

        $lines = [
            [
                'kind' => InvoiceLine::KIND_SUBSCRIPTION,
                'description' => PlanCatalog::name($newPlan).' ('.$newInterval.')'
                    .($newExtra > 0 ? " + {$newExtra} extra carriers" : ''),
                'amount_cents' => PlanCatalog::periodCents($newPlan, $newInterval, $newExtra),
            ],
        ];

        if ($creditApplied > 0) {
            $lines[] = [
                'kind' => InvoiceLine::KIND_CREDIT_APPLIED,
                'description' => 'Credit balance applied',
                'amount_cents' => -$creditApplied,
            ];
        }

        $invoice = $this->createInvoice($organization, $subscription, $lines, [
            'period_start' => $now,
            'period_end' => $this->nextPeriodEnd($newInterval, $now),
            'memo' => 'Downgrade to '.PlanCatalog::name($newPlan),
            'provider_charge_id' => $charge['charge_id'] ?? null,
        ]);

        if (! $charge['success'] || ($amount > 0 && $subscription->payment_method_token === null)) {
            $this->markInvoicePastDue(
                $invoice,
                $subscription,
                $charge['failure_code'] ?? 'no_payment_method',
                $charge['failure_message'] ?? 'No payment method on file.',
            );

            return 'failed';
        }

        $this->markInvoicePaid($invoice, $charge['charge_id']);

        $subscription->update([
            'plan' => $newPlan,
            'interval' => $newInterval,
            'extra_carriers' => $newExtra,
            'plan_started_at' => $now,
            'success_fee_cap_until' => $newPlan === PlanCatalog::SUCCESS_FEE
                ? $now->copy()->addMonths((int) config('billing.success_fee_cap_months', 12))
                : null,
            'current_period_start' => $now,
            'current_period_end' => $this->nextPeriodEnd($newInterval, $now),
            'pending_plan' => null,
            'pending_interval' => null,
            'pending_extra_carriers' => null,
            'credit_balance_cents' => max(0, $subscription->credit_balance_cents - $creditApplied),
            'claims_carryover' => $carryover,
        ]);

        $this->recordBillingEvent($organization, 'customer.subscription.updated', [
            'plan' => $newPlan,
            'interval' => $newInterval,
            'downgrade_applied' => true,
        ]);

        return 'downgrades_applied';
    }

    /**
     * Invoice the metered success-fee usage for a closed period.
     *
     * Every uninvoiced usage record is collected: records are
     * only ever recorded during the period being closed, because
     * the period advances at renewal, so the uninvoiced set is
     * exactly the closed period's usage. One line per usage
     * record (claim reference, recovered amount, fee), plus a
     * cap-adjustment line when the first-year $49/mo cap bites.
     * Returns null when there is nothing to invoice: an empty
     * arrears invoice would be noise.
     */
    public function invoiceMeteredUsage(
        Subscription $subscription,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ?Invoice {
        $organization = $subscription->organization;

        $records = BillingUsageRecord::where('organization_id', $organization->getKey())
            ->where('subscription_id', $subscription->getKey())
            ->whereNull('invoiced_at')
            ->orderBy('recorded_at')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $totalFee = $records->sum('fee_cents');
        $capCents = $subscription->successFeeCapped()
            ? (int) config('billing.success_fee_monthly_cap_cents', 4900)
            : null;

        $lines = $records->map(fn (BillingUsageRecord $record) => [
            'kind' => InvoiceLine::KIND_SUCCESS_FEE,
            'description' => 'Success fee on '.$record->claim->claim_reference
                .' ('.number_format($record->amount_cents / 100, 2).' recovered)',
            'amount_cents' => $record->fee_cents,
            'metadata' => ['claim_id' => $record->claim_id, 'amount_cents' => $record->amount_cents],
        ])->all();

        if ($capCents !== null && $totalFee > $capCents) {
            $lines[] = [
                'kind' => InvoiceLine::KIND_CAP_ADJUSTMENT,
                'description' => 'First-year success-fee cap ($49/mo)',
                'amount_cents' => -($totalFee - $capCents),
            ];
        }

        $invoice = $this->createInvoice($organization, $subscription, $lines, [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'memo' => 'Success-fee arrears',
        ]);

        $records->each(fn (BillingUsageRecord $record) => $record->update([
            'invoiced_at' => now(),
            'invoice_id' => $invoice->getKey(),
        ]));

        $this->recordBillingEvent($organization, 'invoice.created', [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'metered' => true,
        ]);

        return $invoice;
    }

    /**
     * Dunning: retry past-due invoices whose retry time has come,
     * and downgrade to Free any organization 14+ days past due.
     *
     * Retries run at the configured delays (3 and 7 days after the
     * failure). The downgrade never deletes data: the subscription
     * becomes Free and the invoice is written off as uncollectible.
     *
     * @return array{retried: int, paid: int, downgraded: int}
     */
    public function processDunning(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $delays = config('billing.dunning_retry_delays_days', [3, 7]);
        $downgradeDays = (int) config('billing.dunning_past_due_downgrade_days', 14);

        $counts = ['retried' => 0, 'paid' => 0, 'downgraded' => 0];

        $pastDue = Invoice::where('status', Invoice::STATUS_PAST_DUE)
            ->where('next_retry_at', '<=', $now)
            ->orderBy('due_date')
            ->get();

        foreach ($pastDue as $invoice) {
            DB::transaction(function () use ($invoice, $now, $delays, $downgradeDays, &$counts) {
                $organization = $invoice->organization;
                $subscription = $this->subscriptionFor($organization);

                // 14+ days past due: downgrade to Free and write
                // the invoice off. No more retries, no data deleted.
                if ($invoice->due_date !== null && $invoice->due_date->diffInDays($now) >= $downgradeDays) {
                    $this->applyFreePlan($subscription, $now);

                    $invoice->update([
                        'status' => Invoice::STATUS_UNCOLLECTIBLE,
                        'next_retry_at' => null,
                    ]);

                    $this->recordBillingEvent($organization, 'dunning.downgraded', [
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoice->number,
                    ]);

                    $this->audit->record(
                        $organization,
                        'billing.dunning_downgraded',
                        $subscription,
                        null,
                        ['plan' => $subscription->plan],
                        ['plan' => 'free', 'invoice' => $invoice->number],
                    );

                    $counts['downgraded']++;

                    return;
                }

                // Out of retries: wait for the downgrade date.
                if ($invoice->payment_attempts > count($delays)) {
                    $invoice->update(['next_retry_at' => null]);

                    return;
                }

                $counts['retried']++;

                $amount = $invoice->total_cents - $invoice->amount_paid_cents;

                if ($subscription->payment_method_token === null || $amount <= 0) {
                    $invoice->update([
                        'payment_attempts' => $invoice->payment_attempts + 1,
                        'next_retry_at' => $now->copy()->addDays($delays[min($invoice->payment_attempts, count($delays) - 1)]),
                    ]);

                    return;
                }

                $charge = $this->provider->charge(
                    $organization,
                    $subscription->provider_customer_id,
                    $subscription->payment_method_token,
                    $amount,
                    'Retry: Invoice '.$invoice->number,
                    "dunning:{$invoice->getKey()}:{$invoice->payment_attempts}",
                );

                if (! $charge['success']) {
                    $invoice->update([
                        'payment_attempts' => $invoice->payment_attempts + 1,
                        'next_retry_at' => $now->copy()->addDays($delays[min($invoice->payment_attempts, count($delays) - 1)]),
                        'failure_code' => $charge['failure_code'],
                        'failure_message' => $charge['failure_message'],
                    ]);

                    $this->recordBillingEvent($organization, 'invoice.payment_failed', [
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoice->number,
                        'retry' => true,
                    ]);

                    return;
                }

                $this->markInvoicePaid($invoice, $charge['charge_id']);
                $subscription->update(['status' => Subscription::STATUS_ACTIVE]);

                $this->recordBillingEvent($organization, 'invoice.paid', [
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoice->number,
                    'retry' => true,
                ]);

                $counts['paid']++;
            });
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // Webhooks
    // ------------------------------------------------------------------

    /**
     * Handle one provider webhook event, idempotently.
     *
     * A redelivered event (same provider_event_id) is recorded as
     * a duplicate and never applied twice. Unknown types are kept
     * as unprocessed so an operator can see them.
     */
    public function handleWebhookEvent(array $event): BillingEvent
    {
        $providerEventId = (string) ($event['id'] ?? 'evt_mock_'.Str::random(16));
        $type = (string) ($event['type'] ?? 'unknown');
        $data = $event['data'] ?? [];

        $existing = BillingEvent::where('provider_event_id', $providerEventId)->first();

        if ($existing !== null) {
            $existing->update(['status' => BillingEvent::STATUS_DUPLICATE]);

            return $existing->fresh();
        }

        $organizationId = $this->organizationIdFromEvent($data);

        $record = BillingEvent::create([
            'organization_id' => $organizationId,
            'type' => $type,
            'provider_event_id' => $providerEventId,
            'payload' => $event,
            'status' => BillingEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        try {
            $applied = match ($type) {
                'checkout.session.completed' => $this->applyCheckoutCompleted($data),
                'invoice.paid' => $this->applyInvoicePaid($data),
                'invoice.payment_failed' => $this->applyInvoicePaymentFailed($data),
                'customer.subscription.updated' => $this->applySubscriptionUpdated($data),
                'customer.subscription.deleted' => $this->applySubscriptionDeleted($data),
                default => false,
            };

            if (! $applied) {
                $record->update(['status' => BillingEvent::STATUS_UNPROCESSED]);
            }
        } catch (\Throwable $exception) {
            $record->update([
                'status' => BillingEvent::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $record->fresh();
    }

    /**
     * Fire a mock webhook event through the normal pipeline:
     * sign it, verify the signature, then handle it. Used by the
     * simulate endpoint and by tests.
     */
    public function simulateWebhook(string $type, array $data, ?Organization $organization = null): BillingEvent
    {
        $event = [
            'id' => 'evt_mock_'.Str::random(16),
            'type' => $type,
            'data' => $data,
        ];

        $payload = json_encode($event);
        $signature = $this->provider->signWebhookPayload($payload, (string) config('billing.webhook_secret'));

        if (! $this->provider->verifyWebhookSignature($payload, $signature, (string) config('billing.webhook_secret'))) {
            throw ValidationException::withMessages([
                'webhook' => 'Webhook signature verification failed.',
            ]);
        }

        return $this->handleWebhookEvent($event);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Validate a plan change request: known plan, known interval,
     * extra carriers only on Agency, within range.
     *
     * @throws ValidationException
     */
    protected function validatePlanChange(string $plan, string $interval, int $extraCarriers): void
    {
        if (! PlanCatalog::exists($plan)) {
            throw ValidationException::withMessages([
                'plan' => 'Unknown plan.',
            ]);
        }

        if (! in_array($interval, [PlanCatalog::INTERVAL_MONTHLY, PlanCatalog::INTERVAL_ANNUAL], true)) {
            throw ValidationException::withMessages([
                'interval' => 'Interval must be monthly or annual.',
            ]);
        }

        if ($extraCarriers < 0 || $extraCarriers > 90) {
            throw ValidationException::withMessages([
                'extra_carriers' => 'Extra carriers must be between 0 and 90.',
            ]);
        }

        if ($extraCarriers > 0 && $plan !== PlanCatalog::AGENCY) {
            throw ValidationException::withMessages([
                'extra_carriers' => 'Extra carriers only apply to the Agency plan.',
            ]);
        }
    }

    /**
     * Resolve a payment method input to a stored method. The mock
     * accepts its tokens and the Stripe test card numbers; a real
     * provider would accept its own payment method ids here.
     *
     * @return array{token: string, brand: string, last4: string, succeeds: bool}
     *
     * @throws ValidationException
     */
    protected function resolvePaymentMethod(string $input): array
    {
        $method = MockBillingProvider::resolvePaymentMethod($input);

        if ($method === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'Unknown payment method. Use a mock test card (4242 4242 4242 4242 or 4000 0000 0000 0002).',
            ]);
        }

        return $method;
    }

    /**
     * Create an invoice with lines, numbering it from the
     * per-organization sequence. The invoice starts open; callers
     * mark it paid or past due after the charge attempt.
     *
     * @param  array<int, array{kind: string, description: string, amount_cents: int, metadata?: array<string, mixed>}>  $lines
     */
    protected function createInvoice(
        Organization $organization,
        Subscription $subscription,
        array $lines,
        array $options = [],
    ): Invoice {
        $subtotal = array_sum(array_map(fn (array $line) => $line['amount_cents'], $lines));

        $invoice = Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => $this->nextInvoiceNumber($organization),
            'status' => Invoice::STATUS_OPEN,
            'currency' => 'USD',
            'subtotal_cents' => max(0, $subtotal),
            'total_cents' => max(0, $subtotal),
            'amount_paid_cents' => 0,
            'period_start' => $options['period_start'] ?? null,
            'period_end' => $options['period_end'] ?? null,
            'due_date' => now(),
            'provider_invoice_id' => 'in_mock_'.Str::random(14),
            'provider_charge_id' => $options['provider_charge_id'] ?? null,
            'failure_code' => $options['failure_code'] ?? null,
            'failure_message' => $options['failure_message'] ?? null,
            'memo' => $options['memo'] ?? null,
        ]);

        foreach ($lines as $line) {
            InvoiceLine::create([
                'invoice_id' => $invoice->getKey(),
                'kind' => $line['kind'],
                'description' => $line['description'],
                'amount_cents' => $line['amount_cents'],
                'metadata' => $line['metadata'] ?? null,
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * The next invoice number for the organization, from the locked
     * per-organization, per-year counter.
     */
    protected function nextInvoiceNumber(Organization $organization): string
    {
        $year = (int) now()->format('Y');

        $sequence = InvoiceSequence::where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequence = InvoiceSequence::create([
                'organization_id' => $organization->getKey(),
                'year' => $year,
                'last_number' => 0,
            ]);
        }

        $sequence->increment('last_number');

        return sprintf('INV-%d-%04d', $year, $sequence->last_number);
    }

    /**
     * Mark an invoice paid in full.
     */
    protected function markInvoicePaid(Invoice $invoice, ?string $chargeId): void
    {
        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
            'amount_paid_cents' => $invoice->total_cents,
            'provider_charge_id' => $chargeId ?? $invoice->provider_charge_id,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Mark an invoice past due and start dunning on the
     * subscription: the first retry is due in 3 days.
     */
    protected function markInvoicePastDue(
        Invoice $invoice,
        Subscription $subscription,
        ?string $failureCode,
        ?string $failureMessage,
    ): void {
        $delays = config('billing.dunning_retry_delays_days', [3, 7]);

        $invoice->update([
            'status' => Invoice::STATUS_PAST_DUE,
            'payment_attempts' => $invoice->payment_attempts + 1,
            'next_retry_at' => now()->addDays((int) ($delays[0] ?? 3)),
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
        ]);

        $subscription->update(['status' => Subscription::STATUS_PAST_DUE]);

        $this->recordBillingEvent($invoice->organization, 'invoice.payment_failed', [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
        ]);
    }

    /**
     * Move a subscription to the Free plan, keeping its data and
     * starting a fresh monthly period. Used by trial lapse,
     * cancellation at period end, and the past-due downgrade.
     */
    protected function applyFreePlan(Subscription $subscription, CarbonInterface $now): void
    {
        // As with a scheduled downgrade, claims created since the
        // old period began carry into the new Free period, so a
        // move to Free blocks new creation above the Free limits
        // (v0.5.0 section 9.3) instead of handing out a fresh
        // allowance the moment the plan changes.
        $carryover = $this->claimsSincePeriodStart($subscription, $now);

        $subscription->update([
            'plan' => PlanCatalog::FREE,
            'status' => Subscription::STATUS_ACTIVE,
            'interval' => PlanCatalog::INTERVAL_MONTHLY,
            'current_period_start' => $now,
            'current_period_end' => $now->copy()->addMonth(),
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
            'pending_plan' => null,
            'pending_interval' => null,
            'pending_extra_carriers' => null,
            'extra_carriers' => 0,
            'success_fee_cap_until' => null,
            'claims_carryover' => $carryover,
        ]);
    }

    /**
     * Claims created since the current period began, for the
     * downgrade carryover (see applyPendingDowngrade). The window
     * runs to the renewal time, not to the old period end, so
     * claims created after the period ended but before the renewal
     * ran still count.
     */
    protected function claimsSincePeriodStart(Subscription $subscription, CarbonInterface $now): int
    {
        if ($subscription->current_period_start === null) {
            return 0;
        }

        return (int) $subscription->organization->claims()
            ->where('created_at', '>=', $subscription->current_period_start)
            ->where('created_at', '<', $now)
            ->count();
    }

    /**
     * A trial that cannot convert lapses to Free.
     */
    protected function lapseTrialToFree(Subscription $subscription, CarbonInterface $now): void
    {
        $this->applyFreePlan($subscription, $now);

        $this->recordBillingEvent($subscription->organization, 'trial.lapsed', [
            'plan' => PlanCatalog::GROWTH,
        ]);
    }

    /**
     * The end of the next billing period for an interval.
     */
    protected function nextPeriodEnd(string $interval, CarbonInterface $from): CarbonInterface
    {
        return $interval === PlanCatalog::INTERVAL_ANNUAL
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();
    }

    /**
     * Record a billing event with a generated mock event id.
     */
    protected function recordBillingEvent(Organization $organization, string $type, array $data): BillingEvent
    {
        return BillingEvent::create([
            'organization_id' => $organization->getKey(),
            'type' => $type,
            'provider_event_id' => 'evt_mock_'.Str::random(16),
            'payload' => ['type' => $type, 'data' => $data],
            'status' => BillingEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    /**
     * The organization an event payload maps to, if any.
     */
    protected function organizationIdFromEvent(array $data): ?string
    {
        if (! empty($data['organization_id'])) {
            return (string) $data['organization_id'];
        }

        if (! empty($data['subscription_id'])) {
            return Subscription::where('provider_subscription_id', $data['subscription_id'])
                ->value('organization_id');
        }

        if (! empty($data['invoice_id'])) {
            return Invoice::where('id', $data['invoice_id'])->value('organization_id');
        }

        if (! empty($data['checkout_session_id'])) {
            return CheckoutSession::where('provider_session_id', $data['checkout_session_id'])
                ->value('organization_id');
        }

        return null;
    }

    /**
     * Apply a checkout.session.completed event: complete an open
     * session. Already-completed sessions apply nothing (the
     * completion already happened), which keeps redelivery safe.
     */
    protected function applyCheckoutCompleted(array $data): bool
    {
        $session = CheckoutSession::where(
            'provider_session_id',
            $data['checkout_session_id'] ?? '',
        )->first();

        if ($session === null || ! $session->isOpen()) {
            return $session !== null;
        }

        $this->completeCheckout($session, $data['payment_method'] ?? null);

        return true;
    }

    /**
     * Apply an invoice.paid event.
     */
    protected function applyInvoicePaid(array $data): bool
    {
        $invoice = Invoice::find($data['invoice_id'] ?? '');

        if ($invoice === null) {
            return false;
        }

        if ($invoice->status === Invoice::STATUS_PAID) {
            return true;
        }

        $this->markInvoicePaid($invoice, $data['charge_id'] ?? null);

        $subscription = $invoice->subscription;

        if ($subscription !== null && $subscription->status === Subscription::STATUS_PAST_DUE) {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);
        }

        return true;
    }

    /**
     * Apply an invoice.payment_failed event.
     */
    protected function applyInvoicePaymentFailed(array $data): bool
    {
        $invoice = Invoice::find($data['invoice_id'] ?? '');

        if ($invoice === null) {
            return false;
        }

        $subscription = $invoice->subscription;

        if ($subscription === null) {
            return false;
        }

        $this->markInvoicePastDue(
            $invoice,
            $subscription,
            $data['failure_code'] ?? 'card_declined',
            $data['failure_message'] ?? 'Your card was declined.',
        );

        return true;
    }

    /**
     * Apply a customer.subscription.updated event: sync the plan
     * and status the provider reports.
     */
    protected function applySubscriptionUpdated(array $data): bool
    {
        $subscription = Subscription::where(
            'provider_subscription_id',
            $data['provider_subscription_id'] ?? '',
        )->first();

        if ($subscription === null) {
            return false;
        }

        $updates = [];

        if (! empty($data['plan']) && PlanCatalog::exists($data['plan'])) {
            $updates['plan'] = $data['plan'];
        }

        if (! empty($data['status']) && in_array($data['status'], [
            Subscription::STATUS_TRIALING,
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_CANCELED,
        ], true)) {
            $updates['status'] = $data['status'];
        }

        if ($updates !== []) {
            $subscription->update($updates);
        }

        return true;
    }

    /**
     * Apply a customer.subscription.deleted event: the provider
     * canceled the subscription, so the org returns to Free.
     */
    protected function applySubscriptionDeleted(array $data): bool
    {
        $subscription = Subscription::where(
            'provider_subscription_id',
            $data['provider_subscription_id'] ?? '',
        )->first();

        if ($subscription === null) {
            return false;
        }

        $this->applyFreePlan($subscription, now());
        $subscription->update(['canceled_at' => now()]);

        return true;
    }

    // ------------------------------------------------------------------
    // API payloads
    // ------------------------------------------------------------------

    /**
     * The subscription payload for the API.
     *
     * @return array<string, mixed>
     */
    public function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'id' => $subscription->getKey(),
            'plan' => $subscription->plan,
            'plan_name' => PlanCatalog::name($subscription->plan),
            'status' => $subscription->status,
            'interval' => $subscription->interval,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
            'pending_plan' => $subscription->pending_plan,
            'pending_interval' => $subscription->pending_interval,
            'pending_extra_carriers' => $subscription->pending_extra_carriers,
            'provider' => $subscription->provider,
            'payment_method_brand' => $subscription->payment_method_brand,
            'payment_method_last4' => $subscription->payment_method_last4,
            'extra_carriers' => $subscription->extra_carriers,
            'credit_balance_cents' => $subscription->credit_balance_cents,
            'monthly_cents' => PlanCatalog::monthlyCents($subscription->plan, $subscription->extra_carriers),
            'period_cents' => PlanCatalog::periodCents($subscription->plan, $subscription->interval, $subscription->extra_carriers),
            'success_fee_capped' => $subscription->successFeeCapped(),
        ];
    }

    /**
     * The invoice payload for the API, with lines.
     *
     * @return array<string, mixed>
     */
    public function invoicePayload(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'refunds']);

        return [
            'id' => $invoice->getKey(),
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_cents' => $invoice->subtotal_cents,
            'total_cents' => $invoice->total_cents,
            'amount_paid_cents' => $invoice->amount_paid_cents,
            'amount_refunded_cents' => $invoice->amount_refunded_cents,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'due_date' => $invoice->due_date?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'payment_attempts' => $invoice->payment_attempts,
            'next_retry_at' => $invoice->next_retry_at?->toIso8601String(),
            'failure_code' => $invoice->failure_code,
            'failure_message' => $invoice->failure_message,
            'memo' => $invoice->memo,
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'id' => $line->getKey(),
                'kind' => $line->kind,
                'description' => $line->description,
                'amount_cents' => $line->amount_cents,
            ])->all(),
            'refunds' => $invoice->refunds->map(fn (Refund $refund) => $this->refundPayload($refund))->all(),
            'created_at' => $invoice->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function invoicePayloads(Organization $organization, int $limit): array
    {
        return Invoice::where('organization_id', $organization->getKey())
            ->with('lines')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => $this->invoicePayload($invoice))
            ->all();
    }
}
