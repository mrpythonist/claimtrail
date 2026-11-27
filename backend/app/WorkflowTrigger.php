<?php

namespace App;

/**
 * What starts a workflow run (v2.0.0).
 *
 * Every trigger names a claim event inside the organization.
 * Deadline triggers fire from the daily workflows:run-due
 * command, not from a request: deadline.approaching fires
 * when the claim's active deadline is inside the workflow's
 * deadline window, deadline.overdue when it has passed. The
 * evidence triggers fire when evidence is attached to or
 * detached from a claim and the readiness outcome changes.
 */
enum WorkflowTrigger: string
{
    case ClaimCreated = 'claim.created';
    case ClaimSubmitted = 'claim.submitted';
    case StatusChanged = 'status.changed';
    case DeadlineApproaching = 'deadline.approaching';
    case DeadlineOverdue = 'deadline.overdue';
    case EvidenceCompleted = 'evidence.completed';
    case EvidenceIncomplete = 'evidence.incomplete';
    case PaymentRecorded = 'payment.recorded';

    public function label(): string
    {
        return match ($this) {
            self::ClaimCreated => 'Claim created',
            self::ClaimSubmitted => 'Claim submitted',
            self::StatusChanged => 'Claim status changed',
            self::DeadlineApproaching => 'Deadline approaching',
            self::DeadlineOverdue => 'Deadline overdue',
            self::EvidenceCompleted => 'Evidence became complete',
            self::EvidenceIncomplete => 'Evidence became incomplete',
            self::PaymentRecorded => 'Payment recorded',
        };
    }

    /**
     * Whether the trigger is deadline-based (fired by the
     * scheduled command, not by a request).
     */
    public function isDeadlineTrigger(): bool
    {
        return in_array($this, [self::DeadlineApproaching, self::DeadlineOverdue], true);
    }

    /**
     * Every trigger value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $trigger) => $trigger->value, self::cases());
    }
}
