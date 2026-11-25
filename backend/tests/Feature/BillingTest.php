<?php

namespace Tests\Feature;

use App\Billing\PlanCatalog;
use App\Models\BillingUsageRecord;
use App\Models\Invoice;
use App\Models\Subscription;
use App\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Subscription billing (v1.2.0).
 *
 * The provider is the mock: checkout completes in-app, cards are
 * the Stripe test numbers (4242 succeeds, 4000...0002 declines),
 * and no real money moves. Tests cover the full flow: plans,
 * checkout, trials, proration, downgrade scheduling, renewals,
 * cancellations, invoices, dunning, metered success-fee usage
 * with the $49 cap, webhooks, and the portal.
 */
class BillingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Put the organization's subscription on a plan with a stored
     * payment method, as if it had checked out.
     */
    protected function subscribe(\App\Models\Organization $organization, string $plan, string $interval = 'monthly', ?string $paymentMethod = 'pm_mock_visa', int $extraCarriers = 0): Subscription
    {
        $subscription = \App\Services\BillingService::class;
        $service = app($subscription);

        $sub = $service->subscriptionFor($organization);
        $now = now();

        $method = $paymentMethod !== null
            ? \App\Billing\MockBillingProvider::resolvePaymentMethod($paymentMethod)
            : null;

        $sub->update([
            'plan' => $plan,
            'status' => Subscription::STATUS_ACTIVE,
            'interval' => $interval,
            'current_period_start' => $now->copy()->subDays(15),
            'current_period_end' => $now->copy()->addDays(15),
            'plan_started_at' => $now->copy()->subDays(15),
            'success_fee_cap_until' => $plan === PlanCatalog::SUCCESS_FEE
                ? $now->copy()->addMonths(12)
                : null,
            'payment_method_token' => $method['token'] ?? null,
            'payment_method_brand' => $method['brand'] ?? null,
            'payment_method_last4' => $method['last4'] ?? null,
            'extra_carriers' => $extraCarriers,
        ]);

        return $sub->fresh();
    }

    /**
     * Backdate a subscription's period so the renewal is due.
     */
    protected function endPeriod(Subscription $subscription, int $daysAgo = 1): void
    {
        DB::table('subscriptions')
            ->where('id', $subscription->getKey())
            ->update([
                'current_period_start' => now()->subDays(31 + $daysAgo),
                'current_period_end' => now()->subDays($daysAgo),
            ]);
    }

    public function test_plans_endpoint_lists_all_plans_with_prices(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.key', 'free')
            ->assertJsonPath('data.0.monthly_cents', 0)
            ->assertJsonPath('data.1.key', 'starter')
            ->assertJsonPath('data.1.monthly_cents', 1900)
            ->assertJsonPath('data.2.key', 'growth')
            ->assertJsonPath('data.2.monthly_cents', 4900)
            ->assertJsonPath('data.2.annual_cents', 49000)
            ->assertJsonPath('data.3.key', 'fleet')
            ->assertJsonPath('data.3.monthly_cents', 9900)
            ->assertJsonPath('data.4.key', 'agency')
            ->assertJsonPath('data.4.monthly_cents', 14900)
            ->assertJsonPath('data.4.per_extra_carrier_cents', 1200)
            ->assertJsonPath('data.5.key', 'success_fee')
            ->assertJsonPath('data.5.listed', false);
    }

    public function test_subscription_endpoint_creates_a_free_subscription_lazily(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.provider', 'mock')
            ->assertJsonPath('data.monthly_cents', 0);

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_a_viewer_cannot_read_or_change_billing(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/subscription')
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', [])
            ->assertForbidden();
    }

    public function test_checkout_with_a_good_card_activates_growth_and_issues_a_paid_invoice(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $session = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'growth')
            ->assertJsonPath('data.status', 'open')
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/checkout/{$session['id']}/complete", [
                'payment_method' => '4242424242424242',
            ])
            ->assertOk()
            ->assertJsonPath('data.plan', 'growth')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.payment_method_last4', '4242');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(4900, $invoice->total_cents);
        $this->assertSame(4900, $invoice->amount_paid_cents);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{4}$/', $invoice->number);
    }

    public function test_checkout_with_a_declined_card_fails_and_keeps_the_session_open(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $session = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/checkout/{$session['id']}/complete", [
                'payment_method' => '4000000000000002',
            ])
            ->assertUnprocessable();

        $this->assertSame('open', \App\Models\CheckoutSession::find($session['id'])->status);
        $this->assertSame('free', app(\App\Services\BillingService::class)->subscriptionFor($organization)->plan);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_checkout_for_the_success_fee_plan_needs_no_card(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $session = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'success_fee'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/checkout/{$session['id']}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.plan', 'success_fee')
            ->assertJsonPath('data.status', 'active');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, $invoice->total_cents);
    }

    public function test_checkout_from_a_paid_plan_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'fleet'])
            ->assertUnprocessable();
    }

    public function test_annual_checkout_charges_ten_months_and_sets_a_year_period(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $session = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth', 'interval' => 'annual'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/checkout/{$session['id']}/complete", [
                'payment_method' => 'pm_mock_visa',
            ])
            ->assertOk()
            ->assertJsonPath('data.interval', 'annual');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame(49000, $invoice->total_cents);
    }

    public function test_trial_starts_on_growth_for_thirty_days_with_no_card(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 12:00:00'));

        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', [])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'growth')
            ->assertJsonPath('data.status', 'trialing');

        $subscription = app(\App\Services\BillingService::class)->subscriptionFor($organization);
        $this->assertEquals(30, now()->diffInDays($subscription->trial_ends_at));

        $this->travelBack();
    }

    public function test_trial_cannot_start_twice(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', [])
            ->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', [])
            ->assertUnprocessable();
    }

    public function test_trial_converts_to_paid_when_a_card_is_on_file(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', ['payment_method' => 'pm_mock_visa'])
            ->assertCreated();

        $subscription = app(\App\Services\BillingService::class)->subscriptionFor($organization);
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $subscription = $subscription->fresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('growth', $subscription->plan);
        $this->assertNull($subscription->trial_ends_at);

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(4900, $invoice->total_cents);
    }

    public function test_trial_lapses_to_free_without_a_card(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/trials', [])
            ->assertCreated();

        $subscription = app(\App\Services\BillingService::class)->subscriptionFor($organization);
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $this->assertSame('free', $subscription->fresh()->plan);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_upgrade_prorates_the_remaining_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 12:00:00'));

        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        // 30-day period, 15 days left: Growth $49, Fleet $99.
        $this->subscribe($organization, 'growth');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/change-plan', [
                'plan' => 'fleet',
                'preview' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.proration.credit_cents', 2450)
            ->assertJsonPath('data.proration.charge_cents', 4950)
            ->assertJsonPath('data.proration.net_cents', 2500)
            ->assertJsonPath('data.effective', 'immediately');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/change-plan', ['plan' => 'fleet'])
            ->assertOk()
            ->assertJsonPath('data.subscription.plan', 'fleet')
            ->assertJsonPath('data.effective', 'immediately')
            ->assertJsonPath('data.invoice.total_cents', 2500)
            ->assertJsonPath('data.invoice.status', 'paid');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $lines = $invoice->lines()->orderBy('created_at')->get();
        $this->assertSame(-2450, $lines[0]->amount_cents);
        $this->assertSame('proration_credit', $lines[0]->kind);
        $this->assertSame(4950, $lines[1]->amount_cents);
        $this->assertSame('proration_charge', $lines[1]->kind);

        $this->travelBack();
    }

    public function test_downgrade_is_scheduled_and_applies_at_renewal(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'fleet');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/change-plan', ['plan' => 'growth'])
            ->assertOk()
            ->assertJsonPath('data.subscription.plan', 'fleet')
            ->assertJsonPath('data.subscription.pending_plan', 'growth')
            ->assertJsonPath('data.effective', 'at_period_end')
            ->assertJsonPath('data.invoice', null);

        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $subscription = $subscription->fresh();
        $this->assertSame('growth', $subscription->plan);
        $this->assertNull($subscription->pending_plan);

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(4900, $invoice->total_cents);
    }

    public function test_cancel_at_period_end_becomes_free_on_renewal_and_can_be_resumed(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/cancel', [])
            ->assertOk()
            ->assertJsonPath('data.plan', 'growth')
            ->assertJsonPath('data.cancel_at_period_end', true);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/resume', [])
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/cancel', [])
            ->assertOk();

        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $this->assertSame('free', $subscription->fresh()->plan);
        $this->assertNotNull($subscription->fresh()->canceled_at);
    }

    public function test_immediate_cancel_moves_to_free_at_once(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/subscription/cancel', ['immediate' => true])
            ->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_renewal_charges_the_next_period_and_extends_it(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'starter');
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $subscription = $subscription->fresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1900, $invoice->total_cents);
    }

    public function test_failed_renewal_goes_past_due_and_starts_dunning(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth', 'monthly', 'pm_mock_declined');
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $subscription = $subscription->fresh();
        $this->assertSame('past_due', $subscription->status);

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('past_due', $invoice->status);
        $this->assertSame(1, $invoice->payment_attempts);
        $this->assertNotNull($invoice->next_retry_at);
        $this->assertSame('card_declined', $invoice->failure_code);
    }

    public function test_paying_a_past_due_invoice_restores_the_subscription(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth', 'monthly', 'pm_mock_declined');
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('past_due', $invoice->status);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/pay", [
                'payment_method' => 'pm_mock_visa',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_dunning_retries_then_downgrades_to_free_after_fourteen_days(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth', 'monthly', 'pm_mock_declined');
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertSame('past_due', $invoice->status);

        // First retry at +3 days: still declined, attempts 2.
        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'next_retry_at' => now()->subHour(),
        ]);

        Artisan::call('billing:dunning');

        $invoice = $invoice->fresh();
        $this->assertSame('past_due', $invoice->status);
        $this->assertSame(2, $invoice->payment_attempts);

        // 15 days past due: downgrade to Free, invoice written off.
        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'due_date' => now()->subDays(15),
            'next_retry_at' => now()->subHour(),
        ]);

        Artisan::call('billing:dunning');

        $this->assertSame('free', $subscription->fresh()->plan);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('uncollectible', $invoice->fresh()->status);
    }

    public function test_payment_on_a_success_fee_plan_records_metered_usage(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'success_fee', 'monthly', null);

        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 1000.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 1000.00,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        $record = BillingUsageRecord::where('claim_id', $claim->getKey())->first();
        $this->assertNotNull($record);
        $this->assertSame(100000, $record->amount_cents);
        $this->assertSame(15000, $record->fee_cents);
        $this->assertNull($record->invoiced_at);
    }

    public function test_payment_on_a_flat_plan_records_no_usage(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');

        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 1000.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 1000.00,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertDatabaseCount('billing_usage_records', 0);
    }

    public function test_metered_arrears_invoice_caps_the_fee_at_forty_nine_dollars(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'success_fee', 'monthly', null);

        // $1,000 recovered: 15% is $150, capped at $49.
        $this->recordPaidClaim($organization, 1000.00);

        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertNotNull($invoice);
        $this->assertSame('open', $invoice->status);
        $this->assertSame(4900, $invoice->total_cents);

        $lines = $invoice->lines()->orderBy('created_at')->get();
        $this->assertSame('success_fee', $lines[0]->kind);
        $this->assertSame(15000, $lines[0]->amount_cents);
        $this->assertSame('cap_adjustment', $lines[1]->kind);
        $this->assertSame(-10100, $lines[1]->amount_cents);

        $record = BillingUsageRecord::first();
        $this->assertNotNull($record->invoiced_at);
        $this->assertSame($invoice->getKey(), $record->invoice_id);
    }

    public function test_metered_arrears_invoice_below_the_cap_has_no_adjustment(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'success_fee', 'monthly', null);

        // $100 recovered: 15% is $15, under the cap.
        $this->recordPaidClaim($organization, 100.00);

        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $invoice = Invoice::where('organization_id', $organization->getKey())->first();
        $this->assertNotNull($invoice);
        $this->assertSame(1500, $invoice->total_cents);
        $this->assertSame(1, $invoice->lines()->count());
    }

    public function test_no_metered_invoice_when_nothing_was_recovered(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'success_fee', 'monthly', null);
        $this->endPeriod($subscription);

        Artisan::call('billing:renew');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame('success_fee', $subscription->fresh()->plan);
    }

    public function test_usage_summary_shows_accrued_fee_and_cap_remaining(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'success_fee', 'monthly', null);
        $this->recordPaidClaim($organization, 100.00);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/usage')
            ->assertOk()
            ->assertJsonPath('data.recovered_cents', 10000)
            ->assertJsonPath('data.accrued_fee_cents', 1500)
            ->assertJsonPath('data.cap_cents', 4900)
            ->assertJsonPath('data.cap_remaining_cents', 3400);
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->postJson('/api/v1/billing/webhooks', [
            'id' => 'evt_mock_test',
            'type' => 'invoice.paid',
            'data' => [],
        ], ['X-Mock-Signature' => 'sha256=bad'])
            ->assertUnauthorized();
    }

    public function test_webhook_payment_failed_marks_the_invoice_past_due(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth');

        $invoice = Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => 'INV-2026-0001',
            'status' => 'open',
            'total_cents' => 4900,
            'subtotal_cents' => 4900,
            'due_date' => now(),
        ]);

        $event = [
            'id' => 'evt_mock_webhook1',
            'type' => 'invoice.payment_failed',
            'data' => ['invoice_id' => $invoice->getKey(), 'failure_code' => 'card_declined'],
        ];

        $signature = app(\App\Billing\BillingProvider::class)
            ->signWebhookPayload(json_encode($event), (string) config('billing.webhook_secret'));

        $this->postJson('/api/v1/billing/webhooks', $event, ['X-Mock-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertSame('past_due', $invoice->fresh()->status);
        $this->assertSame('past_due', $subscription->fresh()->status);
    }

    public function test_webhook_redelivery_is_recorded_as_a_duplicate(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $subscription = $this->subscribe($organization, 'growth');

        $invoice = Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => 'INV-2026-0001',
            'status' => 'open',
            'total_cents' => 4900,
            'subtotal_cents' => 4900,
            'due_date' => now(),
        ]);

        $event = [
            'id' => 'evt_mock_webhook2',
            'type' => 'invoice.payment_failed',
            'data' => ['invoice_id' => $invoice->getKey()],
        ];

        $signature = app(\App\Billing\BillingProvider::class)
            ->signWebhookPayload(json_encode($event), (string) config('billing.webhook_secret'));

        $this->postJson('/api/v1/billing/webhooks', $event, ['X-Mock-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->postJson('/api/v1/billing/webhooks', $event, ['X-Mock-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.status', 'duplicate');

        // The failure was applied once: one attempt, not two.
        // Two billing events exist: the webhook delivery itself
        // and the internal invoice.payment_failed record written
        // when the invoice went past due. Redelivery adds neither.
        $this->assertSame(1, $invoice->fresh()->payment_attempts);
        $this->assertDatabaseCount('billing_events', 2);
    }

    public function test_portal_session_opens_the_billing_summary(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');

        $session = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/portal', [])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['url', 'token', 'expires_at']])
            ->json('data');

        $this->getJson("/api/v1/billing/portal/{$session['token']}")
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->getKey())
            ->assertJsonPath('data.subscription.plan', 'growth');

        $this->getJson('/api/v1/billing/portal/does-not-exist')
            ->assertNotFound();
    }

    public function test_payment_method_update_stores_the_dummy_card(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/payment-method', ['payment_method' => '4242424242424242'])
            ->assertOk()
            ->assertJsonPath('data.payment_method_brand', 'visa')
            ->assertJsonPath('data.payment_method_last4', '4242');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/billing/payment-method', ['payment_method' => '4111111111111111'])
            ->assertUnprocessable();
    }

    public function test_two_organizations_can_share_an_invoice_number(): void
    {
        // Regression test (found by the live smoke test, 2026-11-25):
        // invoice numbers are per-organization (INV-2026-0001 for
        // each org's first invoice), so a global unique on `number`
        // made the second organization's first checkout fail with a
        // unique-constraint violation. The uniqueness is per org.
        $year = now()->format('Y');
        $expectedNumber = "INV-{$year}-0001";

        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $sessionA = $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth'])
            ->assertCreated()
            ->json('data');
        $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson("/api/v1/billing/checkout/{$sessionA['id']}/complete", [
                'payment_method' => 'pm_mock_visa',
            ])
            ->assertOk();

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $sessionB = $this->withHeaders($this->orgHeaders($organizationB))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth'])
            ->assertCreated()
            ->json('data');
        $this->withHeaders($this->orgHeaders($organizationB))
            ->postJson("/api/v1/billing/checkout/{$sessionB['id']}/complete", [
                'payment_method' => 'pm_mock_visa',
            ])
            ->assertOk();

        $invoiceA = Invoice::where('organization_id', $organizationA->getKey())->first();
        $invoiceB = Invoice::where('organization_id', $organizationB->getKey())->first();

        $this->assertNotNull($invoiceA);
        $this->assertNotNull($invoiceB);
        $this->assertSame($expectedNumber, $invoiceA->number);
        $this->assertSame($expectedNumber, $invoiceB->number);
        $this->assertNotSame($invoiceA->getKey(), $invoiceB->getKey());
    }

    public function test_invoice_history_is_scoped_to_the_organization(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $session = $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson('/api/v1/billing/checkout', ['plan' => 'growth'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson("/api/v1/billing/checkout/{$session['id']}/complete", [
                'payment_method' => 'pm_mock_visa',
            ])
            ->assertOk();

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $invoice = Invoice::where('organization_id', $organizationA->getKey())->first();
        $this->assertNotNull($invoice);

        // Org B cannot see org A's invoice.
        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson("/api/v1/billing/invoices/{$invoice->getKey()}")
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson('/api/v1/billing/invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Walk a claim to paid and return it, as the owner.
     */
    protected function recordPaidClaim(\App\Models\Organization $organization, float $amount): \App\Models\Claim
    {
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => $amount,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => $amount,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        return $claim->fresh();
    }

    /**
     * Walk a claim to the given status through the API, as the owner.
     */
    protected function walkClaimTo(\App\Models\Claim $claim, string $status, array $extra = []): void
    {
        $path = [
            'under_review' => ['under_review'],
            'ready_to_submit' => ['under_review', 'ready_to_submit'],
            'submitted' => ['under_review', 'ready_to_submit', 'submitted'],
        ][$status] ?? [];

        foreach ($path as $step) {
            $this->withHeaders($this->orgHeaders($claim->organization))
                ->postJson("/api/v1/claims/{$claim->getKey()}/transition", array_merge([
                    'to_status' => $step,
                ], $step === $status ? $extra : []))
                ->assertOk();
        }
    }
}
