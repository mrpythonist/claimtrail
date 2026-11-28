<?php

namespace App\Accounting;

use App\Models\Organization;

/**
 * The accounting provider contract (v2.0.2).
 *
 * An accounting provider exports recorded claim payments to
 * an external ledger (QuickBooks, Xero). Only mock providers
 * exist: they model the provider schema and return
 * deterministic dummy results, with no live credentials and
 * no real sync. A real provider is a later implementation of
 * this contract; nothing else in the app knows which driver
 * is behind it.
 *
 * Money is never moved by an accounting export: the export
 * records that a payment ClaimTrail already recorded exists
 * in the external ledger's shape. The ledger of record stays
 * the claim payment ledger (v1.5.0).
 */
interface AccountingProvider
{
    /**
     * The driver name (e.g. mock_quickbooks).
     */
    public function name(): string;

    /**
     * The human label (e.g. QuickBooks (Mock)).
     */
    public function label(): string;

    /**
     * Whether a live provider is reachable. Mock providers
     * always return false.
     */
    public function liveAvailable(): bool;

    /**
     * What the provider can do, as a capability map.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * Test the connection settings. Mock providers validate
     * the shape of the settings only.
     *
     * @param  array<string, mixed>  $settings
     * @return array{ok: bool, live_available: bool, message: string}
     */
    public function testConnection(array $settings): array;

    /**
     * Export recorded payments to the provider's shape.
     * Returns one dummy external record per payment, with
     * recognisably fake ids. Nothing leaves the app.
     *
     * @param  array<int, string>  $paymentIds
     * @return array{exported: int, skipped: int, records: array<int, array<string, mixed>>}
     */
    public function exportPayments(Organization $organization, array $paymentIds): array;
}
