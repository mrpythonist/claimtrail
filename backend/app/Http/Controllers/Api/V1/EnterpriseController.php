<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\DisasterRecoveryDrill;
use App\Models\EnterpriseAlert;
use App\Models\LoadTestRun;
use App\Models\Organization;
use App\Models\SecurityAssessment;
use App\Permission;
use App\Services\AlertingService;
use App\Services\DisasterRecoveryService;
use App\Services\EntitlementService;
use App\Services\LoadTestService;
use App\Services\SecurityAssessmentService;
use App\Services\SloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Enterprise readiness (v2.0.3): disaster recovery, load
 * tests, alert rules and alerts, service-level objectives, and
 * the enterprise security self-assessment.
 *
 * Every endpoint needs manage-enterprise (owner, admin,
 * operations manager) and the Growth plan or better: the Free
 * and Starter plans are refused with a 402. All of it is
 * tenant-scoped to the resolved organization.
 */
class EnterpriseController extends Controller
{
    public function __construct(
        protected DisasterRecoveryService $disasterRecovery,
        protected LoadTestService $loadTests,
        protected AlertingService $alerting,
        protected SloService $slos,
        protected SecurityAssessmentService $assessments,
        protected EntitlementService $entitlements,
    ) {}

    // -- Disaster recovery -------------------------------------------------

    /**
     * Disaster recovery status: the RPO/RTO objectives, the
     * latest backup, the last verified backup, and the latest
     * drill. Objectives are configured targets, not measured
     * values.
     */
    public function disasterRecovery(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        return response()->json([
            'data' => $this->disasterRecovery->status($organization),
        ]);
    }

