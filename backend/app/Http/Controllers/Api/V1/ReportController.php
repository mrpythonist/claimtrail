<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organization-level claim reports.
 *
 * Money figures come from ReportService and are always per
 * currency: no conversion, no invented exchange rates. Viewing
 * needs the report view permission; exporting needs the export
 * permission (viewers can read reports but cannot export them).
 */
class ReportController extends Controller
{
    /**
     * The summary: counts by status and event type, and the six
     * money figures per currency.
     */
    public function summary(Request $request, ReportService $reports): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewReports->value, $organization);

        $validated = $this->validateRange($request);

        return response()->json([
            'data' => $reports->summary(
                $organization,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    /**
     * Recovered money per month per currency.
     */
    public function recoveryHistory(Request $request, ReportService $reports): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewReports->value, $organization);

        $validated = $this->validateRange($request);

        return response()->json([
            'data' => $reports->recoveryHistory(
                $organization,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    /**
     * Export the summary and the per-claim rows as one CSV.
     *
     * The file has two sections: summary rows (one per money figure
     * per currency) and then the per-claim rows under their own
     * header. Claims stream with a cursor so a large export does
     * not load every claim into memory at once.
     */
    public function export(Request $request, ReportService $reports): StreamedResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ExportReports->value, $organization);

        $validated = $this->validateRange($request);
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;

        $summary = $reports->summary($organization, $dateFrom, $dateTo);
        $filename = 'claimtrail-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($reports, $organization, $summary, $dateFrom, $dateTo) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['section', 'metric', 'currency', 'amount']);

            foreach ($summary['money'] as $metric => $rows) {
                if (empty($rows)) {
                    fputcsv($handle, ['summary', $metric, '', '0.00']);
                    continue;
                }

                foreach ($rows as $row) {
                    fputcsv($handle, ['summary', $metric, $row['currency'], $row['amount']]);
                }
            }

            foreach ($summary['counts_by_status'] as $row) {
                fputcsv($handle, ['claims_by_status', $row['status'], '', (string) $row['count']]);
            }

            foreach ($summary['counts_by_event_type'] as $row) {
                fputcsv($handle, ['claims_by_event_type', $row['category'], '', (string) $row['count']]);
            }

            fputcsv($handle, []);

            fputcsv($handle, [
                'claim_reference',
                'shipment_reference',
                'broker_or_customer_name',
                'claim_category',
                'status',
                'requested_amount',
                'accepted_amount',
                'recovered_amount',
                'currency',
                'created_at',
                'submitted_at',
                'paid_at',
            ]);

            $reports->exportClaims($organization, $dateFrom, $dateTo)
                ->each(function ($claim) use ($handle) {
                    fputcsv($handle, [
                        $claim->claim_reference,
                        $claim->shipment?->internal_reference,
                        $claim->shipment?->broker_or_customer_name,
                        $claim->claim_category->value,
                        $claim->status->value,
                        $claim->requested_amount,
                        $claim->accepted_amount,
                        $claim->recovered_amount,
                        $claim->currency,
                        $claim->created_at->format('Y-m-d'),
                        $claim->submitted_at?->format('Y-m-d'),
                        $claim->paid_at?->format('Y-m-d'),
                    ]);
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Validate the optional date range. date_to must not precede
     * date_from.
     *
     * @return array{date_from?: string, date_to?: string}
     */
    protected function validateRange(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
