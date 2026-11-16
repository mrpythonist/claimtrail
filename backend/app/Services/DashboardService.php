<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operational dashboard overview.
 *
 * Counts and money for one organization. Money figures come from
 * ReportService so the dashboard and the reports page can never
 * disagree about what potential, submitted, accepted, and recovered
 * mean. Potential money is estimated money and is labeled as such;
 * it is never presented as recovered.
 *
 * Date filters apply to shipment and claim created_at. The status
 * filter does not rewrite the named counts; it adds a
 * claims_filtered section for the chosen status so the frontend
 * can show "submitted claims: 4, requested $1,250.00" alongside
 * the full picture.
 */
class DashboardService
{
    public function __construct(
        protected ReportService $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(
        Organization $organization,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?ClaimStatus $status = null,
    ): array {
        $shipments = $this->shipmentsInRange($organization, $dateFrom, $dateTo);
        $claims = $this->claimsInRange($organization, $dateFrom, $dateTo);

        $data = [
            'counts' => [
                'members_total' => $organization->members()->count(),
                'members_active' => $organization->activeMembers()->count(),
                'members_inactive' => $organization->members()->where('status', 'inactive')->count(),
                'invitations_pending' => $organization->pendingInvitations()->count(),
                'shipments_total' => (clone $shipments)->count(),
                'shipments_with_events' => (clone $shipments)->whereHas('events')->count(),
                'shipments_with_opportunities' => (clone $shipments)
                    ->whereHas('events', fn ($query) => $query->where('status', 'potential'))
                    ->count(),
                'events_open' => $organization->shipmentEvents()->where('status', 'potential')->count(),
                'events_total' => $organization->shipmentEvents()->count(),
                'evidence_total' => $organization->evidenceFiles()->count(),
                'claims_total' => (clone $claims)->count(),
                'claims_open' => (clone $claims)->whereIn('status', [
                    'potential', 'evidence_required', 'under_review', 'ready_to_submit',
                ])->count(),
                'claims_open_opportunities' => (clone $claims)->whereIn('status', [
                    'potential', 'evidence_required', 'under_review',
                ])->count(),
                'claims_awaiting_evidence' => (clone $claims)->where('status', 'evidence_required')->count(),
                'claims_ready' => (clone $claims)->where('status', 'ready_to_submit')->count(),
                'claims_submitted' => (clone $claims)->where('status', 'submitted')->count(),
                'claims_approved' => (clone $claims)->whereIn('status', ['accepted', 'partially_accepted'])->count(),
                'claims_rejected' => (clone $claims)->where('status', 'rejected')->count(),
                'claims_paid' => (clone $claims)->where('status', 'paid')->count(),
                'claims_closed' => (clone $claims)->where('status', 'closed')->count(),
                'deadlines_upcoming' => $organization->claimDeadlines()->active()->where('status', 'upcoming')->count(),
                'deadlines_due_soon' => $organization->claimDeadlines()->active()->where('status', 'due_soon')->count(),
                'deadlines_overdue' => $organization->claimDeadlines()->active()->where('status', 'overdue')->count(),
                'deadlines_next_14_days' => $organization->claimDeadlines()->active()
                    ->where('deadline_at', '<=', now()->addDays(14))
                    ->count(),
            ],
            'claims_by_status' => $this->claimsByStatus($claims),
            'financials' => $this->reports->moneyFigures($organization, $dateFrom, $dateTo),
            'upcoming_deadlines' => $this->upcomingDeadlines($organization),
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'status' => $status?->value,
            ],
        ];

        if ($status !== null) {
            $data['claims_filtered'] = $this->filteredClaims($claims, $status);
        }

        return $data;
    }

    /**
     * Shipments of the organization inside the date range (by
     * created_at). A null bound means open-ended.
     */
    protected function shipmentsInRange(Organization $organization, ?string $dateFrom, ?string $dateTo): Builder
    {
        return $organization->shipments()->getQuery()
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('created_at', '<=', $dateTo));
    }

    /**
     * Claims of the organization inside the date range (by
     * created_at). A null bound means open-ended.
     */
    protected function claimsInRange(Organization $organization, ?string $dateFrom, ?string $dateTo): Builder
    {
        return $organization->claims()->getQuery()
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('created_at', '<=', $dateTo));
    }

    /**
     * Claim counts for every status, zero-filled.
     *
     * @return array<int, array{status: string, label: string, count: int}>
     */
    protected function claimsByStatus(Builder $claims): array
    {
        $counts = (clone $claims)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return array_map(function (ClaimStatus $status) use ($counts) {
            return [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }, ClaimStatus::cases());
    }

    /**
     * The next five active deadlines due within 14 days, soonest
     * first. The list links into the claim from the frontend.
     *
     * @return array<int, array{deadline_id: string, claim_id: string, claim_reference: string, deadline_at: string, status: string, status_label: string}>
     */
    protected function upcomingDeadlines(Organization $organization): array
    {
        return $organization->claimDeadlines()->active()
            ->with('claim:id,claim_reference')
            ->where('deadline_at', '<=', now()->addDays(14))
            ->orderBy('deadline_at')
            ->limit(5)
            ->get()
            ->map(fn ($deadline) => [
                'deadline_id' => $deadline->getKey(),
                'claim_id' => $deadline->claim_id,
                'claim_reference' => $deadline->claim?->claim_reference,
                'deadline_at' => $deadline->deadline_at->toISOString(),
                'status' => $deadline->status->value,
                'status_label' => $deadline->status->label(),
            ])
            ->all();
    }

    /**
     * Counts and requested money for one claim status, for the
     * status filter. Money is per currency, never mixed.
     *
     * @return array{status: string, label: string, count: int, requested_amounts: array<int, array{currency: string, amount: string}>}
     */
    protected function filteredClaims(Builder $claims, ClaimStatus $status): array
    {
        $filtered = (clone $claims)->where('status', $status->value);

        $amounts = (clone $filtered)
            ->selectRaw('currency, SUM(requested_amount) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => strtoupper((string) $row->currency),
                'amount' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->all();

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (clone $filtered)->count(),
            'requested_amounts' => $amounts,
        ];
    }
}
