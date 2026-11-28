<?php

namespace App\Accounting;

/**
 * The mock accounting provider registry (v2.0.2).
 *
 * Two modelled providers exist: mock_quickbooks and
 * mock_xero. Both are served by MockAccountingProvider; a
 * real provider is a later AccountingProvider implementation
 * registered here. Unknown provider names are rejected, so
 * a typo can never silently fall back to a different
 * ledger.
 */
class AccountingProviderRegistry
{
    /**
     * Every modelled provider name.
     *
     * @return array<int, string>
     */
    public static function providers(): array
    {
        return ['mock_quickbooks', 'mock_xero'];
    }

    /**
     * Labels for the modelled providers.
     *
     * @return array<int, array{value: string, label: string, live_available: bool}>
     */
    public static function options(): array
    {
        return [
            ['value' => 'mock_quickbooks', 'label' => 'QuickBooks (Mock)', 'live_available' => false],
            ['value' => 'mock_xero', 'label' => 'Xero (Mock)', 'live_available' => false],
        ];
    }

    public static function exists(string $provider): bool
    {
        return in_array($provider, self::providers(), true);
    }

    public static function make(string $provider): AccountingProvider
    {
        if (! self::exists($provider)) {
            throw new \InvalidArgumentException("Unknown accounting provider: {$provider}");
        }

        return new MockAccountingProvider($provider);
    }
}
