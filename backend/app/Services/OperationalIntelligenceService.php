<?php

namespace App\Services;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\Claim;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Operational intelligence (v1.5.2): the last version of
 * Phase 5. Detention trends, facility performance, rejection
 * reasons, missing-evidence trends, broker-level trends,
 * revenue leakage, and deterministic operational
 * recommendations.
 *
 * Everything is computed from the organization's own recorded
 * claims, shipments, payments, and evidence. There is no
 * external benchmark, no industry data, and no live payment
 * verification: billing runs on a mock provider, so a payment
 * here is a record of what the organization says it
 * received, not a verified transfer.
 *
 * Definitions, enforced here and nowhere else:
 *
 * - Scope: claims created in the requested date range, both
 *   ends inclusive. Defaults to the last 12 months.
 * - Submitted population: claims whose current status is
 *   submitted, accepted, partially accepted, rejected, or
 *   paid, as defined by ClaimAnalyticsService. Claims still
 *   being prepared never enter a rate denominator.
 * - Facility: the shipment's delivery location where one is
 *   recorded, otherwise its pickup location, otherwise
 *   "Unnamed facility". There is no separate facility record
 *   in the app; the location fields are the facility as
 *   recorded.
 * - Rejection reason: the rejection_reason recorded on the
 *   claim. Rejected claims with nothing recorded group under
 *   "Not recorded". No taxonomy is invented.
 * - Missing evidence: the existing readiness computation
 *   (ReadinessService) over the claim's attached evidence
 *   against the organization's configured requirements. A
 *   claim with no configured requirements is not incomplete;
 *   it simply has nothing to be missing.
 * - Revenue leakage: gaps between recorded amounts only.
 *   Requested vs approved is what the broker cut at approval;
 *   approved vs paid is the outstanding balance under the
 *   single v1.5.0 balance rule (ReconciliationService::
 *   balanceFor); overpaid is paid above the amount due;
 *   legacy is money recorded as paid outside the ledger.
 *   None of it is proven lost money: it is the difference
 *   between numbers the organization recorded.
 * - Recommendations: fixed rules over the numbers above.
 *   Each one carries the numbers it was derived from, and
 *   each fires only when a stated minimum sample is met, so
 *   a single claim never produces advice. Severity is a
 *   cutoff chosen for this report, not an industry standard:
 *   'watch' when a rejection rate is at least 50%, a
 *   recovery rate is below 50%, or money is outstanding;
 *   'note' otherwise.
 * - Rates are null when their denominator is zero, never
 *   zero dressed up as a measurement. Every average carries
 *   its sample size. Money is summed as recorded, with no
 *   currency conversion.
 */
class OperationalIntelligenceService extends ClaimAnalyticsService
{
    public function __construct(
        protected ReadinessService $readiness,
        protected ReconciliationService $reconciliation,
    ) {}

    /**
     * The facility a claim happened at, as recorded on its
     * shipment: delivery location first, pickup location
     * second, "Unnamed facility" when neither is recorded.
     */
    public static function facilityOf(Claim $claim): string
    {
        $delivery = trim((string) ($claim->shipment?->delivery_location ?? ''));
        $pickup = trim((string) ($claim->shipment?->pickup_location ?? ''));

        if ($delivery !== '') {
            return $delivery;
        }

        if ($pickup !== '') {
            return $pickup;
        }

        return 'Unnamed facility';
    }

    /**
     * The broker or customer name on a claim's shipment, or
     * "Unnamed broker" when none is recorded.
     */
    public static function brokerOf(Claim $claim): string
    {
        $name = trim((string) ($claim->shipment?->broker_or_customer_name ?? ''));

        return $name !== '' ? $name : 'Unnamed broker';
    }

    /**
     * The rejection reason recorded on a claim, or
     * "Not recorded" when the claim carries none.
     */
    public static function rejectionReasonOf(Claim $claim): string
    {
        $reason = trim((string) ($claim->rejection_reason ?? ''));

        return $reason !== '' ? $reason : 'Not recorded';
    }

