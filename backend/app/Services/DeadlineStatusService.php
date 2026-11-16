<?php

namespace App\Services;

use App\ClaimStatus;
use App\DeadlineStatus;
use App\Models\ClaimDeadline;
use App\NotificationType;
use App\Permission;
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
 *
 * The refresh also notifies members about deadlines that are due
 * soon or overdue. Each notification is deduped per deadline per
 * day, so the hourly run notifies at most once a day per deadline.
 */
class DeadlineStatusService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}
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
     * Only active (non-superseded) deadlines are refreshed. Due-soon
     * and overdue deadlines notify the organization's members (one
     * notification per deadline per day). The counts are logged so
     * the scheduler output shows the state of play.
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
            ->with(['claim', 'organization'])
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

                $this->notifyForStatus($deadline, $status);
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

    /**
     * Notify members about a deadline that is due soon or overdue.
     *
     * Met, missed, and far-future upcoming deadlines stay quiet:
     * only deadlines needing action notify. The dedupe key is per
     * deadline per day, so the hourly run notifies at most once a
     * day about the same deadline.
     */
    protected function notifyForStatus(ClaimDeadline $deadline, DeadlineStatus $status): void
    {
        $type = match ($status) {
            DeadlineStatus::DueSoon => NotificationType::DeadlineUpcoming,
            DeadlineStatus::Overdue => NotificationType::DeadlineOverdue,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $organization = $deadline->organization;
        $claim = $deadline->claim;
        $date = $deadline->deadline_at->copy()->setTimezone($deadline->timezone)->format('Y-m-d H:i');

        $title = $status === DeadlineStatus::Overdue
            ? 'Submission deadline passed for '.$claim->claim_reference
            : 'Submission deadline soon for '.$claim->claim_reference;

        $body = $status === DeadlineStatus::Overdue
            ? 'The submission deadline for claim '.$claim->claim_reference.' passed on '.$date.' ('.$deadline->timezone.').'
            : 'The submission deadline for claim '.$claim->claim_reference.' is on '.$date.' ('.$deadline->timezone.').';

        $this->notifications->notify(
            $organization,
            $this->notifications->membersWithPermission($organization, Permission::ViewClaims),
            $type,
            $title,
            $body,
            [
                'claim_id' => (string) $claim->getKey(),
                'claim_reference' => $claim->claim_reference,
                'deadline_id' => (string) $deadline->getKey(),
                'deadline_at' => $deadline->deadline_at->toISOString(),
            ],
            $type->value.':'.$deadline->getKey().':'.now()->toDateString(),
        );
    }
}
