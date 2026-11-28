<?php

namespace Tests\Feature;

use App\Accounting\AccountingProviderRegistry;
use App\Models\AccountingConnection;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounting integrations (v2.0.2).
 *
 * Only mock providers exist: QuickBooks and Xero modelled
 * schema-only, with deterministic dummy results, no live
 * credentials and no real sync. A sync exports recorded
 * payments to the provider's dummy shape; money is never
 * moved. Everything is labelled mock in code and docs.
 */
class AccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setupOrg(string $plan = 'starter'): array
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        if ($plan !== null) {
            $this->subscribe($organization, $plan);
        }

        return [$user, $organization];
    }

    public function test_providers_lists_only_mock_providers(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/accounting/providers')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'mock_quickbooks')
            ->assertJsonPath('data.0.label', 'QuickBooks (Mock)')
            ->assertJsonPath('data.0.live_available', false)
            ->assertJsonPath('data.1.value', 'mock_xero')
            ->assertJsonPath('data.1.label', 'Xero (Mock)')
            ->assertJsonPath('data.1.live_available', false);

        $this->assertSame(
            ['mock_quickbooks', 'mock_xero'],
            AccountingProviderRegistry::providers(),
        );
    }

    public function test_connect_test_sync_and_disconnect(): void
    {
        [$user, $organization] = $this->setupOrg();

        $connection = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting', [
                'provider' => 'mock_quickbooks',
                'name' => 'QB mock',
                'settings' => ['company_name' => 'Acme Carriers LLC'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'mock_quickbooks')
            ->assertJsonPath('data.provider_label', 'QuickBooks (Mock)')
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.live_available', false)
            ->assertJsonPath('data.company_name', 'Acme Carriers LLC')
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting/'.$connection.'/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.live_available', false);

        // Record a payment, then sync it: the mock returns
        // a deterministic dummy external id per payment.
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CT-ACCT-1',
            'requested_amount' => 500.00,
        ]);

        $payment = $claim->payments()->create([
            'organization_id' => $organization->getKey(),
            'amount' => 250.00,
            'currency' => 'USD',
            'payment_method' => 'ach',
            'payment_reference' => 'PAY-1',
            'paid_at' => now(),
            'notes' => 'Partial payment',
        ]);

        $sync = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting/'.$connection.'/sync')
            ->assertOk()
            ->assertJsonPath('data.exported', 1)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.records.0.claim_payment_id', $payment->getKey())
            ->assertJsonPath('data.records.0.amount', '250.00')
            ->assertJsonPath('data.records.0.reference', 'PAY-1')
            ->json('data');

        // The dummy external id is deterministic: the same
        // payment always maps to the same QB-MOCK-* id.
        $this->assertStringStartsWith('QB-MOCK-', $sync['records'][0]['external_id']);

        // A zero-amount payment is skipped, never exported.
        $claim->payments()->create([
            'organization_id' => $organization->getKey(),
            'amount' => 0.00,
            'currency' => 'USD',
            'payment_method' => 'ach',
            'payment_reference' => 'PAY-0',
            'paid_at' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting/'.$connection.'/sync')
            ->assertOk()
            ->assertJsonPath('data.exported', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/accounting/'.$connection)
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $this->assertDatabaseHas('accounting_connections', [
            'id' => $connection,
            'status' => 'disconnected',
        ]);
    }

    public function test_unknown_provider_is_422(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting', [
                'provider' => 'quickbooks',
                'name' => 'Real QB',
            ])
            ->assertUnprocessable();
    }

    public function test_accounting_free_plan_is_402(): void
    {
        [, $organization] = $this->setupOrg('free');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting', [
                'provider' => 'mock_quickbooks',
                'name' => 'QB mock',
            ])
            ->assertStatus(402);
    }

    public function test_sync_on_disconnected_connection_is_422(): void
    {
        [$user, $organization] = $this->setupOrg();

        $connection = AccountingConnection::create([
            'organization_id' => $organization->getKey(),
            'provider' => 'mock_xero',
            'name' => 'Xero mock',
            'status' => 'disconnected',
            'settings' => ['company_name' => 'Acme'],
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/accounting/'.$connection->getKey().'/sync')
            ->assertUnprocessable();
    }
}
