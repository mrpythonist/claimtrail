<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\ClaimAnalyticsService;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Advanced analytics for one organization (v1.5.1).
 *
 * Every metric is computed from the organization's own recorded
 * claims, shipments, and payments: recovery and rejection
 * rates, average submission and payment times, broker, carrier,
 * and event-type performance, and monthly/yearly comparisons.
 * There is no external benchmark and no industry data. Money
 * is summed as recorded, with no currency conversion. Averages
 * always carry their sample size.
 *
 * Reading analytics needs view-analytics (owner, admin,
 * operations manager, dispatcher, billing staff, viewer).
 * Exporting a CSV needs export-reports (viewers can read but
 * cannot export). Analytics is a full report, so the Free
 * plan is refused with a 402, the same as the reports module.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected ClaimAnalyticsService $analytics,
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
     * Authorize an analytics read: the permission, then the
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
     * The overview: rates, averages, counts, money totals,
     * and the period comparison.
     */
    public function overview(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->overview($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Broker performance, one row per broker or customer name.
     */
    public function brokers(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->brokers($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Carrier performance, one row per carrier.
     */
    public function carriers(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->carriers($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Event-type performance, one row per claim category.
     */
    public function eventTypes(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->eventTypes($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * One series point per month in the range.
     */
    public function monthly(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->monthly($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * One series point per year in the range.
     */
    public function yearly(Request $request): JsonResponse
    {
        $organization = $this->authorizeRead($request);
        $range = $this->validateRange($request);

        return response()->json([
            'data' => $this->analytics->yearly($organization, $range['from'], $range['to']),
        ]);
    }

    /**
     * Export one analytics table as CSV.
     *
     * The overview export carries the summary metrics first,
     * then the monthly series under its own header. Exporting
     * needs export-reports; viewers can read analytics but
     * cannot export them.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ExportReports->value, $organization);
        $this->entitlements->authorizeReports($organization);

        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['overview', 'brokers', 'carriers', 'event-types', 'monthly'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $type = $validated['type'] ?? 'overview';
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->startOfDay() : null;

        $table = $this->analytics->exportRows($organization, $type, $from, $to);
        $filename = 'claimtrail-analytics-'.$type.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($table) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $table['headers']);

            foreach ($table['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
