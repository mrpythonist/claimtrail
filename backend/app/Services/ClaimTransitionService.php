<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\ClaimStatusHistory;
use App\Models\User;
use App\NotificationType;
use App\Permission;
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
 *
 * Each transition also writes one audit row and notifies the
 * organization's members (except the actor) about the outcome.
 * The model observer is suspended during a transition so the same
 * change is not audited twice; notifications go out after the
 * transaction commits, so a rolled-back transition never notifies.
 */
class ClaimTransitionService
{
    public function __construct(
        protected ReadinessService $readiness,
        protected AuditService $audit,
        protected NotificationService $notifications,
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

        $result = $this->audit->withoutAuditing(fn () => DB::transaction(function () use ($claim, $from, $target, $user, $updates, $reason, $data) {
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

            $after = ['status' => $target->value];

            if (isset($data['accepted_amount'])) {
                $after['accepted_amount'] = number_format((float) $data['accepted_amount'], 2, '.', '');
            }

            if ($reason !== null) {
                $after['reason'] = $reason;
            }

            $this->audit->record(
                $claim->organization,
                'claim.status_changed',
                $claim,
                $user,
                ['status' => $from->value],
                $after,
            );

            // Marking a claim ready to submit freezes a readiness
            // snapshot: the review decision is judged against the
            // evidence that was attached at that moment.
            if ($target === ClaimStatus::ReadyToSubmit) {
                $this->readiness->snapshot($claim->fresh());
            }

            return $claim->fresh();
        }));

        $this->notifyTransition($result, $from, $target, $user);

        return $result;
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

        $result = $this->audit->withoutAuditing(fn () => DB::transaction(function () use ($claim, $from, $user, $data, $recovered, $paymentDate) {
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

            $this->audit->record(
                $claim->organization,
                'claim.payment_recorded',
                $claim,
                $user,
                ['status' => $from->value, 'recovered_amount' => null],
                [
                    'status' => ClaimStatus::Paid->value,
                    'recovered_amount' => number_format((float) $recovered, 2, '.', ''),
                    'currency' => $claim->currency,
                    'payment_reference' => $data['payment_reference'] ?? null,
                ],
            );

            return $claim->fresh();
        }));

        $this->notifications->notify(
            $result->organization,
            $this->notifications->membersWithPermission($result->organization, Permission::ViewClaims, $user),
            NotificationType::PaymentRecorded,
            'Payment recorded for '.$result->claim_reference,
            'A payment of '.$result->currency.' '.number_format((float) $result->recovered_amount, 2).' was recorded for claim '.$result->claim_reference.'.',
            $this->notificationData($result),
            null,
            $user,
        );

        return $result;
    }

    /**
     * Notify members about a transition outcome.
     *
     * Only outcomes the team needs to act on notify: ready for
     * review, submitted, accepted (including partially accepted),
     * rejected, and missing evidence. Routine moves (potential to
     * under review, closing) stay quiet apart from the audit row.
     */
    protected function notifyTransition(Claim $claim, ClaimStatus $from, ClaimStatus $target, User $actor): void
    {
        $organization = $claim->organization;
        $recipients = $this->notifications->membersWithPermission($organization, Permission::ViewClaims, $actor);
        $data = $this->notificationData($claim);

        $notify = fn (NotificationType $type, string $title, string $body, ?string $dedupeKey = null) => $this->notifications->notify(
            $organization, $recipients, $type, $title, $body, $data, $dedupeKey, $actor,
        );

        match ($target) {
            ClaimStatus::ReadyToSubmit => $notify(
                NotificationType::ClaimReadyForReview,
                'Claim '.$claim->claim_reference.' is ready to submit',
                'Claim '.$claim->claim_reference.' ('.$claim->currency.' '.number_format((float) $claim->requested_amount, 2).') is ready for submission.',
            ),
            ClaimStatus::Submitted => $notify(
                NotificationType::ClaimSubmitted,
                'Claim '.$claim->claim_reference.' was submitted',
                'Claim '.$claim->claim_reference.' was submitted to the broker.',
            ),
            ClaimStatus::Accepted => $notify(
                NotificationType::ClaimAccepted,
                'Claim '.$claim->claim_reference.' was accepted',
                'Claim '.$claim->claim_reference.' was accepted for '.$claim->currency.' '.number_format((float) $claim->accepted_amount, 2).'.',
            ),
            ClaimStatus::PartiallyAccepted => $notify(
                NotificationType::ClaimAccepted,
                'Claim '.$claim->claim_reference.' was partially accepted',
                'Claim '.$claim->claim_reference.' was partially accepted for '.$claim->currency.' '.number_format((float) $claim->accepted_amount, 2).' of '.$claim->currency.' '.number_format((float) $claim->requested_amount, 2).' requested.',
            ),
            ClaimStatus::Rejected => $notify(
                NotificationType::ClaimRejected,
                'Claim '.$claim->claim_reference.' was rejected',
                'Claim '.$claim->claim_reference.' was rejected'.($claim->rejection_reason !== null ? ': '.$claim->rejection_reason : '.'),
            ),
            ClaimStatus::EvidenceRequired => $this->notifyEvidenceMissing($claim, $actor),
            ClaimStatus::UnderReview => $this->notifyEvidenceMissing($claim, $actor),
            default => null,
        };
    }

    /**
     * Notify members that a claim is missing required evidence.
     *
     * Fires when a claim moves to evidence required or into review
     * with gaps still open. Deduped per claim per day: a claim
     * that bounces between review states does not spam the team.
     */
    protected function notifyEvidenceMissing(Claim $claim, User $actor): void
    {
        $computed = $this->readiness->compute($claim->fresh());
        $missing = $computed['missing'];

        if (empty($missing)) {
            return;
        }

        $labels = implode(', ', array_column($missing, 'label'));

        $this->notifications->notify(
            $claim->organization,
            $this->notifications->membersWithPermission($claim->organization, Permission::ViewClaims, $actor),
            NotificationType::ClaimEvidenceMissing,
            'Claim '.$claim->claim_reference.' is missing evidence',
            'Claim '.$claim->claim_reference.' still needs: '.$labels.'.',
            $this->notificationData($claim),
            'claim.evidence_missing:'.$claim->getKey().':'.now()->toDateString(),
            $actor,
        );
    }

    /**
     * Entity links carried on claim notifications.
     *
     * @return array<string, string>
     */
    protected function notificationData(Claim $claim): array
    {
        return [
            'claim_id' => (string) $claim->getKey(),
            'claim_reference' => $claim->claim_reference,
            'shipment_id' => (string) $claim->shipment_id,
        ];
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
