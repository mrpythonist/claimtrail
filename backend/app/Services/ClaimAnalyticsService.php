<?php

namespace App\Services;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\Claim;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Advanced analytics (v1.5.1): recovery and rejection rates,
 * average submission and payment times, broker, carrier, and
 * event-type performance, and monthly/yearly comparisons.
 *
 * Everything is computed from the organization's own recorded
 * claims, shipments, and payments. There is no external
 * benchmark, no industry data, and no live payment verification:
 * billing runs on a mock provider, so a payment here is a
 * record of what the organization says it received, not a
 * verified transfer.
 *
 * Definitions, enforced here and nowhere else:
 *
 * - Scope: claims created in the requested date range, both
 *   ends inclusive. Defaults to the last 12 months.
 * - Submitted population: claims whose current status is
 *   submitted, accepted, partially accepted, rejected, or
 *   paid. Claims still being prepared (potential, evidence
 *   required, under review, ready to submit) never enter a
 *   rate denominator, because they were never submitted.
 * - Recovery rate vs requested: paid (sum of recovered_amount
 *   over the submitted population) / requested (sum of
 *   requested_amount over the same population), as a percent.
 * - Recovery rate vs approved: paid / approved (sum of
 *   accepted_amount over claims in the population that have
 *   one), as a percent.
 * - Rejection rate: rejected claims / submitted population,
 *   as a percent. A claim rejected and later reworked counts
 *   under its current status, the same as the report counts.
 * - Average submission time: submitted_at minus created_at,
 *   in days, over claims that have a submitted_at.
 * - Average payment time: paid_at minus approved_at where an
 *   approved_at exists, otherwise paid_at minus submitted_at,
 *   in days, over claims that have a paid_at.
 * - Money is summed as recorded, with no currency conversion
 *   and no invented exchange rates (the same convention as
 *   the reconciliation totals in v1.5.0). Every money total
 *   is accompanied by the currencies it mixes, and every
 *   average carries its sample size, so a rate over three
 *   claims never looks like a rate over three hundred.
 * - Rates are null when their denominator is zero, never
 *   zero dressed up as a measurement.
 */
class ClaimAnalyticsService
{
    /**
     * Statuses that count as having reached submission.
     */
    public const SUBMITTED_STATUSES = [
        ClaimStatus::Submitted,
        ClaimStatus::Accepted,
        ClaimStatus::PartiallyAccepted,
        ClaimStatus::Rejected,
        ClaimStatus::Paid,
    ];

