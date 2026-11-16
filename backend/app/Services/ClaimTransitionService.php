<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\ClaimStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single place claim status changes happen.
 *
 * Controllers never assign Claim::status directly. Every transition
 * goes through here, which checks the transition map, applies the
 * per-transition data rules, stamps the lifecycle timestamps, and
 * writes one append-only history row, all in one transaction.
 *
 * Payment is deliberately outside this service's transition method:
 * moving to paid happens only through recordPayment(), which requires
 * a payment date and a recovered amount.
 */
class ClaimTransitionService
{
    public function __construct(
        protected ReadinessService $readiness,
    ) {}

    /**
     * Move a claim to a new status.
     *
     * @param  array<string, mixed>  $data  accepted_amount, reason, rejection_reason
     *
     * @throws ValidationException when the transition or its data is invalid
     */
    public function transition(Claim $claim, ClaimStatus $target, User $user, array $data = []): Claim
    {
        $from = $claim->status;

        if ($target === ClaimStatus::Paid) {
            throw ValidationException::withMessages([
                'to_status' => 'A claim can only be marked paid by recording a payment.',
            ]);
        }

        if ($target === $from) {
            throw ValidationException::withMessages([
                'to_status' => 'The claim is already '.$from->label().'.',
            ]);
        }

        if (! $from->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'to_status' => "A {$from->label()} claim cannot move to {$target->label()}.",
            ]);
        }

        $updates = $this->transitionUpdates($claim, $target, $data);
        $reason = $data['reason'] ?? $data['rejection_reason'] ?? null;

        return DB::transaction(function () use ($claim, $from, $target, $user, $updates, $reason, $data) {
            $claim->update(array_merge($updates, [
                'updated_by' => $user->id,
            ]));

            ClaimStatusHistory::create([
                'organization_id' => $claim->organization_id,
                'claim_id' => $claim->getKey(),
                'from_status' => $from->value,
                'to_status' => $target->value,
                'changed_by' => $user->id,
                'reason' => $reason,
                'metadata' => $this->historyMetadata($claim, $target, $data),
            ]);

            // Marking a claim ready to submit freezes a readiness
            // snapshot: the review decision is judged against the
            // evidence that was attached at that moment.
            if ($target === ClaimStatus::ReadyToSubmit) {
                $this->readiness->snapshot($claim->fresh());
            }

            return $claim->fresh();
        });
    }

    /**
     * Record an actual payment and move the claim to paid.
     *
     * Only accepted and partially accepted claims can be paid. The
     * recovered amount is the cash actually received and is recorded
     * separately from the accepted amount; it is never defaulted.
     *
     * @param  array<string, mixed>  $data  recovered_amount, payment_date, payment_reference, reason
     *
     * @throws ValidationException when the claim cannot be paid or the data is invalid
     */
    public function recordPayment(Claim $claim, User $user, array $data): Claim
    {
        if (! in_array($claim->status, [ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only an accepted claim can be marked paid. This claim is '.$claim->status->label().'.',
            ]);
        }

        $recovered = $data['recovered_amount'] ?? null;

        if ($recovered === null || ! is_numeric($recovered) || (float) $recovered <= 0) {
            throw ValidationException::withMessages([
                'recovered_amount' => 'A recovered amount greater than zero is required.',
            ]);
        }

        $paymentDate = $data['payment_date'] ?? null;

        if ($paymentDate === null || $paymentDate === '') {
            throw ValidationException::withMessages([
                'payment_date' => 'A payment date is required.',
            ]);
        }

        $from = $claim->status;

        return DB::transaction(function () use ($claim, $from, $user, $data, $recovered, $paymentDate) {
            $claim->update([
                'status' => ClaimStatus::Paid,
                'recovered_amount' => $recovered,
                'paid_at' => $paymentDate,
                'payment_reference' => $data['payment_reference'] ?? $claim->payment_reference,
                'updated_by' => $user->id,
            ]);

            ClaimStatusHistory::create([
                'organization_id' => $claim->organization_id,
                'claim_id' => $claim->getKey(),
                'from_status' => $from->value,
                'to_status' => ClaimStatus::Paid->value,
                'changed_by' => $user->id,
                'reason' => $data['reason'] ?? null,
                'metadata' => [
                    'recovered_amount' => (string) $recovered,
                    'currency' => $claim->currency,
                    'payment_reference' => $data['payment_reference'] ?? null,
                ],
            ]);

            return $claim->fresh();
        });
    }

    /**
     * Field updates each transition applies, after validating its
     * own data rules.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function transitionUpdates(Claim $claim, ClaimStatus $target, array $data): array
    {
        $updates = ['status' => $target];

        if ($target === ClaimStatus::Submitted) {
            // submitted_at is stamped on transition when the caller
            // did not supply one (for example, backdating a submission
            // that happened before the record was created).
            $updates['submitted_at'] = $data['submitted_at'] ?? $claim->submitted_at ?? now();
        }

        if ($target === ClaimStatus::Accepted || $target === ClaimStatus::PartiallyAccepted) {
            $accepted = $data['accepted_amount'] ?? null;

            if ($accepted === null || $accepted === '' || ! is_numeric($accepted) || (float) $accepted <= 0) {
                throw ValidationException::withMessages([
                    'accepted_amount' => 'An accepted amount greater than zero is required.',
                ]);
            }

            if ($target === ClaimStatus::PartiallyAccepted
                && (float) $accepted >= (float) $claim->requested_amount) {
                throw ValidationException::withMessages([
                    'accepted_amount' => 'A partially accepted amount must be less than the requested amount.',
                ]);
            }

            $updates['accepted_amount'] = $accepted;
            $updates['approved_at'] = now();
            $updates['rejection_reason'] = null;
            $updates['rejected_at'] = null;
        }

        if ($target === ClaimStatus::Rejected) {
            $reason = $data['rejection_reason'] ?? $data['reason'] ?? null;

            if ($reason === null || trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'A rejection reason is required.',
                ]);
            }

            $updates['rejection_reason'] = $reason;
            $updates['rejected_at'] = now();
        }

        if ($target === ClaimStatus::Closed && $claim->status->isPreSubmission()) {
            $reason = $data['reason'] ?? null;

            if ($reason === null || trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required to close a claim before submission.',
                ]);
            }
        }

        return $updates;
    }

    /**
     * History metadata for a transition: the amounts that were in
     * effect when the decision was made.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function historyMetadata(Claim $claim, ClaimStatus $target, array $data): array
    {
        $metadata = [
            'requested_amount' => (string) $claim->requested_amount,
            'currency' => $claim->currency,
        ];

        if (isset($data['accepted_amount'])) {
            $metadata['accepted_amount'] = (string) $data['accepted_amount'];
        }

        if ($target === ClaimStatus::Submitted && $claim->submitted_at !== null) {
            $metadata['submitted_at'] = $claim->submitted_at->toISOString();
        }

        return $metadata;
    }
}
