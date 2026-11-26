<?php

namespace App\Billing;

use App\Models\Organization;

/**
 * The billing provider contract.
 *
 * Every billing operation the app needs goes through this interface,
 * so the provider is a swap, not a rewrite. The mock provider
 * (MockBillingProvider) implements it against dummy data; a real
 * Stripe implementation would implement the same methods against the
 * Stripe SDK. The app's own records (subscriptions, invoices, usage)
 * live in the database and are the source of truth; the provider is
 * asked to do provider-side things (customers, checkout sessions,
 * charges, portal sessions, webhook signatures) and returns provider
 * ids, never app state.
 *
 * Money is integer cents. Amounts are always USD.
 */
interface BillingProvider
{
    /**
     * The driver name, e.g. "mock" or "stripe". Stored on the
     * subscription so a later swap can tell which provider issued it.
     */
    public function name(): string;

    /**
     * Create (or find) the provider-side customer for the
     * organization. Returns the provider customer id.
     */
    public function createCustomer(Organization $organization): string;

    /**
     * Open a checkout session for a plan purchase.
     *
     * @return array{session_id: string, url: string, expires_at: \DateTimeInterface}
     */
    public function createCheckoutSession(
        Organization $organization,
        string $customerId,
        string $plan,
        string $interval,
        int $extraCarriers,
    ): array;

    /**
     * Charge the stored payment method for an amount.
     *
     * @param  string  $paymentMethodToken  The dummy payment method token
     *                                     (mock) or Stripe payment method id.
     * @param  string  $idempotencyKey  Caller-supplied; a repeated
     *                                  charge with the same key must
     *                                  not double-charge.
     *
     * @return array{success: bool, charge_id: string|null, failure_code: string|null, failure_message: string|null}
     */
    public function charge(
        Organization $organization,
        string $customerId,
        string $paymentMethodToken,
        int $amountCents,
        string $description,
        string $idempotencyKey,
    ): array;

    /**
     * Open a billing portal session for the organization.
     *
     * @return array{session_id: string, url: string, expires_at: \DateTimeInterface}
     */
    public function createPortalSession(Organization $organization, string $customerId): array;

    /**
     * Refund part or all of a charge.
     *
     * @param  string|null  $chargeId  The provider charge id from the
     *                                  paid invoice; null when nothing
     *                                  was ever charged (a zero-amount
     *                                  invoice), which cannot be refunded.
     * @param  string  $idempotencyKey  Caller-supplied; a repeated
     *                                  refund with the same key must
     *                                  not refund twice.
     *
     * @return array{success: bool, refund_id: string|null, failure_code: string|null, failure_message: string|null}
     */
    public function refund(
        Organization $organization,
        string $customerId,
        ?string $chargeId,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): array;

    /**
     * Verify a webhook signature over the raw request body.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool;

    /**
     * Sign a webhook payload, so the mock can deliver events through
     * the same endpoint real Stripe would use.
     */
    public function signWebhookPayload(string $payload, string $secret): string;
}
