<?php

namespace App\Services;

use App\GeneratedDocumentStatus;
use App\GeneratedDocumentType;
use App\Models\Claim;
use App\Models\ClaimReviewNote;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\NotificationType;
use App\OrganizationRole;
use App\Permission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Executes one workflow step for one claim.
 *
 * Steps are internal only: they read the claim as recorded,
 * write notes, notifications, and draft packages inside the
 * app, and move a claim only through ClaimTransitionService,
 * which enforces the same transition rules as the API. No
 * step sends anything to a broker or calls an outside
 * service.
 *
 * A step returns its result array. An approval step returns
 * ['paused' => true] and the engine stops the run at the
 * checkpoint instead of continuing. A step that cannot run
 * because its parameters are wrong throws a
 * ValidationException, which the engine treats as a permanent
 * failure: retrying would fail the same way. Any other
 * exception is transient and the engine rethrows it so the
 * job retries with backoff.
 */
class WorkflowStepRunner
{
    public function __construct(
        protected ReadinessService $readiness,
        protected ClaimPackageService $packages,
        protected ClaimTransitionService $transitions,
        protected NotificationService $notifications,
    ) {}

    /**
     * Execute one step.
     *
     * @param  array<string, mixed>  $step  the step definition from the workflow
     * @param  array<string, mixed>  $context  run context (trigger, workflow name)
     *
     * @return array<string, mixed> the step result, recorded on the run step row
     *
     * @throws ValidationException when the step definition is invalid for this claim
     */
    public function run(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step, User $actor, array $context = []): array
    {
        $type = $step['type'] ?? null;

        return match ($type) {
            'evidence_check' => $this->evidenceCheck($claim),
            'prepare_package' => $this->preparePackage($workflow, $run, $claim, $actor),
            'notify' => $this->notifyStep($workflow, $run, $claim, $step, $actor),
            'escalate' => $this->escalate($workflow, $run, $claim, $step, $actor),
            'approval' => $this->approval($workflow, $run, $claim, $step),
            'note' => $this->note($workflow, $run, $claim, $step, $actor),
            'transition' => $this->transition($workflow, $run, $claim, $step, $actor),
            default => throw ValidationException::withMessages([
                'type' => 'Unknown workflow step type: '.(string) $type,
            ]),
        };
    }

    /**
     * Run the readiness computation and freeze a snapshot, the
     * same check the review screen runs. The result carries the
     * missing categories so the run history shows what was
     * missing at that moment.
     *
     * @return array<string, mixed>
     */
    protected function evidenceCheck(Claim $claim): array
    {
        $computed = $this->readiness->compute($claim->fresh());
        $this->readiness->snapshot($claim->fresh());

        return [
            'readiness_percentage' => $computed['readiness_percentage'],
            'readiness_status' => $computed['readiness_status'],
            'missing' => array_column($computed['missing'], 'value'),
            'collected' => array_column($computed['collected'], 'value'),
            'has_requirements' => $computed['has_requirements'],
        ];
    }

    /**
     * Assemble the claim package from recorded data and store
     * it as a draft. The PDF is stored under workflow-drafts/
     * and the run history records it as a draft: it is never
     * sent anywhere, and the app has no submission channel.
     *
     * @return array<string, mixed>
     */
    protected function preparePackage(Workflow $workflow, WorkflowRun $run, Claim $claim, User $actor): array
    {
        $pdf = $this->packages->renderPdf($claim->fresh(), false);
        $key = 'workflow-drafts/'.$claim->organization_id.'/'.$claim->getKey().'/draft-'.$run->getKey().'.pdf';

        Storage::disk('private')->put($key, $pdf);

        $document = GeneratedDocument::create([
            'organization_id' => $claim->organization_id,
            'claim_id' => $claim->getKey(),
            'document_type' => GeneratedDocumentType::ClaimPackage,
            'storage_key' => $key,
            'disk' => 'private',
            'file_size' => strlen($pdf),
            'generated_by' => $actor->getKey(),
            'includes_internal_notes' => false,
            'status' => GeneratedDocumentStatus::Ready,
            'generated_at' => now(),
        ]);

        return [
            'draft' => true,
            'document_id' => $document->getKey(),
            'file_name' => $this->packages->fileName($claim),
            'file_size' => strlen($pdf),
            'note' => 'Draft package prepared by workflow "'.$workflow->name.'". It was stored locally only and sent nowhere.',
        ];
    }

    /**
     * Send an in-app notification to the members holding a
     * permission (default view-claims). The message comes from
     * the workflow definition, so it is the organization's own
     * wording about its own claim.
     *
     * @param  array<string, mixed>  $step
     *
     * @return array<string, mixed>
     */
    protected function notifyStep(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step, User $actor): array
    {
        $message = trim((string) ($step['message'] ?? ''));

        if ($message === '') {
            throw ValidationException::withMessages([
                'message' => 'A notify step needs a message.',
            ]);
        }

        $permissionValue = $step['permission'] ?? Permission::ViewClaims->value;
        $permission = Permission::tryFrom($permissionValue);

        if ($permission === null) {
            throw ValidationException::withMessages([
                'permission' => 'Unknown permission: '.$permissionValue,
            ]);
        }

        $recipients = $this->notifications->membersWithPermission($claim->organization, $permission);

        $this->notifications->notify(
            $claim->organization,
            $recipients,
            NotificationType::WorkflowNotification,
            'Workflow "'.$workflow->name.'" on '.$claim->claim_reference,
            $message,
            ['claim_id' => (string) $claim->getKey(), 'claim_reference' => $claim->claim_reference, 'workflow_id' => (string) $workflow->getKey(), 'workflow_run_id' => (string) $run->getKey()],
            'workflow.notify:'.$run->getKey().':'.($step['index'] ?? 0),
            null,
        );

        return [
            'permission' => $permission->value,
            'recipients' => $recipients->count(),
            'message' => $message,
        ];
    }