    /**
     * The immediately preceding window of the same length as
     * the requested range, both ends inclusive.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function previousRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lengthDays = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay()->startOfDay();
        $previousFrom = $previousTo->subDays($lengthDays - 1)->startOfDay();

        return [$previousFrom, $previousTo];
    }

    /**
     * Totals and rates for a given set of claims, the same
     * shape as the analytics window summary.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<string, mixed>
     */
    protected function summarizeClaims(Collection $claims): array
    {
        $submitted = $this->submittedPopulation($claims);
        $money = $this->moneyTotals($submitted);
        $rates = $this->ratesFor($claims);

        return [
            'claims' => $claims->count(),
            'submitted' => $submitted->count(),
            'paid' => $submitted->where('status', ClaimStatus::Paid)->count(),
            'rejected' => $submitted->where('status', ClaimStatus::Rejected)->count(),
            'requested_amount' => $money['requested_amount'],
            'approved_amount' => $money['approved_amount'],
            'paid_amount' => $money['paid_amount'],
            'outstanding_amount' => $money['outstanding_amount'],
            'recovery_rate_vs_requested' => $rates['recovery_rate_vs_requested'],
            'recovery_rate_vs_approved' => $rates['recovery_rate_vs_approved'],
            'rejection_rate' => $rates['rejection_rate'],
        ];
    }

