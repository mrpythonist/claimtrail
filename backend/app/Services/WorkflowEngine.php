<?php

namespace App\Services;

use App\Jobs\RunWorkflow;
use App\Models\Claim;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use App\NotificationType;
use App\OrganizationRole;
use App\Permission;
use App\WorkflowRunStatus;
use App\WorkflowStepStatus;
use App\WorkflowStepType;
use App\WorkflowTrigger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The workflow engine (v2.0.0).
 *
 * Dispatch finds the organization's active workflows for a
 * trigger, evaluates their conditions against the claim as
 * recorded, and starts one run per matching workflow. Runs
 * execute through the queued RunWorkflow job, one step at a
 * time, and every step outcome is recorded. An approval step
 * pauses the run until a member with the named role decides
 * it; nothing after the checkpoint runs before that decision.
 *
 * A step that fails because its definition is wrong for the
 * claim (a ValidationException) fails the run permanently:
 * retrying would fail the same way. Any other failure is
 * transient: the job retries with bounded backoff, and when
 * the retries are exhausted the run is marked failed with
 * the reason, and can be retried by hand from the failed
 * step. A run is never retried forever and never silently
 * dropped.
 */
class WorkflowEngine
{
    public function __construct(
        protected WorkflowConditionEvaluator $conditions,
        protected WorkflowStepRunner $steps,
        protected NotificationService $notifications,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * Fire a trigger for a claim: start one run per active
     * workflow whose trigger matches and whose conditions
     * hold. Returns the runs that were started.
     *
     * @return array<int, WorkflowRun>
     */
    public function dispatch(WorkflowTrigger|string $trigger, Claim $claim, ?User $actor = null, array $context = []): array
    {
        $trigger = $trigger instanceof WorkflowTrigger ? $trigger : WorkflowTrigger::from($trigger);
        $organization = $claim->organization;

        if (! $this->entitlements->limits($organization)['workflows']) {
            Log::info('Workflow dispatch skipped: plan does not include workflows.', [
                'organization_id' => $organization->getKey(),
                'trigger' => $trigger->value,
                'claim_id' => $claim->getKey(),
            ]);

            return [];
        }

        $workflows = Workflow::forOrganization($organization->getKey())
            ->where('trigger', $trigger->value)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get();

        $started = [];

        foreach ($workflows as $workflow) {
            $run = $this->startRun($workflow, $claim, $trigger, $actor, $context);

            if ($run !== null) {
                $started[] = $run;
            }
        }

        return $started;
    }

    /**
     * Fire one deadline workflow for one claim, from the
     * scheduled command. Skips when the workflow already ran
     * for this claim and trigger today, so the daily command
     * never spams duplicate runs.
     */
    public function dispatchForDeadline(Workflow $workflow, Claim $claim, WorkflowTrigger $trigger, ?User $actor = null): ?WorkflowRun
    {
        $alreadyRan = WorkflowRun::withoutGlobalScope('organization')
            ->where('organization_id', $claim->organization_id)
            ->where('workflow_id', $workflow->getKey())
            ->where('claim_id', $claim->getKey())
            ->where('trigger', $trigger->value)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($alreadyRan) {
            return null;
        }

        return $this->startRun($workflow, $claim, $trigger, $actor, ['source' => 'workflows:run-due']);
    }

    /**
     * Start one run for one workflow and claim, or null when
     * the workflow's conditions do not hold or an active run
     * for the same workflow and claim already exists.
     *
     * @param  array<string, mixed>  $context
     */
    public function startRun(Workflow $workflow, Claim $claim, WorkflowTrigger $trigger, ?User $actor = null, array $context = []): ?WorkflowRun
    {
        if (! $this->conditions->matches($claim->fresh(), $workflow->conditions ?? [])) {
            return null;
        }

        $active = WorkflowRun::withoutGlobalScope('organization')
            ->where('organization_id', $claim->organization_id)
            ->where('workflow_id', $workflow->getKey())
            ->where('claim_id', $claim->getKey())
            ->whereIn('status', [WorkflowRunStatus::Running->value, WorkflowRunStatus::WaitingApproval->value])
            ->exists();

        if ($active) {
            return null;
        }

        $actor = $actor ?? $this->runActor($workflow, $claim->organization);

        $run = DB::transaction(function () use ($workflow, $claim, $trigger, $actor, $context) {
            $run = WorkflowRun::create([
                'organization_id' => $claim->organization_id,
                'workflow_id' => $workflow->getKey(),
                'claim_id' => $claim->getKey(),
                'trigger' => $trigger->value,
                'status' => WorkflowRunStatus::Running,
                'actor_user_id' => $actor?->getKey(),
                'current_step_index' => 0,
                'context' => array_merge($context, [
                    'workflow_name' => $workflow->name,
                    'claim_reference' => $claim->claim_reference,
                ]),
                'started_at' => now(),
            ]);

            foreach ($workflow->steps as $index => $step) {
                WorkflowRunStep::create([
                    'organization_id' => $claim->organization_id,
                    'workflow_run_id' => $run->getKey(),
                    'step_index' => $index,
                    'step_type' => $step['type'] ?? 'unknown',
                    'status' => WorkflowStepStatus::Pending,
                ]);
            }

            return $run;
        });

        RunWorkflow::dispatch($run->getKey());

        return $run;
    }

    /**
     * Execute a run from its current step. Called by the
     * queued job; safe to call again for a retry, which
     * resumes from the first step that did not complete.
     */
    public function execute(WorkflowRun $run): void
    {
        $run = $run->fresh();

        if ($run === null || $run->status !== WorkflowRunStatus::Running) {
            return;
        }

        $workflow = $run->workflow;
        $claim = $run->claim;

        if ($workflow === null || $claim === null) {
            $this->markFailed($run, 'The workflow or claim for this run no longer exists.');

            return;
        }

        $actor = $this->runActor($workflow, $run->organization, $run->actor);

        foreach ($run->steps()->orderBy('step_index')->get() as $stepRow) {
            if ($stepRow->step_index < $run->current_step_index) {
                continue;
            }

            if ($stepRow->status === WorkflowStepStatus::Completed) {
                continue;
            }

            $definition = $workflow->steps[$stepRow->step_index] ?? null;

            if ($definition === null) {
                $this->failStep($stepRow, 'The workflow no longer defines this step.');
                $this->markFailed($run->fresh(), 'The workflow no longer defines step '.($stepRow->step_index + 1).'.');

                return;
            }

            $definition['index'] = $stepRow->step_index;
            $stepRow->update([
                'status' => WorkflowStepStatus::Running,
                'started_at' => now(),
                'attempts' => $stepRow->attempts + 1,
            ]);

            try {
                $result = $this->steps->run($workflow, $run, $claim->fresh(), $definition, $actor);
            } catch (ValidationException $exception) {
                $this->failStep($stepRow, $this->validationMessage($exception));
                $this->markFailed($run->fresh(), $this->validationMessage($exception));

                return;
            } catch (\Throwable $exception) {
                $this->failStep($stepRow, $exception->getMessage());
                throw $exception;
            }

            if (($result['paused'] ?? false) === true) {
                $this->pauseAtCheckpoint($run->fresh(), $stepRow, $result, $actor);

                return;
            }

            $stepRow->update([
                'status' => WorkflowStepStatus::Completed,
                'result' => $result,
                'finished_at' => now(),
            ]);

            $run->update(['current_step_index' => $stepRow->step_index + 1]);
        }

        $run->update([
            'status' => WorkflowRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /**
     * Retry a failed run by hand, from the first step that
     * did not complete. Completed steps stay completed; the
     * failure reason is cleared so the new attempt writes
     * its own.
     *
     * @throws ValidationException when the run cannot be retried
     */
    public function retry(WorkflowRun $run, User $user): WorkflowRun
    {
        if ($run->status !== WorkflowRunStatus::Failed) {
            throw ValidationException::withMessages([
                'status' => 'Only a failed run can be retried. This run is '.$run->status->label().'.',
            ]);
        }

        $firstFailed = $run->steps()
            ->where('status', WorkflowStepStatus::Failed)
            ->orderBy('step_index')
            ->first();

        $run->steps()
            ->where('status', WorkflowStepStatus::Failed)
            ->update(['status' => WorkflowStepStatus::Pending, 'error' => null]);

        $run->update([
            'status' => WorkflowRunStatus::Running,
            'current_step_index' => $firstFailed?->step_index ?? 0,
            'failure_reason' => null,
            'finished_at' => null,
        ]);

        RunWorkflow::dispatch($run->getKey());

        return $run->fresh();
    }

    /**
     * Approve a paused checkpoint. Only a member holding the
     * role the checkpoint names (or the organization owner)
     * may approve. The run resumes from the step after the
     * checkpoint.
     *
     * @throws ValidationException when the run is not waiting or the user may not approve
     */
    public function approve(WorkflowRun $run, User $user, ?string $note = null): WorkflowRun
    {
        $approval = $this->pendingApprovalOrFail($run);
        $this->authorizeApprover($run, $user, $approval);

        DB::transaction(function () use ($run, $approval, $user, $note) {
            $approval->update([
                'status' => 'approved',
                'decided_by' => $user->getKey(),
                'note' => $note,
                'decided_at' => now(),
            ]);

            $run->steps()
                ->where('step_index', $approval->step_index)
                ->update([
                    'status' => WorkflowStepStatus::Completed,
                    'result' => ['approved_by' => (string) $user->getKey(), 'note' => $note],
                    'finished_at' => now(),
                ]);

            $run->update([
                'status' => WorkflowRunStatus::Running,
                'current_step_index' => $approval->step_index + 1,
            ]);
        });

        RunWorkflow::dispatch($run->getKey());

        return $run->fresh();
    }

    /**
     * Reject a paused checkpoint. The run ends as rejected
     * and the remaining steps are skipped; nothing after the
     * checkpoint executes.
     *
     * @throws ValidationException when the run is not waiting or the user may not decide
     */
    public function reject(WorkflowRun $run, User $user, ?string $note = null): WorkflowRun
    {
        $approval = $this->pendingApprovalOrFail($run);
        $this->authorizeApprover($run, $user, $approval);

        DB::transaction(function () use ($run, $approval, $user, $note) {
            $approval->update([
                'status' => 'rejected',
                'decided_by' => $user->getKey(),
                'note' => $note,
                'decided_at' => now(),
            ]);

            $run->steps()
                ->where('step_index', '>', $approval->step_index)
                ->where('status', WorkflowStepStatus::Pending)
                ->update(['status' => WorkflowStepStatus::Skipped]);

            $run->steps()
                ->where('step_index', $approval->step_index)
                ->update([
                    'status' => WorkflowStepStatus::Completed,
                    'result' => ['rejected_by' => (string) $user->getKey(), 'note' => $note],
                    'finished_at' => now(),
                ]);

            $run->update([
                'status' => WorkflowRunStatus::Rejected,
                'finished_at' => now(),
            ]);
        });

        return $run->fresh();
    }

    /**
     * Dry-run a workflow against a claim: whether its
     * conditions hold and which steps it would run, without
     * starting a run or executing anything.
     *
     * @return array<string, mixed>
     */
    public function evaluate(Workflow $workflow, Claim $claim): array
    {
        $matches = $this->conditions->matches($claim, $workflow->conditions ?? []);

        return [
            'workflow_id' => (string) $workflow->getKey(),
            'claim_id' => (string) $claim->getKey(),
            'trigger' => $workflow->trigger->value,
            'conditions_match' => $matches,
            'would_run' => $matches && $workflow->is_active,
            'steps' => array_map(fn (array $step, int $index) => [
                'index' => $index,
                'type' => $step['type'] ?? 'unknown',
                'label' => WorkflowStepType::tryFrom((string) ($step['type'] ?? ''))?->label() ?? 'Unknown step',
            ], $workflow->steps, array_keys($workflow->steps)),
        ];
    }

    /**
     * Mark a run failed (used when the job exhausts its
     * retries, and when the workflow or claim is gone). Only
     * a run that is still running can be failed this way.
     */
    public function markFailed(WorkflowRun $run, string $reason): void
    {
        $run = $run->fresh();

        if ($run === null || $run->status !== WorkflowRunStatus::Running) {
            return;
        }

        $run->update([
            'status' => WorkflowRunStatus::Failed,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);

        $this->notifications->notify(
            $run->organization,
            $this->notifications->membersWithPermission($run->organization, Permission::ManageWorkflows),
            NotificationType::WorkflowRunFailed,
            'Workflow "'.$run->workflow?->name.'" failed on '.$run->claim?->claim_reference,
            'Workflow "'.$run->workflow?->name.'" failed on claim '.$run->claim?->claim_reference.': '.$reason,
            ['claim_id' => (string) $run->claim_id, 'workflow_id' => (string) $run->workflow_id, 'workflow_run_id' => (string) $run->getKey()],
            'workflow.run_failed:'.$run->getKey(),
            null,
        );
    }

    /**
     * The workflow run as the API returns it, with its steps
     * and approvals.
     *
     * @return array<string, mixed>
     */
    public function presentRun(WorkflowRun $run): array
    {
        $run->loadMissing(['workflow', 'claim', 'actor', 'steps', 'approvals']);

        return [
            'id' => (string) $run->getKey(),
            'workflow_id' => (string) $run->workflow_id,
            'workflow_name' => $run->workflow?->name,
            'claim_id' => (string) $run->claim_id,
            'claim_reference' => $run->claim?->claim_reference,
            'trigger' => $run->trigger->value,
            'trigger_label' => $run->trigger->label(),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'actor_user_id' => $run->actor_user_id !== null ? (string) $run->actor_user_id : null,
            'current_step_index' => $run->current_step_index,
            'failure_reason' => $run->failure_reason,
            'context' => $run->context,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
            'steps' => $run->steps->map(fn (WorkflowRunStep $step) => [
                'id' => (string) $step->getKey(),
                'step_index' => $step->step_index,
                'step_type' => $step->step_type->value,
                'step_label' => $step->step_type->label(),
                'status' => $step->status->value,
                'status_label' => $step->status->label(),
                'attempts' => $step->attempts,
                'result' => $step->result,
                'error' => $step->error,
                'started_at' => $step->started_at?->toISOString(),
                'finished_at' => $step->finished_at?->toISOString(),
            ])->values()->all(),
            'approvals' => $run->approvals->map(fn (WorkflowApproval $approval) => [
                'id' => (string) $approval->getKey(),
                'step_index' => $approval->step_index,
                'role' => $approval->role,
                'status' => $approval->status,
                'decided_by' => $approval->decided_by !== null ? (string) $approval->decided_by : null,
                'note' => $approval->note,
                'decided_at' => $approval->decided_at?->toISOString(),
                'created_at' => $approval->created_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    /**
     * A workflow as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function presentWorkflow(Workflow $workflow): array
    {
        return [
            'id' => (string) $workflow->getKey(),
            'name' => $workflow->name,
            'description' => $workflow->description,
            'trigger' => $workflow->trigger->value,
            'trigger_label' => $workflow->trigger->label(),
            'is_active' => $workflow->is_active,
            'conditions' => $workflow->conditions ?? [],
            'steps' => $workflow->steps,
            'deadline_days_before' => $workflow->deadline_days_before,
            'created_by' => $workflow->created_by !== null ? (string) $workflow->created_by : null,
            'updated_by' => $workflow->updated_by !== null ? (string) $workflow->updated_by : null,
            'created_at' => $workflow->created_at?->toISOString(),
            'updated_at' => $workflow->updated_at?->toISOString(),
        ];
    }

    /**
     * Pause a run at a checkpoint: create the approval row,
     * mark the step waiting, set the run waiting, and notify
     * the members holding the named role.
     *
     * @param  array<string, mixed>  $result
     */
    protected function pauseAtCheckpoint(WorkflowRun $run, WorkflowRunStep $stepRow, array $result, User $actor): void
    {
        $role = OrganizationRole::from($result['role']);

        DB::transaction(function () use ($run, $stepRow, $result, $role) {
            WorkflowApproval::create([
                'organization_id' => $run->organization_id,
                'workflow_run_id' => $run->getKey(),
                'step_index' => $stepRow->step_index,
                'role' => $role->value,
                'status' => 'pending',
            ]);

            $stepRow->update([
                'status' => WorkflowStepStatus::WaitingApproval,
                'result' => $result,
            ]);

            $run->update([
                'status' => WorkflowRunStatus::WaitingApproval,
                'current_step_index' => $stepRow->step_index,
            ]);
        });

        $recipients = $run->organization->activeMembers()
            ->with('user')
            ->get()
            ->map(fn ($member) => $member->user)
            ->filter(fn (?User $user) => $user !== null)
            ->filter(function (User $user) use ($run, $role) {
                $membership = $user->membershipIn($run->organization);

                return $membership !== null && ($membership->role === $role || $membership->role === OrganizationRole::Owner);
            })
            ->values();

        $this->notifications->notify(
            $run->organization,
            $recipients,
            NotificationType::WorkflowApprovalNeeded,
            'Workflow "'.$run->workflow->name.'" needs '.$role->label().' approval on '.$run->claim->claim_reference,
            ($result['message'] ?? 'Approval required to continue this workflow.').' Claim '.$run->claim->claim_reference.', workflow "'.$run->workflow->name.'".',
            ['claim_id' => (string) $run->claim_id, 'workflow_id' => (string) $run->workflow_id, 'workflow_run_id' => (string) $run->getKey()],
            'workflow.approval_needed:'.$run->getKey().':'.$stepRow->step_index,
            null,
        );
    }

    /**
     * The user a run executes as: the user whose action
     * triggered it, otherwise the workflow creator, otherwise
     * the organization owner. System-triggered runs always
     * have a real member to attribute their notes and
     * transitions to.
     */
    protected function runActor(Workflow $workflow, Organization $organization, ?User $preferred = null): ?User
    {
        if ($preferred !== null) {
            return $preferred;
        }

        if ($workflow->created_by !== null) {
            $creator = User::find($workflow->created_by);

            if ($creator !== null && $creator->isActiveMemberOf($organization)) {
                return $creator;
            }
        }

        $owner = $organization->members()
            ->where('role', OrganizationRole::Owner)
            ->where('status', 'active')
            ->first();

        return $owner?->user;
    }

    /**
     * The pending approval for a run, or a validation error
     * when the run is not waiting at a checkpoint.
     *
     * @throws ValidationException
     */
    protected function pendingApprovalOrFail(WorkflowRun $run): WorkflowApproval
    {
        if ($run->status !== WorkflowRunStatus::WaitingApproval) {
            throw ValidationException::withMessages([
                'status' => 'This run is not waiting for approval. It is '.$run->status->label().'.',
            ]);
        }

        $approval = $run->pendingApproval();

        if ($approval === null) {
            throw ValidationException::withMessages([
                'status' => 'This run has no open approval checkpoint.',
            ]);
        }

        return $approval;
    }

    /**
     * Whether the user may decide a checkpoint: they must
     * hold the role the checkpoint names, or be the
     * organization owner.
     *
     * @throws ValidationException
     */
    protected function authorizeApprover(WorkflowRun $run, User $user, WorkflowApproval $approval): void
    {
        $membership = $user->membershipIn($run->organization);

        if ($membership === null || ! $membership->isActive()) {
            throw ValidationException::withMessages([
                'role' => 'You are not a member of this organization.',
            ]);
        }

        if ($membership->role->value !== $approval->role && $membership->role !== OrganizationRole::Owner) {
            throw ValidationException::withMessages([
                'role' => 'Only a '.$approval->role.' (or the organization owner) may decide this checkpoint.',
            ]);
        }
    }

    /**
     * Record a step failure with its reason.
     */
    protected function failStep(WorkflowRunStep $stepRow, string $error): void
    {
        $stepRow->update([
            'status' => WorkflowStepStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * The first validation message from an exception, as a
     * single readable reason for the run history.
     */
    protected function validationMessage(ValidationException $exception): string
    {
        $messages = $exception->errors();

        if (empty($messages)) {
            return $exception->getMessage();
        }

        $first = reset($messages);

        return is_array($first) ? (string) reset($first) : (string) $first;
    }
}