    /**
     * Escalate the claim to a role: write a review note naming
     * the escalation and notify the members who can review
     * claims. The claim status does not change; escalation is
     * a flag for a person, not an automated decision.
     *
     * @param  array<string, mixed>  $step
     *
     * @return array<string, mixed>
     */
    protected function escalate(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step, User $actor): array
    {
        $role = $this->requireRole($step, 'role');
        $message = trim((string) ($step['message'] ?? 'Escalated for review.'));

        ClaimReviewNote::create([
            'organization_id' => $claim->organization_id,
            'claim_id' => $claim->getKey(),
            'user_id' => $actor->getKey(),
            'note' => '[Workflow: '.$workflow->name.'] Escalated to '.$role->label().': '.$message,
        ]);

        $recipients = $this->notifications->membersWithPermission($claim->organization, Permission::ReviewClaims);

        $this->notifications->notify(
            $claim->organization,
            $recipients,
            NotificationType::WorkflowEscalated,
            'Claim '.$claim->claim_reference.' was escalated to '.$role->label(),
            'Workflow "'.$workflow->name.'" escalated claim '.$claim->claim_reference.' to '.$role->label().': '.$message,
            ['claim_id' => (string) $claim->getKey(), 'claim_reference' => $claim->claim_reference, 'workflow_id' => (string) $workflow->getKey(), 'workflow_run_id' => (string) $run->getKey()],
            'workflow.escalate:'.$run->getKey().':'.($step['index'] ?? 0),
            null,
        );

        return [
            'escalated_to_role' => $role->value,
            'recipients' => $recipients->count(),
            'message' => $message,
        ];
    }

    /**
     * An approval checkpoint: the engine pauses the run here.
     * This method only validates the step and returns the
     * pause marker; the engine creates the approval row and
     * notifies the role.
     *
     * @param  array<string, mixed>  $step
     *
     * @return array<string, mixed>
     */
    protected function approval(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step): array
    {
        $role = $this->requireRole($step, 'role');

        return [
            'paused' => true,
            'role' => $role->value,
            'message' => trim((string) ($step['message'] ?? 'Approval required to continue this workflow.')),
        ];
    }

    /**
     * Add a review note to the claim, prefixed with the
     * workflow name so it is clear the note came from
     * automation, not from a person.
     *
     * @param  array<string, mixed>  $step
     *
     * @return array<string, mixed>
     */
    protected function note(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step, User $actor): array
    {
        $message = trim((string) ($step['message'] ?? ''));

        if ($message === '') {
            throw ValidationException::withMessages([
                'message' => 'A note step needs a message.',
            ]);
        }

        $note = ClaimReviewNote::create([
            'organization_id' => $claim->organization_id,
            'claim_id' => $claim->getKey(),
            'user_id' => $actor->getKey(),
            'note' => '[Workflow: '.$workflow->name.'] '.$message,
        ]);

        return [
            'note_id' => $note->getKey(),
            'message' => $message,
        ];
    }

    /**
     * Move the claim through ClaimTransitionService, which
     * enforces the same transition map and data rules as the
     * API. A transition the claim cannot make is a permanent
     * failure: the engine records it and does not retry.
     *
     * @param  array<string, mixed>  $step
     *
     * @return array<string, mixed>
     */
    protected function transition(Workflow $workflow, WorkflowRun $run, Claim $claim, array $step, User $actor): array
    {
        $targetValue = $step['to_status'] ?? null;
        $target = \App\ClaimStatus::tryFrom((string) $targetValue);

        if ($target === null) {
            throw ValidationException::withMessages([
                'to_status' => 'Unknown claim status: '.(string) $targetValue,
            ]);
        }

        $data = [];

        foreach (['accepted_amount', 'rejection_reason', 'reason', 'submitted_at'] as $key) {
            if (array_key_exists($key, $step)) {
                $data[$key] = $step[$key];
            }
        }

        $updated = $this->transitions->transition($claim->fresh(), $target, $actor, $data);

        return [
            'from_status' => $claim->status->value,
            'to_status' => $updated->status->value,
            'claim_reference' => $updated->claim_reference,
        ];
    }

    /**
     * Resolve a step's role parameter to an OrganizationRole,
     * or fail the step permanently when it names no role.
     *
     * @param  array<string, mixed>  $step
     */
    protected function requireRole(array $step, string $key): OrganizationRole
    {
        $role = OrganizationRole::tryFrom((string) ($step[$key] ?? ''));

        if ($role === null) {
            throw ValidationException::withMessages([
                $key => 'A valid organization role is required: '.(string) ($step[$key] ?? ''),
            ]);
        }

        return $role;
    }
}
