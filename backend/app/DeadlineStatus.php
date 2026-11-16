<?php

namespace App;

/**
 * Claim deadline states, recomputed by the
 * claimtrail:refresh-deadline-statuses command.
 *
 * - upcoming: deadline is in the future, outside the reminder window.
 * - due_soon: deadline is inside the reminder window (largest
 *   reminder_days_before value, or 7 days when none are set).
 * - overdue: deadline passed and the claim has not been submitted.
 * - met: the claim was submitted on or before the deadline.
 * - missed: the claim was submitted after the deadline, or the claim
 *   was closed without submission after the deadline passed.
 */
enum DeadlineStatus: string
{
    case Upcoming = 'upcoming';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Met = 'met';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::DueSoon => 'Due Soon',
            self::Overdue => 'Overdue',
            self::Met => 'Met',
            self::Missed => 'Missed',
        };
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
