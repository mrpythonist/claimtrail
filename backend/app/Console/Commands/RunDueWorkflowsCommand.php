<?php

namespace App\Console\Commands;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\Organization;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use App\WorkflowTrigger;
use Illuminate\Console\Command;

/**
 * Fire deadline workflows for claims whose deadlines are
 * approaching or overdue (v2.0.0).
 *
 * Runs daily at 06:30. For each active workflow with a
 * deadline trigger, claims in the organization with an active
 * deadline inside the workflow's window (approaching) or past
 * its deadline (overdue) get one run, unless the workflow
 * already ran for that claim and trigger today. Submitted,
 * paid, and closed claims never fire: their deadline outcome
 * is already recorded. A claim with no active deadline row
 * falls back to its submission_deadline_at.
 */
class RunDueWorkflowsCommand extends Command
{
    protected $signature = 'workflows:run-due
        {--organization= : Only run deadline workflows for one organization ID}';

    protected $description = 'Fire deadline-approaching and deadline-overdue workflows for claims with due deadlines';

    public function __construct(
        protected WorkflowEngine $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizations = $this->option('organization') !== null
            ? Organization::whereKey($this->option('organization'))->get()
            : Organization::query()->orderBy('created_at')->get();

        if ($this->option('organization') !== null && $organizations->isEmpty()) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $started = 0;
        $skipped = 0;

        foreach ($organizations as $organization) {
            $workflows = Workflow::forOrganization($organization->getKey())
                ->where('is_active', true)
                ->whereIn('trigger', [WorkflowTrigger::DeadlineApproaching->value, WorkflowTrigger::DeadlineOverdue->value])
                ->orderBy('created_at')
                ->get();

            foreach ($workflows as $workflow) {
                foreach ($this->dueClaims($organization, $workflow) as $claim) {
                    $run = $this->engine->dispatchForDeadline($workflow, $claim, $workflow->trigger);

                    if ($run !== null) {
                        $started++;
                        $this->line('Started workflow "'.$workflow->name.'" for claim '.$claim->claim_reference.'.');
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        $this->info("Deadline workflows complete: {$started} runs started, {$skipped} skipped (already ran today or conditions did not hold).");

        return self::SUCCESS;
    }

    /**
     * Claims in the organization whose deadline matches the
     * workflow's trigger: inside the window and still open
     * for approaching, past the deadline and still open for
     * overdue. Submitted, paid, and closed claims are
     * excluded: their deadline outcome is already recorded.
     *
     * @return \Illuminate\Support\Collection<int, Claim>
     */
    protected function dueClaims(Organization $organization, Workflow $workflow)
    {
        $now = now();
        $window = $workflow->deadlineWindowDays();

        $claims = Claim::forOrganization($organization->getKey())
            ->whereNotIn('status', [ClaimStatus::Submitted, ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted, ClaimStatus::Rejected, ClaimStatus::Paid, ClaimStatus::Closed])
            ->with(['activeDeadline', 'shipment'])
            ->get();

        return $claims->filter(function (Claim $claim) use ($workflow, $now, $window) {
            $deadline = $claim->activeDeadline;

            $at = $deadline?->deadline_at ?? $claim->submission_deadline_at;

            if ($at === null) {
                return false;
            }

            if ($workflow->trigger === WorkflowTrigger::DeadlineApproaching) {
                return $at->greaterThan($now) && $at->lessThanOrEqualTo($now->copy()->addDays($window));
            }

            return $at->lessThanOrEqualTo($now);
        })->values();
    }
}
