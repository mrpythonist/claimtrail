<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Organization;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\OrganizationRole;
use App\Permission;
use App\Services\EntitlementService;
use App\Services\WorkflowConditionEvaluator;
use App\Services\WorkflowEngine;
use App\WorkflowRunStatus;
use App\WorkflowStepType;
use App\WorkflowTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Workflow configuration and execution history (v2.0.0).
 *
 * A workflow fires on one trigger, evaluates its conditions
 * against the claim as recorded, and runs its ordered steps
 * through the queued engine. Any member may list workflows
 * and read runs. Creating, changing, deleting, testing, and
 * retrying a run need manage-workflows (owner, admin,
 * operations manager, dispatcher) and the Starter plan or
 * better: the Free plan is refused with a 402. Approving or
 * rejecting a checkpoint needs the role the checkpoint names
 * (or the owner); the engine enforces that.
 *
 * Workflows act only on the organization's own recorded data
 * and stay inside the app: no step sends a claim package to a
 * broker or calls an outside service.
 */
class WorkflowController extends Controller
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * List the organization's workflows, newest first. Any
     * member may list.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $workflows = Workflow::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => array_map(
                fn (Workflow $workflow) => $this->engine->presentWorkflow($workflow),
                $workflows->items(),
            ),
            'meta' => $this->paginationMeta($workflows),
        ]);
    }

    /**
     * Create a workflow. Needs manage-workflows and a plan
     * that includes workflows.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageWorkflows->value, $organization);
        $this->entitlements->authorizeWorkflows($organization);

        $validated = $this->validateWorkflow($request, $organization);

        $workflow = Workflow::create(array_merge($validated, [
            'organization_id' => $organization->getKey(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json([
            'data' => $this->engine->presentWorkflow($workflow),
        ], 201);
    }

    /**
     * One workflow. Any member may read.
     */
    public function show(Request $request, Workflow $workflow): JsonResponse
    {
        $organization = $this->organization($request);

        if ($workflow->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->engine->presentWorkflow($workflow),
        ]);
    }

    /**
     * Change a workflow. Needs manage-workflows and a plan
     * that includes workflows. Runs already made keep the
     * definition they started with: a run records its step
     * rows at creation, and the engine only executes steps
     * the current definition still has.
     */
    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $organization = $this->organization($request);

        if ($workflow->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageWorkflows->value, $organization);
        $this->entitlements->authorizeWorkflows($organization);

        $validated = $this->validateWorkflow($request, $organization, $workflow);

        $workflow->update(array_merge($validated, [
            'updated_by' => $request->user()->id,
        ]));

        return response()->json([
            'data' => $this->engine->presentWorkflow($workflow->fresh()),
        ]);
    }

    /**
     * Delete a workflow. Needs manage-workflows. A workflow
     * with runs cannot be deleted: its history stays
     * auditable. Deactivate it instead.
     */
    public function destroy(Request $request, Workflow $workflow): JsonResponse
    {
        $organization = $this->organization($request);

        if ($workflow->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageWorkflows->value, $organization);

        if ($workflow->runs()->exists()) {
            return response()->json([
                'message' => 'This workflow has runs and cannot be deleted. Deactivate it instead so its history stays auditable.',
            ], 422);
        }

        $workflow->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Dry-run a workflow against a claim: whether its
     * conditions hold and which steps it would run, without
     * starting a run or executing anything. Needs
     * manage-workflows.
     */
    public function test(Request $request, Workflow $workflow): JsonResponse
    {
        $organization = $this->organization($request);

        if ($workflow->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageWorkflows->value, $organization);

        $validated = $request->validate([
            'claim_id' => [
                'required', 'uuid',
                Rule::exists('claims', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $claim = Claim::forOrganization($organization->getKey())
            ->whereKey($validated['claim_id'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->engine->evaluate($workflow, $claim),
        ]);
    }

    /**
     * List the organization's workflow runs, newest first.
     * Any member may list. Filters: workflow_id, claim_id,
     * status.
     */
    public function runs(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'workflow_id' => ['nullable', 'uuid'],
            'claim_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(WorkflowRunStatus::values())],
        ]);

        $runs = WorkflowRun::forOrganization($organization->getKey())
            ->with(['workflow', 'claim', 'actor'])
            ->when($validated['workflow_id'] ?? null, fn ($query, $id) => $query->where('workflow_id', $id))
            ->when($validated['claim_id'] ?? null, fn ($query, $id) => $query->where('claim_id', $id))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => array_map(
                fn (WorkflowRun $run) => $this->engine->presentRun($run),
                $runs->items(),
            ),
            'meta' => $this->paginationMeta($runs),
        ]);
    }

    /**
     * One run with its steps and approvals. Any member may read.
     */
    public function showRun(Request $request, WorkflowRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->engine->presentRun($run),
        ]);
    }

    /**
     * Retry a failed run by hand, from the first step that did
     * not complete. Needs manage-workflows.
     */
    public function retryRun(Request $request, WorkflowRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageWorkflows->value, $organization);

        return response()->json([
            'data' => $this->engine->presentRun($this->engine->retry($run, $request->user())),
        ]);
    }

    /**
     * Approve a paused checkpoint. The engine checks that the
     * user holds the role the checkpoint names (or is the
     * owner); a wrong role is a 422, not a 403, because the
     * checkpoint itself is the rule being enforced.
     */
    public function approveRun(Request $request, WorkflowRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->engine->presentRun($this->engine->approve($run, $request->user(), $validated['note'] ?? null)),
        ]);
    }

    /**
     * Reject a paused checkpoint. The run ends as rejected
     * and the remaining steps are skipped.
     */
    public function rejectRun(Request $request, WorkflowRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->engine->presentRun($this->engine->reject($run, $request->user(), $validated['note'] ?? null)),
        ]);
    }

    /**
     * Validate a workflow definition on create or update.
     * Conditions and steps are checked against the engine's
     * own vocabulary, so a workflow can never be saved with a
     * field, operator, or step type the engine does not know.
     *
     * @return array<string, mixed>
     */
    protected function validateWorkflow(Request $request, Organization $organization, ?Workflow $workflow = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('workflows', 'name')
                    ->where('organization_id', $organization->getKey())
                    ->ignore($workflow?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'trigger' => ['required', 'string', Rule::in(WorkflowTrigger::values())],
            'is_active' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string', Rule::in(WorkflowConditionEvaluator::fields())],
            'conditions.*.operator' => ['required', 'string', Rule::in(WorkflowConditionEvaluator::operators())],
            'conditions.*.value' => ['present'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.type' => ['required', 'string', Rule::in(WorkflowStepType::values())],
            // Step parameters must be listed here or Laravel
            // strips them from the validated data: only
            // validated fields reach the workflow row.
            'steps.*.message' => ['nullable', 'string', 'max:500'],
            'steps.*.role' => ['nullable', 'string', 'max:40'],
            'steps.*.permission' => ['nullable', 'string', 'max:60'],
            'steps.*.to_status' => ['nullable', 'string', 'max:40'],
            'steps.*.accepted_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'steps.*.rejection_reason' => ['nullable', 'string', 'max:2000'],
            'steps.*.reason' => ['nullable', 'string', 'max:2000'],
            'steps.*.submitted_at' => ['nullable', 'date'],
            'deadline_days_before' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        WorkflowConditionEvaluator::validateConditions($validated['conditions'] ?? null);
        $this->validateSteps($validated['steps']);

        return $validated;
    }

    /**
     * Validate the parameters of every step against its type.
     * A step that names a role must name a real role; a notify
     * or note step must carry its message; a transition step
     * must name a real claim status.
     *
     * @param  array<int, array<string, mixed>>  $steps
     *
     * @throws ValidationException
     */
    protected function validateSteps(array $steps): void
    {
        foreach ($steps as $index => $step) {
            $type = $step['type'] ?? null;
            $fail = fn (string $field, string $message) => throw ValidationException::withMessages([
                "steps.{$index}.{$field}" => $message,
            ]);

            match ($type) {
                'notify' => $this->validateNotifyStep($step, $index, $fail),
                'escalate', 'approval' => $this->validateRoleStep($step, $index, $fail),
                'note' => $this->validateNoteStep($step, $index, $fail),
                'transition' => $this->validateTransitionStep($step, $index, $fail),
                'evidence_check', 'prepare_package' => null,
                default => $fail('type', 'Unknown workflow step type: '.(string) $type),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function validateNotifyStep(array $step, int $index, callable $fail): void
    {
        if (trim((string) ($step['message'] ?? '')) === '') {
            $fail('message', 'A notify step needs a message.');
        }

        if (isset($step['message']) && mb_strlen((string) $step['message']) > 500) {
            $fail('message', 'A notify message is at most 500 characters.');
        }

        if (isset($step['permission']) && Permission::tryFrom((string) $step['permission']) === null) {
            $fail('permission', 'Unknown permission: '.(string) $step['permission']);
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function validateRoleStep(array $step, int $index, callable $fail): void
    {
        if (OrganizationRole::tryFrom((string) ($step['role'] ?? '')) === null) {
            $fail('role', 'A valid organization role is required.');
        }

        if (isset($step['message']) && mb_strlen((string) $step['message']) > 500) {
            $fail('message', 'A step message is at most 500 characters.');
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function validateNoteStep(array $step, int $index, callable $fail): void
    {
        if (trim((string) ($step['message'] ?? '')) === '') {
            $fail('message', 'A note step needs a message.');
        }

        if (mb_strlen((string) $step['message']) > 500) {
            $fail('message', 'A note message is at most 500 characters.');
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    protected function validateTransitionStep(array $step, int $index, callable $fail): void
    {
        if (ClaimStatus::tryFrom((string) ($step['to_status'] ?? '')) === null) {
            $fail('to_status', 'A valid claim status is required.');
        }
    }
}
