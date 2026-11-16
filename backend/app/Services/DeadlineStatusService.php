<?php

namespace App\Services;

use App\ClaimStatus;
use App\DeadlineStatus;
use App\Models\ClaimDeadline;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes claim deadline statuses.
 *
 * Rules, in order:
 * 1. The claim was submitted: met when submitted on or before the
 *    deadline, missed when submitted after it.
 * 2. The claim was closed without submission and the deadline passed:
 *    missed. (A closed claim cannot be submitted anymore.)
 * 3. The deadline passed and the claim is not submitted: overdue.
 * 4. The deadline is inside the reminder window (largest configured
 *    reminder, or 7 days): due soon.
 * 5. Otherwise: upcoming.
 *
 * Deadline comparisons use the deadline's own timezone, so a deadline
 * of 5pm Chicago time is judged at 5pm Chicago time, not UTC.
 */
class DeadlineStatusService
{
    /**
     * Compute the status a deadline should have right now.
     */
    public function compute(ClaimDeadline $deadline): DeadlineStatus
    {
        $claim = $deadline->claim;
        $now = now($deadline->timezone);
        $deadlineAt = $deadline->deadline_at->copy()->setTimezone($deadline->timezone);

        if ($claim->submitted_at !== null) {
            $submittedAt = $claim->submitted_at->copy()->setTimezone($deadline->timezone);

            return $submittedAt->lessThanOrEqualTo($deadlineAt)
                ? DeadlineStatus::Met
                : DeadlineStatus::Missed;
        }

        if ($claim->status === ClaimStatus::Closed && $now->greaterThan($deadlineAt)) {
            return DeadlineStatus::Missed;
        }

        if ($now->greaterThan($deadlineAt)) {
            return DeadlineStatus::Overdue;
        }

        if ($now->copy()->addDays($deadline->reminderWindowDays())->greaterThanOrEqualTo($deadlineAt)) {
            return DeadlineStatus::DueSoon;
        }

        return DeadlineStatus::Upcoming;
    }

    /**
     * Refresh every active deadline and return how many changed.
     *
     * Only active (non-superseded) deadlines are refreshed.
     * Notification delivery is a Milestone 4 concern; this method
     * logs the upcoming and overdue counts so the scheduler output
     * shows the state of play.
     *
     * @return array{refreshed: int, changed: int, upcoming: int, due_soon: int, overdue: int}
     */
    public function refreshAll(): array
    {
        $refreshed = 0;
        $changed = 0;
        $counts = [
            'upcoming' => 0,
            'due_soon' => 0,
            'overdue' => 0,
        ];

        ClaimDeadline::query()
            ->active()
            ->with('claim')
            ->cursor()
            ->each(function (ClaimDeadline $deadline) use (&$refreshed, &$changed, &$counts) {
                $refreshed++;
                $status = $this->compute($deadline);

                if (array_key_exists($status->value, $counts)) {
                    $counts[$status->value]++;
                }

                if ($status !== $deadline->status) {
                    $deadline->update(['status' => $status]);
                    $changed++;
                }
            });

        Log::info('Deadline statuses refreshed.', [
            'refreshed' => $refreshed,
            'changed' => $changed,
            'upcoming' => $counts['upcoming'],
            'due_soon' => $counts['due_soon'],
            'overdue' => $counts['overdue'],
        ]);

        return array_merge([
            'refreshed' => $refreshed,
            'changed' => $changed,
        ], $counts);
    }
}
