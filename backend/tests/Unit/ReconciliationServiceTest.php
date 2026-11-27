<?php

namespace Tests\Unit;

use App\Models\Claim;
use App\OrganizationRole;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The outstanding rule (v1.5.0), unit-tested in isolation:
 *
 *   due         = accepted_amount ?? requested_amount
 *   paid        = recovered_amount
 *   outstanding = max(0, due - paid)
 *
 * accepted_amount is the approved amount. Where no approval is
 * recorded, the requested amount is the amount due.
 */
class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    protected function createRequestedClaim($organization, float $requested): Claim
    {
        $shipment = $this->createShipment($organization);

        $data = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => $requested,
            ])
            ->assertCreated()
            ->json('data');

        return Claim::findOrFail($data['id']);
    }

    public function test_balance_uses_the_requested_amount_when_no_approval_is_recorded(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createRequestedClaim($organization, 250.00);

        // No approval recorded: accepted_amount stays null, so
        // the requested amount is the amount due.
        $balance = $this->service()->balanceFor($claim->fresh());

        $this->assertSame('250.00', $balance['requested_amount']);
        $this->assertNull($balance['approved_amount']);
        $this->assertSame('250.00', $balance['due_amount']);
        $this->assertSame('0.00', $balance['paid_amount']);
        $this->assertSame('250.00', $balance['outstanding_amount']);
        $this->assertSame('unpaid', $balance['balance_status']);
    }

    public function test_balance_prefers_the_approved_amount_over_the_requested_amount(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createRequestedClaim($organization, 250.00);

        $claim->update(['accepted_amount' => '200.00', 'recovered_amount' => '50.00']);

        $balance = $this->service()->balanceFor($claim->fresh());

        $this->assertSame('250.00', $balance['requested_amount']);
        $this->assertSame('200.00', $balance['approved_amount']);
        $this->assertSame('200.00', $balance['due_amount']);
        $this->assertSame('50.00', $balance['paid_amount']);
        $this->assertSame('150.00', $balance['outstanding_amount']);
        $this->assertSame('partial', $balance['balance_status']);
    }

    public function test_balance_never_goes_negative_and_flags_overpayment(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createRequestedClaim($organization, 250.00);

        $claim->update(['accepted_amount' => '200.00', 'recovered_amount' => '260.00']);

        $balance = $this->service()->balanceFor($claim->fresh());

        $this->assertSame('0.00', $balance['outstanding_amount']);
        $this->assertSame('overpaid', $balance['balance_status']);
    }

    public function test_balance_reports_legacy_amounts_paid_before_the_ledger(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $claim = $this->createRequestedClaim($organization, 250.00);

        $claim->update(['accepted_amount' => '250.00', 'recovered_amount' => '250.00']);

        $balance = $this->service()->balanceFor($claim->fresh());

        $this->assertSame('250.00', $balance['paid_amount']);
        $this->assertSame('0.00', $balance['ledger_amount']);
        $this->assertSame('250.00', $balance['legacy_amount']);
        $this->assertSame('paid', $balance['balance_status']);
    }
}
