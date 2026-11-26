<?php

namespace App\Billing;

use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * The mock billing provider (v1.2.0).
 *
 * Implements the BillingProvider contract against dummy data, so the
 * full billing flow works end to end with no real Stripe account and
 * no keys. Every id it returns is recognisably fake (cus_mock_*,
 * cs_mock_*, pi_mock_*, ch_mock_*). Charges are deterministic: the
 * test card 4242 4242 4242 4242 (token pm_mock_visa) always succeeds
 * and 4000 0000 0000 0002 (token pm_mock_declined) always declines,
 * matching the Stripe test card numbers so the dummy payment methods
 * map 1:1 when real Stripe lands.
 *
 * The provider is stateless apart from idempotency: a repeated
 * charge with the same idempotency key returns the first result
 * instead of charging twice. The app stores the subscription,
 * invoice, and usage records; the provider only answers
 * provider-side questions.
 */
class MockBillingProvider implements BillingProvider
{
    /**
     * Idempotency cache for charges, keyed by idempotency key.
     * Process-local only; the database invoice is the durable
     * record, and the service also guards on it.
     *
     * @var array<string, array{success: bool, charge_id: string|null, failure_code: string|null, failure_message: string|null}>
     */
    protected static array $charges = [];

    public function name(): string
    {
        return 'mock';
    }

    public function createCustomer(Organization $organization): string
    {
        return 'cus_mock_'.Str::random(14);
    }

    public function createCheckoutSession(
        Organization $organization,
        string $customerId,
        string $plan,
        string $interval,
        int $extraCarriers,
    ): array {
        $sessionId = 'cs_mock_'.Str::random(16);

        return [
            'session_id' => $sessionId,
            // The mock has no hosted page; the frontend completes the
            // session in-app, which is the mock's "customer pays" step.
            'url' => url('/settings/billing?mock_checkout='.$sessionId),
            'expires_at' => now()->addHours(24),
        ];
    }

    public function charge(
        Organization $organization,
        string $customerId,
        string $paymentMethodToken,
        int $amountCents,
        string $description,
        string $idempotencyKey,
    ): array {
        if (isset(self::$charges[$idempotencyKey])) {
            return self::$charges[$idempotencyKey];
        }

        $result = $this->attemptCharge($paymentMethodToken, $amountCents);

        self::$charges[$idempotencyKey] = $result;

        return $result;
    }

    /**
     * The one place that decides whether a dummy payment succeeds.
     */
    protected function attemptCharge(string $paymentMethodToken, int $amountCents): array
    {
        if ($paymentMethodToken === 'pm_mock_declined' || $paymentMethodToken === '4000000000000002') {
            return [
                'success' => false,
                'charge_id' => null,
                'failure_code' => 'card_declined',
                'failure_message' => 'Your card was declined.',
            ];
        }

        // A zero amount needs no charge; report success with no id.
        if ($amountCents <= 0) {
            return [
                'success' => true,
                'charge_id' => null,
                'failure_code' => null,
                'failure_message' => null,
            ];
        }

        return [
            'success' => true,
            'charge_id' => 'ch_mock_'.Str::random(16),
            'failure_code' => null,
            'failure_message' => null,
        ];
    }

    /**
     * Idempotency cache for refunds, keyed by idempotency key.
     * Process-local only; the refunds table is the durable
     * record, and the service also guards on it.
     *
     * @var array<string, array{success: bool, refund_id: string|null, failure_code: string|null, failure_message: string|null}>
     */
    protected static array $refunds = [];

    /**
     * Refund part or all of a mock charge. A repeated refund with
     * the same idempotency key returns the first result instead
     * of refunding twice. A charge that never happened (null id,
     * a zero-amount invoice) cannot be refunded.
     */
    public function refund(
        Organization $organization,
        string $customerId,
        ?string $chargeId,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): array {
        if (isset(self::$refunds[$idempotencyKey])) {
            return self::$refunds[$idempotencyKey];
        }

        if ($chargeId === null || $amountCents <= 0) {
            $result = [
                'success' => false,
                'refund_id' => null,
                'failure_code' => 'nothing_to_refund',
                'failure_message' => 'This invoice has no charge to refund.',
            ];
        } else {
            $result = [
                'success' => true,
                'refund_id' => 're_mock_'.Str::random(16),
                'failure_code' => null,
                'failure_message' => null,
            ];
        }

        self::$refunds[$idempotencyKey] = $result;

        return $result;
    }

    public function createPortalSession(Organization $organization, string $customerId): array
    {
        $sessionId = 'bps_mock_'.Str::random(16);

        return [
            'session_id' => $sessionId,
            'url' => url('/settings/billing?portal_session='.$sessionId),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        $expected = $this->signWebhookPayload($payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * The dummy payment methods the mock accepts, with the Stripe
     * test card numbers they stand for.
     *
     * @return array<int, array{token: string, brand: string, last4: string, succeeds: bool}>
     */
    public static function paymentMethods(): array
    {
        return [
            ['token' => 'pm_mock_visa', 'brand' => 'visa', 'last4' => '4242', 'succeeds' => true],
            ['token' => 'pm_mock_declined', 'brand' => 'visa', 'last4' => '0002', 'succeeds' => false],
        ];
    }

    /**
     * Resolve a token or a raw test card number to a payment method.
     * Returns null for anything the mock does not recognise.
     *
     * @return array{token: string, brand: string, last4: string, succeeds: bool}|null
     */
    public static function resolvePaymentMethod(string $input): ?array
    {
        $input = preg_replace('/\s+/', '', $input) ?? $input;

        foreach (self::paymentMethods() as $method) {
            if ($input === $method['token']) {
                return $method;
            }
        }

        if ($input === '4242424242424242') {
            return ['token' => 'pm_mock_visa', 'brand' => 'visa', 'last4' => '4242', 'succeeds' => true];
        }

        if ($input === '4000000000000002') {
            return ['token' => 'pm_mock_declined', 'brand' => 'visa', 'last4' => '0002', 'succeeds' => false];
        }

        return null;
    }
}