    /**
     * A comparison between two claim sets: the current window
     * and the preceding window of equal length.
     *
     * @param  Collection<int, Claim>  $current
     * @param  Collection<int, Claim>  $previous
     * @return array<string, mixed>
     */
    protected function comparisonFor(Collection $current, Collection $previous, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom, CarbonImmutable $previousTo): array
    {
        $currentSummary = $this->summarizeClaims($current);
        $previousSummary = $this->summarizeClaims($previous);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $previousFrom->toDateString(),
            'previous_to' => $previousTo->toDateString(),
            'current' => $currentSummary,
            'previous' => $previousSummary,
            'change' => [
                'claims' => $currentSummary['claims'] - $previousSummary['claims'],
                'submitted' => $currentSummary['submitted'] - $previousSummary['submitted'],
                'paid' => $currentSummary['paid'] - $previousSummary['paid'],
                'rejected' => $currentSummary['rejected'] - $previousSummary['rejected'],
                'requested_amount' => number_format((float) bcsub($currentSummary['requested_amount'], $previousSummary['requested_amount'], 2), 2, '.', ''),
                'paid_amount' => number_format((float) bcsub($currentSummary['paid_amount'], $previousSummary['paid_amount'], 2), 2, '.', ''),
                'recovery_rate_vs_requested_pp' => $currentSummary['recovery_rate_vs_requested'] !== null && $previousSummary['recovery_rate_vs_requested'] !== null
                    ? round($currentSummary['recovery_rate_vs_requested'] - $previousSummary['recovery_rate_vs_requested'], 2)
                    : null,
                'rejection_rate_pp' => $currentSummary['rejection_rate'] !== null && $previousSummary['rejection_rate'] !== null
                    ? round($currentSummary['rejection_rate'] - $previousSummary['rejection_rate'], 2)
                    : null,
            ],
        ];
    }

    /**
     * One series point per month in the range, over a given
     * set of claims: claims created, submitted, paid, and
     * rejected in that month, with money and the recovery
     * rate for the month.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<int, array<string, mixed>>
     */
    protected function monthlyPointsFor(Collection $claims, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $points = [];

        foreach (self::monthBuckets($from, $to) as $month) {
            $inMonth = $claims->filter(fn (Claim $claim) => $claim->created_at->format('Y-m') === $month)->values();
            $submitted = $this->submittedPopulation($inMonth);
            $money = $this->moneyTotals($submitted);

            $points[] = [
                'month' => $month,
                'claims_created' => $inMonth->count(),
                'submitted' => $submitted->count(),
                'paid' => $submitted->where('status', ClaimStatus::Paid)->count(),
                'rejected' => $submitted->where('status', ClaimStatus::Rejected)->count(),
                'requested_amount' => $money['requested_amount'],
                'approved_amount' => $money['approved_amount'],
                'paid_amount' => $money['paid_amount'],
                'recovery_rate_vs_requested' => self::ratePercent($money['paid_amount'], $money['requested_amount']),
            ];
        }

        return $points;
    }

    /**
     * Detention trend analysis: detention claims only, with
     * the monthly series, rates, averages, and a comparison
     * against the preceding window of equal length. There is
     * no detention-hours field on a claim, so the trend is
     * over detention claims and their recorded money, not
     * over hours waited.
     *
     * @return array<string, mixed>
     */
    public function detentionTrends(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);

        $detention = $this->claimsInRange($organization, $from, $to)
            ->filter(fn (Claim $claim) => $claim->claim_category === ClaimCategory::Detention)
            ->values();
        $previousDetention = $this->claimsInRange($organization, $previousFrom, $previousTo)
            ->filter(fn (Claim $claim) => $claim->claim_category === ClaimCategory::Detention)
            ->values();

        $submitted = $this->submittedPopulation($detention);
        $money = $this->moneyTotals($submitted);

        return [
            'organization_id' => $organization->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currencies' => $this->currenciesOf($detention),
            'counts' => [
                'claims' => $detention->count(),
                'submitted' => $submitted->count(),
                'paid' => $submitted->where('status', ClaimStatus::Paid)->count(),
                'rejected' => $submitted->where('status', ClaimStatus::Rejected)->count(),
                'accepted' => $submitted->whereIn('status', [ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted])->count(),
            ],
            'rates' => $this->ratesFor($detention),
            'money' => $money,
            'money_by_currency' => $this->moneyByCurrency($submitted),
            ...$this->averageTimes($detention),
            'monthly' => $this->monthlyPointsFor($detention, $from, $to),
            'comparison' => $this->comparisonFor($detention, $previousDetention, $from, $to, $previousFrom, $previousTo),
        ];
    }

    /**
     * Facility performance, one row per facility as recorded
     * on the claims' shipments, most claims first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function facilities(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);

        $grouped = $claims->groupBy(fn (Claim $claim) => self::facilityOf($claim));

        $rows = $grouped->map(fn (Collection $group, string $facility) => array_merge(
            ['facility' => $facility],
            $this->performanceRow($group),
        ))->values();

        return $rows->sortBy([['claims', 'desc'], ['facility', 'asc']])->values()->all();
    }

    /**
     * Rejection reason analysis: one row per recorded reason
     * over the rejected claims in the range, most rejections
     * first. The share is the reason's part of all rejected
     * claims in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rejectionReasons(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);

        $rejected = $claims->filter(fn (Claim $claim) => $claim->status === ClaimStatus::Rejected)->values();
        $totalRejected = $rejected->count();

        $grouped = $rejected->groupBy(fn (Claim $claim) => self::rejectionReasonOf($claim));

        $rows = $grouped->map(function (Collection $group, string $reason) use ($totalRejected) {
            $requested = '0.00';

            foreach ($group as $claim) {
                $requested = bcadd($requested, (string) ($claim->requested_amount ?? '0.00'), 2);
            }

            return [
                'reason' => $reason,
                'rejected' => $group->count(),
                'share_of_rejected' => self::ratePercent((string) $group->count(), (string) $totalRejected),
                'requested_amount' => number_format((float) $requested, 2, '.', ''),
                'currencies' => $this->currenciesOf($group),
            ];
        })->values();

        return $rows->sortBy([['rejected', 'desc'], ['reason', 'asc']])->values()->all();
    }

    /**
     * Claims of one organization created in the range, with
     * the relations the readiness computation needs.
     *
     * @return Collection<int, Claim>
     */
    protected function claimsWithEvidence(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->claimsInRange($organization, $from, $to)
            ->loadMissing(['event', 'evidenceFiles']);
    }

    /**
     * Missing-evidence trends: which document categories
     * claims are missing, how many claims are incomplete,
     * the average readiness, and the monthly trend. A claim
     * counts as incomplete only when it has configured
     * requirements and at least one is still missing.
     *
     * @return array<string, mixed>
     */
    public function missingEvidence(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsWithEvidence($organization, $from, $to);

        $readiness = [];
        $missingCounts = [];
        $missingLabels = [];
        $incomplete = 0;
        $withRequirements = 0;
        $readinessSum = 0;

        foreach ($claims as $claim) {
            $computed = $this->readiness->compute($claim);
            $readiness[$claim->getKey()] = $computed;

            if (! $computed['has_requirements']) {
                continue;
            }

            $withRequirements++;
            $readinessSum += $computed['readiness_percentage'];

            if ($computed['readiness_status'] !== 'ready') {
                $incomplete++;
            }

            foreach ($computed['missing'] as $category) {
                $value = $category['value'];
                $missingCounts[$value] = ($missingCounts[$value] ?? 0) + 1;
                $missingLabels[$value] = $category['label'];
            }
        }

        // Only claims with requirements enter the average; a
        // claim with nothing configured has no readiness to
        // average. Incomplete claims are the ones still
        // missing at least one required category, so a missing
        // category is only ever counted on an incomplete claim.
        $byCategory = collect($missingCounts)->map(function (int $count, string $category) use ($missingLabels, $incomplete) {
            return [
                'category' => $category,
                'category_label' => $missingLabels[$category] ?? $category,
                'claims_missing' => $count,
                'share_of_incomplete' => self::ratePercent((string) $count, (string) $incomplete),
            ];
        })->values()->sortBy([['claims_missing', 'desc'], ['category', 'asc']])->values()->all();

        $monthly = [];

        foreach (self::monthBuckets($from, $to) as $month) {
            $inMonth = $claims->filter(fn (Claim $claim) => $claim->created_at->format('Y-m') === $month)->values();
            $monthWithRequirements = 0;
            $monthIncomplete = 0;
            $monthSum = 0;

            foreach ($inMonth as $claim) {
                $computed = $readiness[$claim->getKey()];

                if (! $computed['has_requirements']) {
                    continue;
                }

                $monthWithRequirements++;
                $monthSum += $computed['readiness_percentage'];

                if ($computed['readiness_status'] !== 'ready') {
                    $monthIncomplete++;
                }
            }

            $monthly[] = [
                'month' => $month,
                'claims_created' => $inMonth->count(),
                'claims_with_requirements' => $monthWithRequirements,
                'incomplete' => $monthIncomplete,
                'average_readiness_percentage' => [
                    'percentage' => $monthWithRequirements > 0
                        ? round($monthSum / $monthWithRequirements, 2)
                        : null,
                    'sample_size' => $monthWithRequirements,
                ],
            ];
        }

        return [
            'organization_id' => $organization->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'claims' => $claims->count(),
            'claims_with_requirements' => $withRequirements,
            'claims_incomplete' => $incomplete,
            'average_readiness_percentage' => [
                'percentage' => $withRequirements > 0
                    ? round($readinessSum / $withRequirements, 2)
                    : null,
                'sample_size' => $withRequirements,
            ],
            'by_category' => $byCategory,
            'monthly' => $monthly,
        ];
    }

    /**
     * Broker-level trends: one row per broker with its
     * monthly series and its change against the preceding
     * window of equal length, most claims first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brokerTrends(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);

        $claims = $this->claimsInRange($organization, $from, $to);
        $previousClaims = $this->claimsInRange($organization, $previousFrom, $previousTo);

        $grouped = $claims->groupBy(fn (Claim $claim) => self::brokerOf($claim));
        $previousGrouped = $previousClaims->groupBy(fn (Claim $claim) => self::brokerOf($claim));

        $rows = $grouped->map(function (Collection $group, string $broker) use ($from, $to, $previousFrom, $previousTo, $previousGrouped) {
            $previousGroup = $previousGrouped->get($broker, collect());
            $comparison = $this->comparisonFor($group, $previousGroup, $from, $to, $previousFrom, $previousTo);

            return array_merge(
                ['broker' => $broker],
                $this->performanceRow($group),
                [
                    'monthly' => $this->monthlyPointsFor($group, $from, $to),
                    'change' => $comparison['change'],
                ],
            );
        })->values();

        return $rows->sortBy([['claims', 'desc'], ['broker', 'asc']])->values()->all();
    }

    /**
     * Revenue leakage: the gaps between recorded amounts
     * over the submitted population, under the single v1.5.0
     * balance rule. Requested vs approved is what the broker
     * cut at approval; approved vs paid is the outstanding
     * balance; overpaid is paid above the amount due; legacy
     * is money recorded as paid outside the ledger. The
     * unsubmitted totals are the pipeline, not leakage:
     * claims still being prepared have no approved amount
     * yet, so their requested total is reported separately.
     *
     * @return array<string, mixed>
     */
    public function revenueLeakage(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);
        $submitted = $this->submittedPopulation($claims);

        $requested = '0.00';
        $approved = '0.00';
        $paid = '0.00';
        $outstanding = '0.00';
        $overpaid = '0.00';
        $legacy = '0.00';
        $requestedVsApprovedGap = '0.00';
        $claimsCut = 0;
        $byStatus = [];
        $largest = [];

        foreach ($submitted as $claim) {
            $balance = $this->reconciliation->balanceFor($claim);

            $requested = bcadd($requested, $balance['requested_amount'], 2);
            $paid = bcadd($paid, $balance['paid_amount'], 2);
            $outstanding = bcadd($outstanding, $balance['outstanding_amount'], 2);
            $legacy = bcadd($legacy, $balance['legacy_amount'], 2);

            if ($balance['approved_amount'] !== null) {
                $approved = bcadd($approved, $balance['approved_amount'], 2);

                if (bccomp($balance['approved_amount'], $balance['requested_amount'], 2) < 0) {
                    $requestedVsApprovedGap = bcadd(
                        $requestedVsApprovedGap,
                        bcsub($balance['requested_amount'], $balance['approved_amount'], 2),
                        2,
                    );
                    $claimsCut++;
                }
            }

            if (bccomp($balance['paid_amount'], $balance['due_amount'], 2) > 0) {
                $overpaid = bcadd(
                    $overpaid,
                    bcsub($balance['paid_amount'], $balance['due_amount'], 2),
                    2,
                );
            }

            $status = $balance['balance_status'];

            if (! isset($byStatus[$status])) {
                $byStatus[$status] = [
                    'balance_status' => $status,
                    'claims' => 0,
                    'requested_amount' => '0.00',
                    'approved_amount' => '0.00',
                    'paid_amount' => '0.00',
                    'outstanding_amount' => '0.00',
                ];
            }

            $byStatus[$status]['claims']++;
            $byStatus[$status]['requested_amount'] = bcadd($byStatus[$status]['requested_amount'], $balance['requested_amount'], 2);
            $byStatus[$status]['paid_amount'] = bcadd($byStatus[$status]['paid_amount'], $balance['paid_amount'], 2);
            $byStatus[$status]['outstanding_amount'] = bcadd($byStatus[$status]['outstanding_amount'], $balance['outstanding_amount'], 2);

            if ($balance['approved_amount'] !== null) {
                $byStatus[$status]['approved_amount'] = bcadd($byStatus[$status]['approved_amount'], $balance['approved_amount'], 2);
            }

            $largest[] = [
                'claim_id' => $claim->getKey(),
                'claim_reference' => $claim->claim_reference,
                'broker' => self::brokerOf($claim),
                'requested_amount' => $balance['requested_amount'],
                'approved_amount' => $balance['approved_amount'],
                'paid_amount' => $balance['paid_amount'],
                'outstanding_amount' => $balance['outstanding_amount'],
            ];
        }

        $unsubmittedRequested = '0.00';
        $unsubmitted = 0;

        foreach ($claims as $claim) {
            if (! in_array($claim->status->value, array_map(fn (ClaimStatus $status) => $status->value, self::SUBMITTED_STATUSES), true)) {
                $unsubmitted++;
                $unsubmittedRequested = bcadd($unsubmittedRequested, (string) ($claim->requested_amount ?? '0.00'), 2);
            }
        }

        usort($largest, fn (array $a, array $b) => bccomp($b['outstanding_amount'], $a['outstanding_amount'], 2));
        $largest = array_values(array_filter($largest, fn (array $row) => bccomp($row['outstanding_amount'], '0.00', 2) > 0));
        $largest = array_slice($largest, 0, 5);

        $byCurrency = [];

        foreach ($submitted->groupBy('currency') as $currency => $group) {
            $currencyRequested = '0.00';
            $currencyApproved = '0.00';
            $currencyPaid = '0.00';
            $currencyOutstanding = '0.00';
            $currencyGap = '0.00';

            foreach ($group as $claim) {
                $balance = $this->reconciliation->balanceFor($claim);
                $currencyRequested = bcadd($currencyRequested, $balance['requested_amount'], 2);
                $currencyPaid = bcadd($currencyPaid, $balance['paid_amount'], 2);
                $currencyOutstanding = bcadd($currencyOutstanding, $balance['outstanding_amount'], 2);

                if ($balance['approved_amount'] !== null) {
                    $currencyApproved = bcadd($currencyApproved, $balance['approved_amount'], 2);

                    if (bccomp($balance['approved_amount'], $balance['requested_amount'], 2) < 0) {
                        $currencyGap = bcadd(
                            $currencyGap,
                            bcsub($balance['requested_amount'], $balance['approved_amount'], 2),
                            2,
                        );
                    }
                }
            }

            $byCurrency[] = [
                'currency' => (string) $currency,
                'requested_amount' => number_format((float) $currencyRequested, 2, '.', ''),
                'approved_amount' => number_format((float) $currencyApproved, 2, '.', ''),
                'paid_amount' => number_format((float) $currencyPaid, 2, '.', ''),
                'outstanding_amount' => number_format((float) $currencyOutstanding, 2, '.', ''),
                'requested_vs_approved_gap' => number_format((float) $currencyGap, 2, '.', ''),
            ];
        }

        $statusOrder = ['unpaid' => 0, 'partial' => 1, 'paid' => 2, 'overpaid' => 3];
        $byStatusRows = array_values($byStatus);
        usort($byStatusRows, fn (array $a, array $b) => ($statusOrder[$a['balance_status']] ?? 9) <=> ($statusOrder[$b['balance_status']] ?? 9));

        foreach ($byStatusRows as &$row) {
            $row['requested_amount'] = number_format((float) $row['requested_amount'], 2, '.', '');
            $row['approved_amount'] = number_format((float) $row['approved_amount'], 2, '.', '');
            $row['paid_amount'] = number_format((float) $row['paid_amount'], 2, '.', '');
            $row['outstanding_amount'] = number_format((float) $row['outstanding_amount'], 2, '.', '');
        }
        unset($row);

        return [
            'organization_id' => $organization->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currencies' => $this->currenciesOf($submitted),
            'submitted_claims' => $submitted->count(),
            'requested_total' => number_format((float) $requested, 2, '.', ''),
            'approved_total' => number_format((float) $approved, 2, '.', ''),
            'paid_total' => number_format((float) $paid, 2, '.', ''),
            'outstanding_total' => number_format((float) $outstanding, 2, '.', ''),
            'requested_vs_approved_gap' => number_format((float) $requestedVsApprovedGap, 2, '.', ''),
            'claims_cut_at_approval' => $claimsCut,
            'overpaid_total' => number_format((float) $overpaid, 2, '.', ''),
            'legacy_total' => number_format((float) $legacy, 2, '.', ''),
            'unsubmitted_claims' => $unsubmitted,
            'unsubmitted_requested_total' => number_format((float) $unsubmittedRequested, 2, '.', ''),
            'by_balance_status' => $byStatusRows,
            'money_by_currency' => $byCurrency,
            'largest_outstanding' => $largest,
        ];
    }

    /**
     * Operational recommendations: fixed rules over the six
     * analyses above. Each recommendation carries the numbers
     * it was derived from, so the reader can check it. A rule
     * fires only when its minimum sample is met; when nothing
     * meets a rule, no recommendation is invented for it.
     *
     * @return array<string, mixed>
     */
    public function recommendations(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);

        $detention = $this->detentionTrends($organization, $from, $to);
        $facilities = $this->facilities($organization, $from, $to);
        $reasons = $this->rejectionReasons($organization, $from, $to);
        $missing = $this->missingEvidence($organization, $from, $to);
        $brokerTrends = $this->brokerTrends($organization, $from, $to);
        $leakage = $this->revenueLeakage($organization, $from, $to);
        $overview = $this->overview($organization, $from, $to);

        $recommendations = [];

        // The broker with the highest rejection rate, over at
        // least three submitted claims. A broker with one
        // rejected claim out of one is not a pattern.
        $worstBroker = null;

        foreach ($brokerTrends as $row) {
            if ($row['submitted'] < 3 || $row['rejection_rate'] === null || $row['rejection_rate'] <= 0) {
                continue;
            }

            if ($worstBroker === null || $row['rejection_rate'] > $worstBroker['rejection_rate']) {
                $worstBroker = $row;
            }
        }

        if ($worstBroker !== null) {
            $recommendations[] = [
                'type' => 'highest_rejection_broker',
                'title' => "Review submissions to {$worstBroker['broker']}",
                'detail' => "{$worstBroker['broker']} rejected {$worstBroker['rejected']} of {$worstBroker['submitted']} submitted claims in this period. Check what those submissions had in common before the next one goes out.",
                'severity' => $worstBroker['rejection_rate'] >= 50 ? 'watch' : 'note',
                'evidence' => [
                    'broker' => $worstBroker['broker'],
                    'rejected' => $worstBroker['rejected'],
                    'submitted' => $worstBroker['submitted'],
                    'rejection_rate' => $worstBroker['rejection_rate'],
                ],
            ];
        }

        // The facility with the lowest recovery rate, over at
        // least three submitted claims, when it recovered less
        // than everything. A facility at 100% needs no review.
        $worstFacility = null;

        foreach ($facilities as $row) {
            if ($row['submitted'] < 3 || $row['recovery_rate_vs_requested'] === null || $row['recovery_rate_vs_requested'] >= 100) {
                continue;
            }

            if ($worstFacility === null || $row['recovery_rate_vs_requested'] < $worstFacility['recovery_rate_vs_requested']) {
                $worstFacility = $row;
            }
        }

        if ($worstFacility !== null) {
            $recommendations[] = [
                'type' => 'lowest_recovery_facility',
                'title' => "Review claims from {$worstFacility['facility']}",
                'detail' => "Claims from {$worstFacility['facility']} recovered {$worstFacility['recovery_rate_vs_requested']}% of what was requested across {$worstFacility['submitted']} submitted claims. Look at what was cut or left unpaid there.",
                'severity' => $worstFacility['recovery_rate_vs_requested'] < 50 ? 'watch' : 'note',
                'evidence' => [
                    'facility' => $worstFacility['facility'],
                    'submitted' => $worstFacility['submitted'],
                    'requested_amount' => $worstFacility['requested_amount'],
                    'paid_amount' => $worstFacility['paid_amount'],
                    'recovery_rate_vs_requested' => $worstFacility['recovery_rate_vs_requested'],
                ],
            ];
        }

        // The most common recorded rejection reason, when at
        // least two claims were rejected.
        $totalRejected = $overview['counts']['rejected'];

        if ($totalRejected >= 2 && count($reasons) > 0) {
            $topReason = $reasons[0];

            $recommendations[] = [
                'type' => 'top_rejection_reason',
                'title' => "Most rejections cite '{$topReason['reason']}'",
                'detail' => "{$topReason['rejected']} of {$totalRejected} rejections in this period cite '{$topReason['reason']}'. Fixing that one cause addresses the largest share of rejections.",
                'severity' => ($topReason['share_of_rejected'] ?? 0) >= 50 ? 'watch' : 'note',
                'evidence' => [
                    'reason' => $topReason['reason'],
                    'rejected' => $topReason['rejected'],
                    'total_rejected' => $totalRejected,
                    'share_of_rejected' => $topReason['share_of_rejected'],
                ],
            ];
        }

        // The document category most claims are missing, when
        // at least one claim is incomplete.
        if ($missing['claims_incomplete'] >= 1 && count($missing['by_category']) > 0) {
            $topMissing = $missing['by_category'][0];

            $recommendations[] = [
                'type' => 'most_missing_evidence',
                'title' => "Attach the missing {$topMissing['category_label']}",
                'detail' => "{$topMissing['claims_missing']} of {$missing['claims_incomplete']} incomplete claims are missing the {$topMissing['category_label']}. Attaching it before submission is the single largest evidence gap.",
                'severity' => ($topMissing['share_of_incomplete'] ?? 0) >= 50 ? 'watch' : 'note',
                'evidence' => [
                    'category' => $topMissing['category'],
                    'category_label' => $topMissing['category_label'],
                    'claims_missing' => $topMissing['claims_missing'],
                    'claims_incomplete' => $missing['claims_incomplete'],
                    'share_of_incomplete' => $topMissing['share_of_incomplete'],
                ],
            ];
        }

        // Money still outstanding on submitted claims.
        if (bccomp($leakage['outstanding_total'], '0.00', 2) > 0) {
            $largest = $leakage['largest_outstanding'][0] ?? null;
            $detail = "{$leakage['outstanding_total']} is still outstanding across {$leakage['submitted_claims']} submitted claims in this period.";

            if ($largest !== null) {
                $detail .= " The largest single balance is claim {$largest['claim_reference']} at {$largest['outstanding_amount']}.";
            }

            $recommendations[] = [
                'type' => 'largest_outstanding',
                'title' => 'Follow up the outstanding balances',
                'detail' => $detail,
                'severity' => 'watch',
                'evidence' => [
                    'outstanding_total' => $leakage['outstanding_total'],
                    'submitted_claims' => $leakage['submitted_claims'],
                    'largest_outstanding' => $largest,
                ],
            ];
        }

        // Detention claims rising against the preceding window.
        $detentionChange = $detention['comparison']['change']['claims'];

        if ($detentionChange > 0) {
            $recommendations[] = [
                'type' => 'detention_trend_rising',
                'title' => 'Detention claims are rising',
                'detail' => "Detention claims rose from {$detention['comparison']['previous']['claims']} in the preceding window to {$detention['comparison']['current']['claims']} in this period. Check whether particular facilities or brokers account for the increase.",
                'severity' => 'note',
                'evidence' => [
                    'current_claims' => $detention['comparison']['current']['claims'],
                    'previous_claims' => $detention['comparison']['previous']['claims'],
                    'change' => $detentionChange,
                ],
            ];
        }

        // Detention claims rejected more often than claims
        // overall, over at least three submitted detention
        // claims.
        $detentionRejection = $detention['rates']['rejection_rate'];
        $overallRejection = $overview['rates']['rejection_rate'];

        if (
            $detention['counts']['submitted'] >= 3
            && $detentionRejection !== null
            && $overallRejection !== null
            && $detentionRejection > $overallRejection
        ) {
            $recommendations[] = [
                'type' => 'detention_rejection_above_average',
                'title' => 'Detention claims are rejected more often than average',
                'detail' => "Detention claims were rejected at {$detentionRejection}% against {$overallRejection}% overall. Review the detention submission package before the next detention claim.",
                'severity' => $detentionRejection >= 50 ? 'watch' : 'note',
                'evidence' => [
                    'detention_rejection_rate' => $detentionRejection,
                    'overall_rejection_rate' => $overallRejection,
                    'detention_submitted' => $detention['counts']['submitted'],
                ],
            ];
        }

        // The broker whose recovery fell furthest against the
        // preceding window: at least ten points down, over at
        // least three submitted claims this period.
        $worstFall = null;

        foreach ($brokerTrends as $row) {
            $fall = $row['change']['recovery_rate_vs_requested_pp'] ?? null;

            if ($fall === null || $fall > -10 || $row['submitted'] < 3) {
                continue;
            }

            if ($worstFall === null || $fall < $worstFall['change']['recovery_rate_vs_requested_pp']) {
                $worstFall = $row;
            }
        }

        if ($worstFall !== null) {
            $recommendations[] = [
                'type' => 'broker_recovery_fell',
                'title' => "Recovery from {$worstFall['broker']} fell sharply",
                'detail' => "Recovery from {$worstFall['broker']} fell {$worstFall['change']['recovery_rate_vs_requested_pp']} points against the preceding window, to {$worstFall['recovery_rate_vs_requested']}%. Check what changed with that broker.",
                'severity' => 'watch',
                'evidence' => [
                    'broker' => $worstFall['broker'],
                    'recovery_rate_vs_requested' => $worstFall['recovery_rate_vs_requested'],
                    'recovery_rate_change_pp' => $worstFall['change']['recovery_rate_vs_requested_pp'],
                    'submitted' => $worstFall['submitted'],
                ],
            ];
        }

        return [
            'organization_id' => $organization->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_from' => 'Recorded claims, shipments, payments, and evidence only. Each recommendation states the numbers it was derived from; nothing here is an industry benchmark or a guarantee.',
            'recommendations' => $recommendations,
        ];
    }
}