    /**
     * Resolve the date range, defaulting to the last 12 months.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function resolveRange(?CarbonImmutable $from, ?CarbonImmutable $to, int $monthsBack = 12): array
    {
        $to ??= now()->toImmutable()->startOfDay();
        $from ??= $to->subMonths($monthsBack)->startOfMonth();

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /**
     * Claims of one organization created in the range, with
     * their shipment and carrier eager-loaded.
     *
     * @return Collection<int, Claim>
     */
    protected function claimsInRange(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Claim::forOrganization($organization)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->with(['shipment.carrier'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The submitted population: claims that reached submission.
     *
     * @param  Collection<int, Claim>  $claims
     * @return Collection<int, Claim>
     */
    protected function submittedPopulation(Collection $claims): Collection
    {
        $statuses = array_map(fn (ClaimStatus $status) => $status->value, self::SUBMITTED_STATUSES);

        return $claims->filter(fn (Claim $claim) => in_array($claim->status->value, $statuses, true))->values();
    }

    /**
     * A rate as a percent with two decimals, or null when the
     * denominator is zero. Exact decimal math, no floats: the
     * division runs at six decimals so the final round to two
     * is a real round, not a truncation (400/600 is 66.67,
     * not 66.66).
     */
    public static function ratePercent(string $numerator, string $denominator): ?float
    {
        if (bccomp($denominator, '0.00', 2) === 0) {
            return null;
        }

        return round((float) bcmul(bcdiv($numerator, $denominator, 6), '100', 6), 2);
    }

    /**
     * The mean of durations in seconds, in days with two
     * decimals, or null when there is nothing to average.
     *
     * @param  array<int, int|float>  $seconds
     */
    public static function averageDays(array $seconds): ?float
    {
        $count = count($seconds);

        if ($count === 0) {
            return null;
        }

        return round(array_sum($seconds) / $count / 86400, 2);
    }

    /**
     * Month buckets (YYYY-MM) from the from month to the to
     * month, inclusive.
     *
     * @return array<int, string>
     */
    public static function monthBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $buckets = [];
        $cursor = $from->startOfMonth();

        while ($cursor <= $to->startOfMonth()) {
            $buckets[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $buckets;
    }

    /**
     * Year buckets (YYYY) from the from year to the to year,
     * inclusive.
     *
     * @return array<int, string>
     */
    public static function yearBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $buckets = [];

        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            $buckets[] = (string) $year;
        }

        return $buckets;
    }

    /**
     * Money sums over a set of claims, as recorded. The paid
     * total is the sum of recovered_amount, the approved total
     * the sum of accepted_amount where one is recorded.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<string, string>
     */
    protected function moneyTotals(Collection $claims): array
    {
        $requested = '0.00';
        $approved = '0.00';
        $paid = '0.00';

        foreach ($claims as $claim) {
            $requested = bcadd($requested, (string) ($claim->requested_amount ?? '0.00'), 2);
            $approved = bcadd($approved, (string) ($claim->accepted_amount ?? '0.00'), 2);
            $paid = bcadd($paid, (string) ($claim->recovered_amount ?? '0.00'), 2);
        }

        $due = $approved !== '0.00' ? $approved : $requested;
        $outstanding = bccomp($paid, $due, 2) >= 0 ? '0.00' : bcsub($due, $paid, 2);

        return [
            'requested_amount' => number_format((float) $requested, 2, '.', ''),
            'approved_amount' => number_format((float) $approved, 2, '.', ''),
            'paid_amount' => number_format((float) $paid, 2, '.', ''),
            'outstanding_amount' => number_format((float) $outstanding, 2, '.', ''),
        ];
    }

    /**
     * Money totals per currency, one row per currency present.
     * No conversion: each currency is summed on its own.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<int, array<string, string>>
     */
    protected function moneyByCurrency(Collection $claims): array
    {
        $byCurrency = [];

        foreach ($claims->groupBy('currency') as $currency => $group) {
            $byCurrency[] = array_merge(
                ['currency' => (string) $currency],
                $this->moneyTotals($group),
            );
        }

        return $byCurrency;
    }

    /**
     * The currencies mixed into a set of claims, sorted.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<int, string>
     */
    protected function currenciesOf(Collection $claims): array
    {
        return $claims->pluck('currency')->unique()->sort()->values()->all();
    }

    /**
     * Average submission and payment times over a set of
     * claims, each with its sample size.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<string, array{days: float|null, sample_size: int}>
     */
    protected function averageTimes(Collection $claims): array
    {
        $submissionSeconds = [];
        $paymentSeconds = [];

        foreach ($claims as $claim) {
            if ($claim->submitted_at !== null) {
                $submissionSeconds[] = $claim->submitted_at->getTimestamp() - $claim->created_at->getTimestamp();
            }

            if ($claim->paid_at !== null) {
                $start = $claim->approved_at ?? $claim->submitted_at;

                if ($start !== null) {
                    $paymentSeconds[] = $claim->paid_at->getTimestamp() - $start->getTimestamp();
                }
            }
        }

        return [
            'average_submission_days' => [
                'days' => self::averageDays($submissionSeconds),
                'sample_size' => count($submissionSeconds),
            ],
            'average_payment_days' => [
                'days' => self::averageDays($paymentSeconds),
                'sample_size' => count($paymentSeconds),
            ],
        ];
    }

    /**
     * The rate block for a set of claims: recovery vs
     * requested, recovery vs approved, and rejection, each
     * null when its denominator is zero.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<string, float|null>
     */
    protected function ratesFor(Collection $claims): array
    {
        $submitted = $this->submittedPopulation($claims);
        $money = $this->moneyTotals($submitted);

        $approvedBase = $submitted->filter(fn (Claim $claim) => $claim->accepted_amount !== null);
        $approvedMoney = $this->moneyTotals($approvedBase);

        return [
            'recovery_rate_vs_requested' => self::ratePercent($money['paid_amount'], $money['requested_amount']),
            'recovery_rate_vs_approved' => self::ratePercent($money['paid_amount'], $approvedMoney['approved_amount']),
            'rejection_rate' => $submitted->count() > 0
                ? round($submitted->where('status', ClaimStatus::Rejected)->count() / $submitted->count() * 100, 2)
                : null,
        ];
    }

    /**
     * The overview: rates, averages, counts, money totals,
     * and the period comparison against the immediately
     * preceding window of equal length.
     *
     * @return array<string, mixed>
     */
    public function overview(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);
        $submitted = $this->submittedPopulation($claims);
        $money = $this->moneyTotals($submitted);

        return [
            'organization_id' => $organization->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currencies' => $this->currenciesOf($claims),
            'counts' => [
                'claims' => $claims->count(),
                'submitted' => $submitted->count(),
                'paid' => $submitted->where('status', ClaimStatus::Paid)->count(),
                'rejected' => $submitted->where('status', ClaimStatus::Rejected)->count(),
                'accepted' => $submitted->whereIn('status', [ClaimStatus::Accepted, ClaimStatus::PartiallyAccepted])->count(),
            ],
            'rates' => $this->ratesFor($claims),
            'money' => $money,
            'money_by_currency' => $this->moneyByCurrency($submitted),
            ...$this->averageTimes($claims),
            'comparison' => $this->periodComparison($organization, $from, $to),
        ];
    }

    /**
     * One row of group performance: counts, money, rates, and
     * the average payment time with its sample size.
     *
     * @param  Collection<int, Claim>  $claims
     * @return array<string, mixed>
     */
    protected function performanceRow(Collection $claims): array
    {
        $submitted = $this->submittedPopulation($claims);
        $money = $this->moneyTotals($submitted);
        $rates = $this->ratesFor($claims);
        $times = $this->averageTimes($claims);

        return [
            'claims' => $claims->count(),
            'submitted' => $submitted->count(),
            'paid' => $submitted->where('status', ClaimStatus::Paid)->count(),
            'rejected' => $submitted->where('status', ClaimStatus::Rejected)->count(),
            'requested_amount' => $money['requested_amount'],
            'approved_amount' => $money['approved_amount'],
            'paid_amount' => $money['paid_amount'],
            'outstanding_amount' => $money['outstanding_amount'],
            'currencies' => $this->currenciesOf($claims),
            'recovery_rate_vs_requested' => $rates['recovery_rate_vs_requested'],
            'rejection_rate' => $rates['rejection_rate'],
            'average_payment_days' => $times['average_payment_days'],
        ];
    }

    /**
     * Broker performance, one row per broker or customer name
     * on the claims' shipments, most claims first. A shipment
     * with no broker name groups under "Unnamed broker".
     *
     * @return array<int, array<string, mixed>>
     */
    public function brokers(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);

        $grouped = $claims->groupBy(function (Claim $claim) {
            $name = trim((string) ($claim->shipment?->broker_or_customer_name ?? ''));

            return $name !== '' ? $name : 'Unnamed broker';
        });

        $rows = $grouped->map(fn (Collection $group, string $broker) => array_merge(
            ['broker' => $broker],
            $this->performanceRow($group),
        ))->values();

        return $rows->sortBy([['claims', 'desc'], ['broker', 'asc']])->values()->all();
    }

