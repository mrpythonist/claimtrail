<?php

namespace App;

/**
 * Claim lifecycle states.
 *
 * A claim moves through review, submission, and payment. Payment is
 * recorded separately from acceptance: an accepted claim is not
 * recovered revenue until a payment is recorded against it.
 *
 * Transitions are enforced by ClaimTransitionService, never by direct
 * status assignment. The map below is the single source of allowed
 * moves; the service adds the per-transition data rules (amounts,
 * reasons, payment details) on top of it.
 */
enum ClaimStatus: string
{
    case Potential = 'potential';
    case EvidenceRequired = 'evidence_required';
    case UnderReview = 'under_review';
    case ReadyToSubmit = 'ready_to_submit';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case PartiallyAccepted = 'partially_accepted';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Potential => 'Potential',
            self::EvidenceRequired => 'Evidence Required',
            self::UnderReview => 'Under Review',
            self::ReadyToSubmit => 'Ready to Submit',
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted',
            self::PartiallyAccepted => 'Partially Accepted',
            self::Rejected => 'Rejected',
            self::Paid => 'Paid',
            self::Closed => 'Closed',
        };
    }

    /**
     * Statuses this status may move to.
     *
     * Paid is reachable only through the record-payment endpoint, not
     * through a generic status change, so it never appears here.
     * Closed is terminal.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Potential => [self::EvidenceRequired, self::UnderReview, self::Closed],
            self::EvidenceRequired => [self::UnderReview, self::Closed],
            self::UnderReview => [self::ReadyToSubmit, self::EvidenceRequired, self::Closed],
            self::ReadyToSubmit => [self::Submitted, self::UnderReview, self::Closed],
            self::Submitted => [self::Accepted, self::PartiallyAccepted, self::Rejected],
            self::Accepted => [],
            self::PartiallyAccepted => [],
            self::Rejected => [self::Closed, self::UnderReview],
            self::Paid => [self::Closed],
            self::Closed => [],
        };
    }

    /**
     * Whether a move to the given status is allowed by the map.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the claim is still being prepared (not yet submitted).
     */
    public function isPreSubmission(): bool
    {
        return in_array($this, [
            self::Potential,
            self::EvidenceRequired,
            self::UnderReview,
            self::ReadyToSubmit,
        ], true);
    }

    /**
     * Whether the claim reached a submitted state at some point.
     * Used by deadline status computation.
     */
    public function isSubmittedOrBeyond(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::Accepted,
            self::PartiallyAccepted,
            self::Rejected,
            self::Paid,
        ], true);
    }

    /**
     * Every status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
