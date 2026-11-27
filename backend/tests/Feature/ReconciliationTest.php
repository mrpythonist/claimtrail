<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\Notification;
use App\Models\ReconciliationReport;
use App\NotificationType;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Financial reconciliation (v1.5.0).
 *
 * The payment ledger records what the organization says it
 * received. Partial payments leave the claim accepted and
 * only the payment that reaches the amount due moves it to
 * Paid, through the existing record-payment flow. The
 * outstanding rule is due = accepted ?? requested, paid =
 * recovered_amount, outstanding = max(0, due - paid). Nothing
 * here moves real money: billing runs on a mock provider, so
 * no payment is verified against a bank or a processor.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function createAcceptedClaim($organization, float $requested = 250.00, ?float $accepted = 250.00): Claim
    {
        $shipment = $this->createShipment($organization);

        $claim = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => $requested,
            ])
            ->assertCreated()
            ->json('data');

        foreach (['under_review', 'ready_to_submit', 'submitted'] as $step) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson("/api/v1/claims/{$claim['id']}/transition", ['to_status' => $step])
                ->assertOk();
        }

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim['id']}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => $accepted,
            ])
            ->assertOk();

        return Claim::findOrFail($claim['id']);
    }

    protected function recordPayment($organization, Claim $claim, array $overrides = []): array
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/reconciliation/claims/{$claim->getKey()}/payments", array_merge([
                'amount' => 100.00,
                'payment_method' => 'wire_transfer',
                'paid_at' => now()->toDateString(),
                'payment_reference' => 'WIRE-1001',
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    public function test_summary_is_empty_for_a_fresh_organization(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/summary')
            ->assertOk()
            ->assertJsonPath('data.claims_count', 0)
            ->assertJsonPath('data.requested_amount', '0.00')
            ->assertJsonPath('data.outstanding_amount', '0.00');
    }

    public function test_a_payment_cannot_be_recorded_on_a_claim_that_is_not_accepted(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $claim = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/reconciliation/claims/{$claim['id']}/payments", [
                'amount' => 100.00,
                'payment_method' => 'wire_transfer',
                'paid_at' => now()->toDateString(),
            ])
            ->assertUnprocessable();

        $this->assertSame(0, ClaimPayment::count());
    }

    public function test_a_partial_payment_leaves_the_claim_accepted_with_an_outstanding_balance(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);

        $claim = $this->createAcceptedClaim($organization, 250.00, 250.00);

        $payment = $this->recordPayment($organization, $claim);

        $this->assertSame('100.00', $payment['amount']);
        $this->assertSame('wire_transfer', $payment['payment_method']);
        $this->assertSame('WIRE-1001', $payment['payment_reference']);
        $this->assertSame($claim->currency, $payment['currency']);

        $claim = $claim->fresh();
        $this->assertSame('accepted', $claim->status->value);
        $this->assertSame('100.00', $claim->recovered_amount);

        $balance = $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/reconciliation/claims/{$claim->getKey()}")
            ->assertOk()
            ->json('data');

        $this->assertSame('250.00', $balance['requested_amount']);
        $this->assertSame('250.00', $balance['approved_amount']);
        $this->assertSame('250.00', $balance['due_amount']);
        $this->assertSame('100.00', $balance['paid_amount']);
        $this->assertSame('150.00', $balance['outstanding_amount']);
        $this->assertSame('partial', $balance['balance_status']);
        $this->assertCount(1, $balance['payments']);

        // The dispatcher (not the actor) got the partial-payment notification.
        $this->assertTrue(Notification::where('organization_id', $organization->getKey())
            ->where('user_id', $dispatcher->getKey())
            ->where('type', NotificationType::ReconciliationPaymentRecorded)
            ->exists());
        $this->assertFalse(Notification::where('organization_id', $organization->getKey())
            ->where('user_id', $owner->getKey())
            ->where('type', NotificationType::ReconciliationPaymentRecorded)
            ->exists());
    }

    public function test_the_payment_that_reaches_the_amount_due_moves_the_claim_to_paid(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);

        $claim = $this->createAcceptedClaim($organization, 250.00, 200.00);

        $this->recordPayment($organization, $claim, ['amount' => 120.00, 'payment_reference' => 'WIRE-1001']);
        $this->assertSame('accepted', $claim->fresh()->status->value);

        $this->recordPayment($organization, $claim, ['amount' => 80.00, 'payment_reference' => 'WIRE-1002']);

        $claim = $claim->fresh();
        $this->assertSame('paid', $claim->status->value);
        $this->assertSame('200.00', $claim->recovered_amount);
        $this->assertNotNull($claim->paid_at);
        $this->assertSame('WIRE-1002', $claim->payment_reference);

        $balance = $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/reconciliation/claims/{$claim->getKey()}")
            ->assertOk()
            ->json('data');

        $this->assertSame('0.00', $balance['outstanding_amount']);
        $this->assertSame('paid', $balance['balance_status']);
        $this->assertCount(2, $balance['payments']);

        // The existing payment.recorded notification fired for the final payment.
        $this->assertTrue(Notification::where('organization_id', $organization->getKey())
            ->where('type', NotificationType::PaymentRecorded)
            ->exists());
    }

    public function test_an_overpayment_marks_the_claim_paid_and_flags_the_balance(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization, 250.00, 250.00);

        $this->recordPayment($organization, $claim, ['amount' => 300.00]);

        $balance = $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/reconciliation/claims/{$claim->getKey()}")
            ->assertOk()
            ->json('data');

        $this->assertSame('paid', $claim->fresh()->status->value);
        $this->assertSame('300.00', $balance['paid_amount']);
        $this->assertSame('0.00', $balance['outstanding_amount']);
        $this->assertSame('overpaid', $balance['balance_status']);
    }

    public function test_a_payment_can_be_voided_and_a_payment_on_a_paid_claim_cannot(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization, 250.00, 250.00);

        $payment = $this->recordPayment($organization, $claim, ['amount' => 100.00]);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/reconciliation/ledger/{$payment['id']}")
            ->assertOk()
            ->assertJsonPath('data.voided', true);

        $this->assertSame(0, ClaimPayment::count());
        $this->assertSame('0.00', $claim->fresh()->recovered_amount);

        // Pay it in full, then voiding is refused.
        $this->recordPayment($organization, $claim, ['amount' => 250.00]);
        $this->assertSame('paid', $claim->fresh()->status->value);

        $last = ClaimPayment::orderByDesc('created_at')->first();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/reconciliation/ledger/{$last->getKey()}")
            ->assertUnprocessable();

        $this->assertSame(1, ClaimPayment::count());
    }

    public function test_payment_validation_rejects_bad_amounts_methods_and_future_dates(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization);

        $base = ['amount' => 100.00, 'payment_method' => 'wire_transfer', 'paid_at' => now()->toDateString()];

        foreach ([
            ['amount' => 0],
            ['amount' => -5],
            ['payment_method' => 'gold_bars'],
            ['paid_at' => now()->addDay()->toDateString()],
        ] as $bad) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson("/api/v1/reconciliation/claims/{$claim->getKey()}/payments", array_merge($base, $bad))
                ->assertUnprocessable();
        }

        $this->assertSame(0, ClaimPayment::count());
    }

    public function test_the_ledger_lists_and_filters_payments_with_their_references(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization, 250.00, 250.00);
        $other = $this->createAcceptedClaim($organization, 100.00, 100.00);

        $this->recordPayment($organization, $claim, ['amount' => 100.00, 'payment_method' => 'wire_transfer', 'payment_reference' => 'WIRE-1']);
        $this->recordPayment($organization, $claim, ['amount' => 50.00, 'payment_method' => 'ach', 'payment_reference' => 'ACH-1']);
        $this->recordPayment($organization, $other, ['amount' => 100.00, 'payment_method' => 'cheque', 'payment_reference' => 'CHQ-1']);

        $all = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/ledger')
            ->assertOk()
            ->json('data');
        $this->assertCount(3, $all);

        $byClaim = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/ledger?claim_id='.$claim->getKey())
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $byClaim);

        $byMethod = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/ledger?payment_method=ach')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byMethod);
        $this->assertSame('ACH-1', $byMethod[0]['payment_reference']);
    }

    public function test_a_viewer_can_read_but_cannot_record_or_generate(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization);

        $viewer = \App\Models\User::factory()->create();
        $this->addMember($organization, $viewer, OrganizationRole::Viewer);
        \Laravel\Sanctum\Sanctum::actingAs($viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/summary')
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/reconciliation/claims/{$claim->getKey()}/payments", [
                'amount' => 10.00,
                'payment_method' => 'cash',
                'paid_at' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/reconciliation/reports', [])
            ->assertForbidden();
    }

    public function test_cross_tenant_payments_claims_and_reports_are_404(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization);
        $payment = $this->recordPayment($organization, $claim);

        $report = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/reconciliation/reports', [])
            ->assertCreated()
            ->json('data');

        [, $other] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($other))
            ->getJson("/api/v1/reconciliation/ledger/{$payment['id']}")
            ->assertNotFound();
        $this->withHeaders($this->orgHeaders($other))
            ->getJson("/api/v1/reconciliation/claims/{$claim->getKey()}")
            ->assertNotFound();
        $this->withHeaders($this->orgHeaders($other))
            ->getJson("/api/v1/reconciliation/reports/{$report['id']}")
            ->assertNotFound();
    }

    public function test_a_report_totals_the_period_and_flags_no_discrepancies_when_clean(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization, 250.00, 200.00);
        $this->recordPayment($organization, $claim, ['amount' => 120.00]);

        $report = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/reconciliation/reports', [])
            ->assertCreated()
            ->json('data');

        $this->assertSame('250.00', $report['totals']['requested_amount']);
        $this->assertSame('200.00', $report['totals']['approved_amount']);
        $this->assertSame('120.00', $report['totals']['paid_amount']);
        $this->assertSame('80.00', $report['totals']['outstanding_amount']);
        $this->assertSame(1, $report['totals']['claims_count']);
        $this->assertSame(1, $report['totals']['payments_count']);
        $this->assertSame(0, $report['totals']['discrepancies_count']);
        $this->assertSame([], $report['discrepancies']);

        $listed = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/reconciliation/reports')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $listed);
    }

    public function test_a_report_flags_a_payment_recorded_before_the_ledger_as_legacy(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization, 250.00, 250.00);

        // The pre-ledger record-payment endpoint: paid in full with no ledger rows.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 250.00,
                'payment_date' => now()->toDateString(),
                'payment_reference' => 'OLD-WIRE-9',
            ])
            ->assertOk();

        $report = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/reconciliation/reports', [])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $report['totals']['discrepancies_count']);
        $this->assertSame('legacy_payment', $report['discrepancies'][0]['kind']);
        $this->assertSame($claim->claim_reference, $report['discrepancies'][0]['claim_reference']);
    }

    public function test_the_reconciliation_command_generates_once_per_day_per_period(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createAcceptedClaim($organization);
        $this->recordPayment($organization, $claim, ['amount' => 50.00]);

        $this->artisan('reconciliation:run')
            ->expectsOutputToContain('Reconciled')
            ->assertExitCode(0);

        $this->assertSame(1, ReconciliationReport::count());

        // A second run the same day skips the organization.
        $this->artisan('reconciliation:run')
            ->expectsOutputToContain('Skipped')
            ->assertExitCode(0);

        $this->assertSame(1, ReconciliationReport::count());
    }
}