    /**
     * Drill history, newest first.
     */
    public function listDrills(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $drills = DisasterRecoveryDrill::forOrganization($organization->getKey())
            ->with('performedBy')
            ->orderByDesc('performed_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $drills->getCollection()
                ->map(fn (DisasterRecoveryDrill $drill) => $this->disasterRecovery->presentDrill($drill))
                ->all(),
            'meta' => $this->paginationMeta($drills),
        ]);
    }

    /**
     * Run a drill now: verify (re-hash a backup) or
     * restore_dry_run (open a temporary copy of the backup
     * database read-only and count its rows). The live
     * database is never touched.
     */
    public function runDrill(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', DisasterRecoveryDrill::kinds())],
            'backup_run_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        $drill = $this->disasterRecovery->runDrill(
            $organization,
            $validated['kind'],
            $validated['backup_run_id'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->disasterRecovery->presentDrill($drill->fresh()),
        ], 201);
    }

    // -- Load tests --------------------------------------------------------

    /**
     * Load test runs, newest first.
     */
    public function listLoadTests(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $runs = LoadTestRun::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $runs->getCollection()
                ->map(fn (LoadTestRun $run) => $this->loadTests->present($run))
                ->all(),
            'meta' => $this->paginationMeta($runs),
        ]);
    }

    /**
     * Run a bounded local synthetic load pass now and store the
     * result. Requests are capped by config; the run is
     * sequential and in-process, so it measures this instance
     * only and never touches an external service.
     */
    public function runLoadTest(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'requests' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('enterprise.load_test.max_requests', 200)],
            'target' => ['sometimes', 'string', 'in:'.implode(',', LoadTestRun::targets())],
        ]);

        $headers = [];

        if (($validated['target'] ?? LoadTestRun::TARGET_HEALTH) === LoadTestRun::TARGET_SHIPMENTS) {
            // The synthetic requests run with the caller's own
            // credentials: the same Authorization and
            // organization headers this request carried.
            if ($request->header('Authorization') !== null) {
                $headers['Authorization'] = $request->header('Authorization');
            }

            $headers['X-Organization-Id'] = (string) $organization->getKey();
        }

        $run = $this->loadTests->run(
            $organization,
            $validated['target'] ?? LoadTestRun::TARGET_HEALTH,
            $validated['requests'] ?? (int) config('enterprise.load_test.default_requests', 50),
            $validated['name'] ?? null,
            $headers,
        );

        return response()->json([
            'data' => $this->loadTests->present($run),
        ], 201);
    }

    // -- Alert rules -------------------------------------------------------

    /**
     * Alert rules, by name.
     */
    public function listAlertRules(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $rules = AlertRule::forOrganization($organization->getKey())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rules
                ->map(fn (AlertRule $rule) => $this->alerting->presentRule($rule))
                ->all(),
        ]);
    }

    /**
     * Create an alert rule.
     */
    public function storeAlertRule(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'metric' => ['required', 'string', 'in:'.implode(',', AlertRule::metrics())],
            'operator' => ['sometimes', 'string', 'in:gt,gte'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'window_minutes' => ['sometimes', 'integer', 'min:1', 'max:43200'],
            'enabled' => ['sometimes', 'boolean'],
            'severity' => ['sometimes', 'string', 'in:warning,critical'],
        ]);

        $rule = AlertRule::create(array_merge($validated, [
            'organization_id' => $organization->getKey(),
            'created_by' => $request->user()?->getKey(),
        ]));

        return response()->json([
            'data' => $this->alerting->presentRule($rule),
        ], 201);
    }

    /**
     * One alert rule.
     */
    public function showAlertRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alertRule->organization_id);

        return response()->json([
            'data' => $this->alerting->presentRule($alertRule),
        ]);
    }

    /**
     * Update an alert rule. Only the fields in the request are
     * changed.
     */
    public function updateAlertRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alertRule->organization_id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'metric' => ['sometimes', 'string', 'in:'.implode(',', AlertRule::metrics())],
            'operator' => ['sometimes', 'string', 'in:gt,gte'],
            'threshold' => ['sometimes', 'numeric', 'min:0'],
            'window_minutes' => ['sometimes', 'integer', 'min:1', 'max:43200'],
            'enabled' => ['sometimes', 'boolean'],
            'severity' => ['sometimes', 'string', 'in:warning,critical'],
        ]);

        $alertRule->update($validated);

        return response()->json([
            'data' => $this->alerting->presentRule($alertRule->fresh()),
        ]);
    }

    /**
     * Delete an alert rule. Its alerts stay: the rule id on
     * them is set to null, so the history is kept.
     */
    public function destroyAlertRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alertRule->organization_id);

        $alertRule->delete();

        return response()->json(['message' => 'Alert rule deleted.']);
    }

    /**
     * Evaluate one rule now, outside the schedule.
     */
    public function evaluateAlertRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alertRule->organization_id);

        return response()->json([
            'data' => $this->alerting->evaluateRule($alertRule, $request->user()),
        ]);
    }

    // -- Alerts ------------------------------------------------------------

    /**
     * Alerts, newest first, with status and metric filters.
     */
    public function listAlerts(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $alerts = EnterpriseAlert::forOrganization($organization->getKey())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('metric'), fn ($query) => $query->where('metric', $request->string('metric')))
            ->orderByDesc('triggered_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $alerts->getCollection()
                ->map(fn (EnterpriseAlert $alert) => $this->alerting->presentAlert($alert))
                ->all(),
            'meta' => $this->paginationMeta($alerts),
        ]);
    }

    /**
     * Acknowledge an alert: it stays open, but is marked as
     * seen. A repeat breach still updates it rather than
     * opening a second row.
     */
    public function acknowledgeAlert(Request $request, EnterpriseAlert $alert): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alert->organization_id);

        if ($alert->status === EnterpriseAlert::STATUS_ACTIVE) {
            $alert->update([
                'status' => EnterpriseAlert::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $this->alerting->presentAlert($alert->fresh()),
        ]);
    }

    /**
     * Resolve an alert. A later breach of the same rule opens
     * a fresh alert.
     */
    public function resolveAlert(Request $request, EnterpriseAlert $alert): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);
        $this->assertOwns($organization, $alert->organization_id);

        if ($alert->status !== EnterpriseAlert::STATUS_RESOLVED) {
            $alert->update([
                'status' => EnterpriseAlert::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $this->alerting->presentAlert($alert->fresh()),
        ]);
    }

    // -- SLOs --------------------------------------------------------------

    /**
     * The organization's SLOs with their measured values and
     * statuses. Rows are seeded from config on first read.
     */
    public function listSlos(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        return response()->json([
            'data' => $this->slos->statusFor($organization),
        ]);
    }

    /**
     * Update one SLO's target, window, or enabled flag.
     */
    public function updateSlo(Request $request, string $key): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $slo = $this->slos->findFor($organization, $key);

        if ($slo === null) {
            abort(404, 'SLO not found.');
        }

        $validated = $request->validate([
            'target_value' => ['sometimes', 'numeric', 'min:0'],
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $slo->update($validated);

        return response()->json([
            'data' => $this->slos->present($slo->fresh(), $this->slos->measure($slo->fresh())),
        ]);
    }

    // -- Security assessments ----------------------------------------------

    /**
     * Assessment history, newest first.
     */
    public function listAssessments(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $assessments = SecurityAssessment::forOrganization($organization->getKey())
            ->orderByDesc('generated_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $assessments->getCollection()
                ->map(fn (SecurityAssessment $assessment) => $this->assessments->present($assessment))
                ->all(),
            'meta' => $this->paginationMeta($assessments),
        ]);
    }

    /**
     * Generate a fresh self-assessment now and store it.
     */
    public function generateAssessment(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $assessment = $this->assessments->generate($organization, $request->user());

        return response()->json([
            'data' => $this->assessments->present($assessment),
        ], 201);
    }

    /**
     * The latest assessment, or 404 when none exists yet.
     */
    public function latestAssessment(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeEnterprise($organization);

        $assessment = $this->assessments->latest($organization);

        if ($assessment === null) {
            abort(404, 'No assessment has been generated yet.');
        }

        return response()->json([
            'data' => $this->assessments->present($assessment),
        ]);
    }

    /**
     * The permission and the plan gate, in one place: every
     * enterprise endpoint calls this first.
     */
    protected function authorizeEnterprise(Organization $organization): void
    {
        Gate::authorize(Permission::ManageEnterprise->value, $organization);
        $this->entitlements->authorizeEnterprise($organization);
    }

    /**
     * Cross-tenant rows are 404s: route model binding runs
     * before the organization middleware, so the check is
     * explicit, like the other controllers.
     */
    protected function assertOwns(Organization $organization, ?string $ownerId): void
    {
        if ($ownerId !== $organization->getKey()) {
            abort(404);
        }
    }

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
}
