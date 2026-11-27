<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\EntitlementService;
use App\Services\OperationalIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Operational intelligence for one organization (v1.5.2).
 *
 * Detention trends, facility performance, rejection reasons,
 * missing-evidence trends, broker-level trends, revenue
 * leakage, and deterministic operational recommendations.
 * Every number is computed from the organization's own
 * recorded claims, shipments, payments, and evidence: there
 * is no external benchmark and no industry data. Money is
 * summed as recorded, with no currency conversion. Averages
 * always carry their sample size.
 *
 * Reading needs view-analytics (owner, admin, operations
 * manager, dispatcher, billing staff, viewer). Operational
 * intelligence is a full report, so the Free plan is refused
 * with a 402, the same as the analytics and reports modules.
 */
class OperationalIntelligenceController extends Controller
{
    public function __construct(
        protected OperationalIntelligenceService $operations,
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
     * Validate the optional date range. Both ends default to
     * the last 12 months; date_to must not precede date_from.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    protected function validateRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            'from' => isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : null,
            'to' => isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->startOfDay() : null,
        ];
    }

    /**
     * Authorize an operations read: the permission, then the
     * plan gate for full reports.
     */
    protected function authorizeRead(Request $request): Organization
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewAnalytics->value, $organization);
        $this->entitlements->authorizeReports($organization);

        return $organization;
    }

    /**
     * Detention trend analysis: detention claims only, with
     * the monthly series and a comparison against the
     * preceding window.
     */
    public function detentionTrends(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->detentionTrends($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Facility performance, one row per facility as recorded
     * on the claims' shipments.
     */
    public function facilities(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->facilities($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Rejection reason analysis, one row per recorded reason
     * over the rejected claims in the range.
     */
    public function rejectionReasons(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->rejectionReasons($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Missing-evidence trends: which document categories
     * claims are missing, how many claims are incomplete,
     * and the monthly trend.
     */
    public function missingEvidence(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->missingEvidence($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Broker-level trends, one row per broker with its
     * monthly series and its change against the preceding
     * window.
     */
    public function brokerTrends(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->brokerTrends($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Revenue leakage: the gaps between recorded amounts over
     * the submitted population, under the single v1.5.0
     * balance rule.
     */
    public function revenueLeakage(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->revenueLeakage($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Operational recommendations: fixed rules over the six
     * analyses above, each carrying the numbers it was
     * derived from.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->operations->recommendations($organization, $range['from'], $range['to']),
        ]);
    }
}
