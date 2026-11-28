<?php

namespace App\Broker;

use App\Models\Claim;

/**
 * The mock broker-system adapter (v2.0.2).
 *
 * Models what a broker's own system records about a claim:
 * the broker's reference, the status as the broker sees it,
 * and when the broker last updated it. Everything it returns
 * is derived from the claim ClaimTrail already recorded, with
 * recognisably mock ids (BRK-MOCK-*). No request ever leaves
 * the app and no claim package is ever sent anywhere: broker
 * access in this version is read/status only, and the
 * submission channel is still missing (see
 * docs/PRODUCTION-LAUNCH-CHECKLIST.md).
 *
 * The broker status is a mapping, not a new source of truth:
 * the claim status in ClaimTrail is the record; this adapter
 * only shows how that status reads from the broker's side.
 */
class MockBrokerAdapter
{
    /**
     * The broker-side status view of a claim.
     *
     * @return array<string, mixed>
     */
    public function statusFor(Claim $claim, string $brokerName): array
    {
        return [
            'broker_name' => $brokerName,
            'broker_reference' => 'BRK-MOCK-'.strtoupper(substr(hash('sha256', $claim->getKey()), 0, 10)),
            'claim_reference' => $claim->claim_reference,
            'claim_status' => $claim->status?->value ?? (string) $claim->status,
            'broker_status' => $this->brokerStatus($claim),
            'external_broker_reference' => $claim->external_broker_reference,
            'requested_amount' => $claim->requested_amount !== null ? (string) $claim->requested_amount : null,
            'accepted_amount' => $claim->accepted_amount !== null ? (string) $claim->accepted_amount : null,
            'recovered_amount' => $claim->recovered_amount !== null ? (string) $claim->recovered_amount : null,
            'currency' => $claim->currency,
            'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'paid_at' => $claim->paid_at?->toIso8601String(),
            'live_available' => false,
            'note' => 'Mock broker view. Derived only from the claim as recorded in ClaimTrail; no broker system is contacted.',
        ];
    }

    /**
     * Map the ClaimTrail status to the broker-side wording.
     * The mapping is fixed and documented, so the same claim
     * always reads the same way from the broker's side.
     */
    protected function brokerStatus(Claim $claim): string
    {
        $status = $claim->status?->value ?? (string) $claim->status;

        return match ($status) {
            'potential', 'evidence_required', 'ready_to_submit' => 'not_submitted',
            'submitted' => 'received',
            'under_review' => 'under_review',
            'accepted' => 'approved',
            'partially_accepted' => 'partially_approved',
            'rejected' => 'denied',
            'paid' => 'paid',
            'closed' => 'closed',
            default => 'unknown',
        };
    }
}
