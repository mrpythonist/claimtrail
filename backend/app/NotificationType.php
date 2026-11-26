<?php

namespace App;

/**
 * Notification event types.
 *
 * Each type is one thing a member can be told about, in the app,
 * by email, or both, per their preferences. The values are stable
 * API strings: the frontend preference form and the stored
 * notification rows both use them.
 */
enum NotificationType: string
{
    case ClaimCreated = 'claim.created';
    case ClaimEvidenceMissing = 'claim.evidence_missing';
    case ClaimReadyForReview = 'claim.ready_for_review';
    case DeadlineUpcoming = 'deadline.upcoming';
    case DeadlineOverdue = 'deadline.overdue';
    case ClaimSubmitted = 'claim.submitted';
    case ClaimAccepted = 'claim.accepted';
    case ClaimRejected = 'claim.rejected';
    case PaymentRecorded = 'payment.recorded';
    case OrganizationInvitation = 'organization.invitation';
    case SupportTicketReply = 'support.ticket_reply';

    public function label(): string
    {
        return match ($this) {
            self::ClaimCreated => 'New claim created',
            self::ClaimEvidenceMissing => 'Missing evidence',
            self::ClaimReadyForReview => 'Claim ready for review',
            self::DeadlineUpcoming => 'Upcoming deadline',
            self::DeadlineOverdue => 'Overdue deadline',
            self::ClaimSubmitted => 'Claim submitted',
            self::ClaimAccepted => 'Claim accepted',
            self::ClaimRejected => 'Claim rejected',
            self::PaymentRecorded => 'Payment recorded',
            self::OrganizationInvitation => 'Organization invitation',
            self::SupportTicketReply => 'Support ticket reply',
        };
    }

    /**
     * Every type value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
