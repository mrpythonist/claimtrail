<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Subscription;
use App\OrganizationRole;
use Tests\TestCase;

/**
 * Invoice refunds (v1.2.3).
 *
 * A paid invoice can be refunded in full (status becomes
 * refunded) or in part (partially_refunded, refundable again
 * until nothing remains). The reason is required. Every refund
 * is a refunds row, an audit row (billing.invoice_refunded), and
 * a billing event (invoice.refunded). The provider is asked
 * first with an idempotency key, so a retry never refunds twice;
 * a charge that never happened cannot be refunded. Refunds run
 * against the mock provider; no real money moves.
 */
class BillingRefundTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function paidInvoice(\App\Models\Organization $organization, int $totalCents = 4900): Invoice
    {
        $subscription = app(\App\Services\BillingService::class)
            ->subscriptionFor($organization);

        return Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => 'INV-2026-0001',
            'status' => Invoice::STATUS_PAID,
            'total_cents' => $totalCents,
            'subtotal_cents' => $totalCents,
            'amount_paid_cents' => $totalCents,
            'amount_refunded_cents' => 0,
            'provider_charge_id' => 'ch_mock_test',
            'due_date' => now(),
            'paid_at' => now(),
        ]);
    }

    public function test_full_refund_sets_the_invoice_to_refunded(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Duplicate charge',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 4900)
            ->assertJsonPath('data.reason', 'Duplicate charge')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.invoice_id', $invoice->getKey());

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(4900, $invoice->fresh()->amount_refunded_cents);
        $this->assertDatabaseHas('refunds', [
            'invoice_id' => $invoice->getKey(),
            'amount_cents' => 4900,
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'billing.invoice_refunded',
        ]);
        $this->assertDatabaseHas('billing_events', [
            'organization_id' => $organization->getKey(),
            'type' => 'invoice.refunded',
            'status' => 'processed',
        ]);
    }

    public function test_partial_refund_leaves_the_invoice_partially_refunded(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'amount_cents' => 2000,
                'reason' => 'Service credit',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 2000);

        $this->assertSame(Invoice::STATUS_PARTIALLY_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(2000, $invoice->fresh()->amount_refunded_cents);

        // The remainder can be refunded too, and then it is refunded.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'amount_cents' => 2900,
                'reason' => 'Rest of the credit',
            ])
            ->assertCreated();

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(4900, $invoice->fresh()->amount_refunded_cents);
        $this->assertDatabaseCount('refunds', 2);
    }

    public function test_refund_requires_a_reason(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [])
            ->assertUnprocessable();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_refund_rejects_amounts_above_the_remaining_paid_amount(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'amount_cents' => 4901,
                'reason' => 'Too much',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'amount_cents' => 0,
                'reason' => 'Nothing',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'amount_cents' => -100,
                'reason' => 'Negative',
            ])
            ->assertUnprocessable();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_refund_rejects_unpaid_invoices(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $subscription = app(\App\Services\BillingService::class)
            ->subscriptionFor($organization);

        $invoice = Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => 'INV-2026-0002',
            'status' => Invoice::STATUS_OPEN,
            'total_cents' => 4900,
            'subtotal_cents' => 4900,
            'amount_paid_cents' => 0,
            'amount_refunded_cents' => 0,
            'due_date' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Nothing to refund',
            ])
            ->assertUnprocessable();
    }

    public function test_refund_fails_when_the_invoice_has_no_charge(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $subscription = app(\App\Services\BillingService::class)
            ->subscriptionFor($organization);

        // Paid on paper, but no provider charge ever happened.
        $invoice = Invoice::create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'number' => 'INV-2026-0003',
            'status' => Invoice::STATUS_PAID,
            'total_cents' => 4900,
            'subtotal_cents' => 4900,
            'amount_paid_cents' => 4900,
            'amount_refunded_cents' => 0,
            'provider_charge_id' => null,
            'due_date' => now(),
            'paid_at' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Duplicate charge',
            ])
            ->assertUnprocessable();

        // The failed refund is recorded, but the invoice is untouched.
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('refunds', [
            'invoice_id' => $invoice->getKey(),
            'status' => 'failed',
        ]);
    }

    public function test_refund_needs_manage_billing_and_is_scoped(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        // A viewer cannot refund.
        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Duplicate charge',
            ])
            ->assertForbidden();

        // Another organization cannot refund the invoice: the
        // service reports it as not found (422), the same as
        // paying another organization's invoice.
        $other = $this->createOrganization();
        $this->actingAsMemberOf(OrganizationRole::Owner, $other);
        $this->withHeaders($this->orgHeaders($other))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Duplicate charge',
            ])
            ->assertUnprocessable();
    }

    public function test_billing_events_endpoint_lists_refunds(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $invoice = $this->paidInvoice($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/billing/invoices/{$invoice->getKey()}/refund", [
                'reason' => 'Duplicate charge',
            ])
            ->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/events')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'invoice.refunded')
            ->assertJsonPath('data.0.status', 'processed');

        // A viewer cannot read the billing event log.
        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/billing/events')
            ->assertForbidden();
    }
}
