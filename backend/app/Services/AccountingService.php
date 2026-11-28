<?php

namespace App\Services;

use App\Accounting\AccountingProviderRegistry;
use App\Models\AccountingConnection;
use App\Models\ClaimPayment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Accounting integrations (v2.0.2).
 *
 * Connections are mock providers only: mock_quickbooks and
 * mock_xero model the provider schema and return
 * deterministic dummy results, with no live credentials and
 * no real sync. A sync exports recorded claim payments to
 * the provider's dummy shape; money is never moved, and the
 * claim payment ledger (v1.5.0) stays the record. A real
 * provider is a later AccountingProvider implementation
 * registered in AccountingProviderRegistry.
 */
class AccountingService
{
    /**
     * Connect a mock provider. The settings are validated
     * by the provider's test: a mock connection needs a
     * company name and nothing sensitive.
     *
     * @param  array<string, mixed>  $settings
     */
    public function connect(
        Organization $organization,
        User $creator,
        string $provider,
        string $name,
        array $settings = [],
    ): AccountingConnection {
        if (! AccountingProviderRegistry::exists($provider)) {
            throw ValidationException::withMessages([
                'provider' => "Unknown accounting provider: {$provider}.",
            ]);
        }

        $result = AccountingProviderRegistry::make($provider)->testConnection($settings);

        if (! $result['ok']) {
            throw ValidationException::withMessages([
                'settings' => $result['message'],
            ]);
        }

        return AccountingConnection::create([
            'organization_id' => $organization->getKey(),
            'provider' => $provider,
            'name' => $name,
            'status' => AccountingConnection::STATUS_CONNECTED,
            'settings' => $settings,
            'created_by' => $creator->getKey(),
        ]);
    }

    /**
     * Test a connection's settings through its provider.
     * Returns the provider's verdict; the connection row is
     * not changed.
     *
     * @return array{ok: bool, live_available: bool, message: string}
     */
    public function test(AccountingConnection $connection): array
    {
        $result = AccountingProviderRegistry::make($connection->provider)
            ->testConnection($connection->settings ?? []);

        if (! $result['ok']) {
            $connection->update(['last_error' => $result['message']]);
        }

        return $result;
    }

    /**
     * Run a mock sync: export the organization's recorded
     * payments to the provider's dummy shape. The summary is
     * stored on the connection so the sync is inspectable,
     * and last_synced_at records when it ran. Nothing leaves
     * the app and no money moves.
     *
     * @param  array<int, string>  $paymentIds  when empty, every recorded payment is exported
     * @return array{exported: int, skipped: int, records: array<int, array<string, mixed>>}
     */
    public function sync(AccountingConnection $connection, array $paymentIds = []): array
    {
        if ($connection->status !== AccountingConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages([
                'connection' => 'A disconnected connection cannot sync.',
            ]);
        }

        if ($paymentIds === []) {
            $paymentIds = ClaimPayment::query()
                ->where('organization_id', $connection->organization_id)
                ->orderBy('created_at')
                ->pluck('id')
                ->all();
        }

        $summary = AccountingProviderRegistry::make($connection->provider)
            ->exportPayments($connection->organization, $paymentIds);

        $connection->update([
            'last_sync_summary' => [
                'exported' => $summary['exported'],
                'skipped' => $summary['skipped'],
                'records' => $summary['records'],
            ],
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        return $summary;
    }

    /**
     * Disconnect a connection. History stays: the row and
     * its last sync summary remain readable, it just cannot
     * sync again until reconnected.
     */
    public function disconnect(AccountingConnection $connection): AccountingConnection
    {
        $connection->update(['status' => AccountingConnection::STATUS_DISCONNECTED]);

        return $connection->fresh();
    }

    /**
     * The public presentation of a connection. Settings
     * carry no secrets for mock providers, but the full
     * settings are still not returned: only the company
     * name, which is what the UI needs.
     *
     * @return array<string, mixed>
     */
    public function present(AccountingConnection $connection): array
    {
        return [
            'id' => $connection->getKey(),
            'organization_id' => $connection->organization_id,
            'provider' => $connection->provider,
            'provider_label' => AccountingProviderRegistry::make($connection->provider)->label(),
            'name' => $connection->name,
            'status' => $connection->status,
            'company_name' => $connection->settings['company_name'] ?? null,
            'live_available' => false,
            'last_sync_summary' => $connection->last_sync_summary,
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }
}