    /**
     * Carrier performance, one row per carrier, most claims
     * first. Shipments with no carrier group under
     * "No carrier assigned" with a null carrier id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function carriers(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);

        $grouped = $claims->groupBy(function (Claim $claim) {
            return $claim->shipment?->carrier_id ?? 'none';
        });

        $rows = $grouped->map(function (Collection $group, string $key) {
            $first = $group->first();

            return array_merge([
                'carrier_id' => $key === 'none' ? null : $key,
                'carrier' => $first?->shipment?->carrier?->name ?? 'No carrier assigned',
            ], $this->performanceRow($group));
        })->values();

        return $rows->sortBy([['claims', 'desc'], ['carrier', 'asc']])->values()->all();
    }

    /**
     * Event-type performance, one row per claim category,
     * including categories with no claims in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventTypes(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);
        $grouped = $claims->groupBy(fn (Claim $claim) => $claim->claim_category->value);

        $rows = [];

        foreach (ClaimCategory::cases() as $category) {
            $group = $grouped->get($category->value, collect());

            $rows[] = array_merge([
                'category' => $category->value,
                'category_label' => $category->label(),
            ], $this->performanceRow($group));
        }

        return $rows;
    }

    /**
     * One series point per month in the range: claims created,
     * submitted, paid, and rejected in that month, with money
     * and the recovery rate for the month.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $claims = $this->claimsInRange($organization, $from, $to);

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
     * One series point per year in the range, with the same
     * shape as the monthly series. Defaults to the last three
     * years when no range is given.
     *
     * @return array<int, array<string, mixed>>
     */
    public function yearly(Organization $organization, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($from === null && $to === null) {
            $to = now()->toImmutable()->startOfDay();
            $from = $to->subYears(2)->startOfYear();
        }

        [$from, $to] = $this->resolveRange($from, $to, 12);
        $claims = $this->claimsInRange($organization, $from, $to);

        $points = [];

        foreach (self::yearBuckets($from, $to) as $year) {
            $inYear = $claims->filter(fn (Claim $claim) => $claim->created_at->format('Y') === $year)->values();
            $submitted = $this->submittedPopulation($inYear);
            $money = $this->moneyTotals($submitted);

            $points[] = [
                'year' => $year,
                'claims_created' => $inYear->count(),
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
     * The requested range against the immediately preceding
     * window of equal length: the same totals and rates for
     * both, with the change between them. Rate changes are in
     * percentage points; money changes are signed decimals.
     *
     * @return array<string, mixed>
     */
    public function periodComparison(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lengthDays = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay()->startOfDay();
        $previousFrom = $previousTo->subDays($lengthDays - 1)->startOfDay();

        $current = $this->summarizeWindow($organization, $from, $to);
        $previous = $this->summarizeWindow($organization, $previousFrom, $previousTo);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $previousFrom->toDateString(),
            'previous_to' => $previousTo->toDateString(),
            'current' => $current,
            'previous' => $previous,
            'change' => [
                'claims' => $current['claims'] - $previous['claims'],
                'submitted' => $current['submitted'] - $previous['submitted'],
                'paid' => $current['paid'] - $previous['paid'],
                'rejected' => $current['rejected'] - $previous['rejected'],
                'requested_amount' => number_format((float) bcsub($current['requested_amount'], $previous['requested_amount'], 2), 2, '.', ''),
                'paid_amount' => number_format((float) bcsub($current['paid_amount'], $previous['paid_amount'], 2), 2, '.', ''),
                'recovery_rate_vs_requested_pp' => $current['recovery_rate_vs_requested'] !== null && $previous['recovery_rate_vs_requested'] !== null
                    ? round($current['recovery_rate_vs_requested'] - $previous['recovery_rate_vs_requested'], 2)
                    : null,
                'rejection_rate_pp' => $current['rejection_rate'] !== null && $previous['rejection_rate'] !== null
                    ? round($current['rejection_rate'] - $previous['rejection_rate'], 2)
                    : null,
            ],
        ];
    }

    /**
     * Totals and rates for one window, for the comparison.
     *
     * @return array<string, mixed>
     */
    protected function summarizeWindow(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $claims = $this->claimsInRange($organization, $from, $to);
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
     * CSV rows for one export type: headers plus rows of
     * plain strings. The overview export carries the summary
     * metrics and the monthly series; the other types export
     * their own table.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function exportRows(Organization $organization, string $type, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        [$from, $to] = $this->resolveRange($from, $to);

        return match ($type) {
            'brokers' => [
                'headers' => ['broker', 'claims', 'submitted', 'paid', 'rejected', 'requested_amount', 'approved_amount', 'paid_amount', 'outstanding_amount', 'recovery_rate_vs_requested', 'rejection_rate', 'average_payment_days', 'average_payment_sample_size'],
                'rows' => array_map(fn (array $row) => [
                    $row['broker'],
                    (string) $row['claims'],
                    (string) $row['submitted'],
                    (string) $row['paid'],
                    (string) $row['rejected'],
                    $row['requested_amount'],
                    $row['approved_amount'],
                    $row['paid_amount'],
                    $row['outstanding_amount'],
                    $row['recovery_rate_vs_requested'] !== null ? (string) $row['recovery_rate_vs_requested'] : '',
                    $row['rejection_rate'] !== null ? (string) $row['rejection_rate'] : '',
                    $row['average_payment_days']['days'] !== null ? (string) $row['average_payment_days']['days'] : '',
                    (string) $row['average_payment_days']['sample_size'],
                ], $this->brokers($organization, $from, $to)),
            ],
            'carriers' => [
                'headers' => ['carrier', 'claims', 'submitted', 'paid', 'rejected', 'requested_amount', 'approved_amount', 'paid_amount', 'outstanding_amount', 'recovery_rate_vs_requested', 'rejection_rate', 'average_payment_days', 'average_payment_sample_size'],
                'rows' => array_map(fn (array $row) => [
                    $row['carrier'],
                    (string) $row['claims'],
                    (string) $row['submitted'],
                    (string) $row['paid'],
                    (string) $row['rejected'],
                    $row['requested_amount'],
                    $row['approved_amount'],
                    $row['paid_amount'],
                    $row['outstanding_amount'],
                    $row['recovery_rate_vs_requested'] !== null ? (string) $row['recovery_rate_vs_requested'] : '',
                    $row['rejection_rate'] !== null ? (string) $row['rejection_rate'] : '',
                    $row['average_payment_days']['days'] !== null ? (string) $row['average_payment_days']['days'] : '',
                    (string) $row['average_payment_days']['sample_size'],
                ], $this->carriers($organization, $from, $to)),
            ],
            'event-types' => [
                'headers' => ['category', 'category_label', 'claims', 'submitted', 'paid', 'rejected', 'requested_amount', 'approved_amount', 'paid_amount', 'outstanding_amount', 'recovery_rate_vs_requested', 'rejection_rate', 'average_payment_days', 'average_payment_sample_size'],
                'rows' => array_map(fn (array $row) => [
                    $row['category'],
                    $row['category_label'],
                    (string) $row['claims'],
                    (string) $row['submitted'],
                    (string) $row['paid'],
                    (string) $row['rejected'],
                    $row['requested_amount'],
                    $row['approved_amount'],
                    $row['paid_amount'],
                    $row['outstanding_amount'],
                    $row['recovery_rate_vs_requested'] !== null ? (string) $row['recovery_rate_vs_requested'] : '',
                    $row['rejection_rate'] !== null ? (string) $row['rejection_rate'] : '',
                    $row['average_payment_days']['days'] !== null ? (string) $row['average_payment_days']['days'] : '',
                    (string) $row['average_payment_days']['sample_size'],
                ], $this->eventTypes($organization, $from, $to)),
            ],
            'monthly' => [
                'headers' => ['month', 'claims_created', 'submitted', 'paid', 'rejected', 'requested_amount', 'approved_amount', 'paid_amount', 'recovery_rate_vs_requested'],
                'rows' => array_map(fn (array $point) => [
                    $point['month'],
                    (string) $point['claims_created'],
                    (string) $point['submitted'],
                    (string) $point['paid'],
                    (string) $point['rejected'],
                    $point['requested_amount'],
                    $point['approved_amount'],
                    $point['paid_amount'],
                    $point['recovery_rate_vs_requested'] !== null ? (string) $point['recovery_rate_vs_requested'] : '',
                ], $this->monthly($organization, $from, $to)),
            ],
            default => $this->overviewExportRows($organization, $from, $to),
        };
    }

    /**
     * The overview export: summary metrics first, then the
     * monthly series under its own header.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    protected function overviewExportRows(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $overview = $this->overview($organization, $from, $to);
        $rows = [];

        foreach ([
            ['claims', (string) $overview['counts']['claims']],
            ['submitted', (string) $overview['counts']['submitted']],
            ['paid', (string) $overview['counts']['paid']],
            ['rejected', (string) $overview['counts']['rejected']],
            ['requested_amount', $overview['money']['requested_amount']],
            ['approved_amount', $overview['money']['approved_amount']],
            ['paid_amount', $overview['money']['paid_amount']],
            ['outstanding_amount', $overview['money']['outstanding_amount']],
            ['recovery_rate_vs_requested', $overview['rates']['recovery_rate_vs_requested'] !== null ? (string) $overview['rates']['recovery_rate_vs_requested'] : ''],
            ['recovery_rate_vs_approved', $overview['rates']['recovery_rate_vs_approved'] !== null ? (string) $overview['rates']['recovery_rate_vs_approved'] : ''],
            ['rejection_rate', $overview['rates']['rejection_rate'] !== null ? (string) $overview['rates']['rejection_rate'] : ''],
            ['average_submission_days', $overview['average_submission_days']['days'] !== null ? (string) $overview['average_submission_days']['days'] : ''],
            ['average_submission_sample_size', (string) $overview['average_submission_days']['sample_size']],
            ['average_payment_days', $overview['average_payment_days']['days'] !== null ? (string) $overview['average_payment_days']['days'] : ''],
            ['average_payment_sample_size', (string) $overview['average_payment_days']['sample_size']],
        ] as [$metric, $value]) {
            $rows[] = ['summary', $metric, $value];
        }

        $rows[] = [];
        $rows[] = ['month', 'claims_created', 'submitted', 'paid', 'rejected', 'requested_amount', 'approved_amount', 'paid_amount', 'recovery_rate_vs_requested'];

        foreach ($this->monthly($organization, $from, $to) as $point) {
            $rows[] = [
                $point['month'],
                (string) $point['claims_created'],
                (string) $point['submitted'],
                (string) $point['paid'],
                (string) $point['rejected'],
                $point['requested_amount'],
                $point['approved_amount'],
                $point['paid_amount'],
                $point['recovery_rate_vs_requested'] !== null ? (string) $point['recovery_rate_vs_requested'] : '',
            ];
        }

        return ['headers' => ['section', 'metric', 'value'], 'rows' => $rows];
    }
}
