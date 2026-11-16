<?php

namespace App\Services;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\Claim;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Organization-level claim reporting.
 *
 * Financial definitions (the single source for reports and the
 * dashboard; do not redefine these anywhere else):
 *
 * - potential: requested amounts of claims still being prepared
 *   (potential, evidence required, under review, ready to submit).
 *   Estimated money, never presented as recovered.
 * - submitted: requested amounts of claims that reached the broker
 *   (submitted, accepted, partially accepted, rejected, paid, plus
 *   closed claims that were submitted before closing).
 * - accepted: accepted amounts of accepted, partially accepted, and
 *   paid claims.
 * - recovered: recovered amounts of paid claims only. Actual cash
 *   received, recorded separately from acceptance.
 * - rejected: requested amounts of rejected claims.
 * - outstanding: accepted minus recovered per currency, floored at
 *   zero. Money the broker agreed to that has not arrived yet.
 *
 * Currencies are never mixed: every figure is a per-currency
 * breakdown. There is no conversion and no invented exchange rate.
 * A claim with no accepted amount contributes nothing to the
 * accepted figure; a claim with no recovered amount contributes
 * nothing to the recovered figure.
 */
class ReportService
{
    /**
     * Statuses whose requested amounts count as potential.
     */
    public const POTENTIAL_STATUSES = [
        ClaimStatus::Potential,
        ClaimStatus::EvidenceRequired,
        ClaimStatus::UnderReview,
        ClaimStatus::ReadyToSubmit,
    ];

    /**
     * Statuses whose requested amounts count as submitted.
     */
    public const SUBMITTED_STATUSES = [
        ClaimStatus::Submitted,
        ClaimStatus::Accepted,
        ClaimStatus::PartiallyAccepted,
        ClaimStatus::Rejected,
        ClaimStatus::Paid,
    ];

    /**
     * Statuses whose accepted amounts count as accepted.
     */
    public const ACCEPTED_STATUSES = [
        ClaimStatus::Accepted,
        ClaimStatus::PartiallyAccepted,
        ClaimStatus::Paid,
    ];

    /**
     * The full summary: counts by status and event type, and the
     * six money figures per currency.
     *
     * @return array<string, mixed>
     */
    public function summary(Organization $organization, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $claims = $this->claimsInRange($organization, $dateFrom, $dateTo);

        return [
            'counts_by_status' => $this->countsByStatus($claims),
            'counts_by_event_type' => $this->countsByEventType($claims),
            'money' => [
                'potential' => $this->moneyByCurrency($claims, self::POTENTIAL_STATUSES, 'requested_amount'),
                'submitted' => $this->submittedMoney($claims),
                'accepted' => $this->moneyByCurrency($claims, self::ACCEPTED_STATUSES, 'accepted_amount'),
                'recovered' => $this->moneyByCurrency($claims, [ClaimStatus::Paid], 'recovered_amount'),
                'rejected' => $this->moneyByCurrency($claims, [ClaimStatus::Rejected], 'requested_amount'),
                'outstanding' => $this->outstandingMoney($claims),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }

    /**
     * Recovered money per month per currency.
     *
     * Grouped in PHP rather than SQL so the month bucketing works
     * identically on SQLite and PostgreSQL. Defaults to the last
     * 12 months when no range is given.
     *
     * @return array<int, array{month: string, amounts: array<int, array{currency: string, amount: string}>}>
     */
    public function recoveryHistory(Organization $organization, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $to = $dateTo !== null ? $dateTo : now()->toDateString();
        $from = $dateFrom ?? now()->subMonths(11)->startOfMonth()->toDateString();

        $paid = $organization->claims()->getQuery()
            ->where('status', ClaimStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->whereNotNull('recovered_amount')
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->orderBy('paid_at')
            ->get(['paid_at', 'recovered_amount', 'currency']);

        $months = [];

        foreach ($paid as $claim) {
            $month = $claim->paid_at->format('Y-m');
            $currency = strtoupper($claim->currency);
            $months[$month][$currency] = bcadd(
                (string) ($months[$month][$currency] ?? '0'),
                (string) $claim->recovered_amount,
                2
            );
        }

        ksort($months);

        return array_map(function (string $month, array $currencies) {
            ksort($currencies);

            return [
                'month' => $month,
                'amounts' => array_map(
                    fn (string $currency, string $amount) => ['currency' => $currency, 'amount' => $amount],
                    array_keys($currencies),
                    array_values($currencies),
                ),
            ];
        }, array_keys($months), array_values($months));
    }

    /**
     * Per-claim rows for the CSV export, honoring the same filters
     * as the summary.
     *
     * @return \Illuminate\Support\Collection<int, Claim>
     */
    public function exportClaims(Organization $organization, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        return $this->claimsInRange($organization, $dateFrom, $dateTo)
            ->with(['shipment'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Money figures for the dashboard: potential, submitted,
     * accepted, and recovered per currency.
     *
     * @return array<string, array<int, array{currency: string, amount: string}>>
     */
    public function moneyFigures(Organization $organization, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $claims = $this->claimsInRange($organization, $dateFrom, $dateTo);

        return [
            'potential' => $this->moneyByCurrency($claims, self::POTENTIAL_STATUSES, 'requested_amount'),
            'submitted' => $this->submittedMoney($claims),
            'accepted' => $this->moneyByCurrency($claims, self::ACCEPTED_STATUSES, 'accepted_amount'),
            'recovered' => $this->moneyByCurrency($claims, [ClaimStatus::Paid], 'recovered_amount'),
        ];
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
     * Claim counts for every status, zero-filled so the frontend
     * can render a complete table.
     *
     * @return array<int, array{status: string, label: string, count: int}>
     */
    protected function countsByStatus(Builder $claims): array
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
     * Claim counts for every claim category, zero-filled.
     *
     * @return array<int, array{category: string, label: string, count: int}>
     */
    protected function countsByEventType(Builder $claims): array
    {
        $counts = (clone $claims)
            ->selectRaw('claim_category, COUNT(*) as count')
            ->groupBy('claim_category')
            ->pluck('count', 'claim_category')
            ->all();

        return array_map(function (ClaimCategory $category) use ($counts) {
            return [
                'category' => $category->value,
                'label' => $category->label(),
                'count' => (int) ($counts[$category->value] ?? 0),
            ];
        }, ClaimCategory::cases());
    }

    /**
     * Sum of one amount column per currency for claims in the given
     * statuses. Claims with a null amount contribute nothing.
     *
     * @param  array<int, ClaimStatus>  $statuses
     *
     * @return array<int, array{currency: string, amount: string}>
     */
    protected function moneyByCurrency(Builder $claims, array $statuses, string $column): array
    {
        $values = array_map(fn (ClaimStatus $status) => $status->value, $statuses);

        $rows = (clone $claims)
            ->whereIn('status', $values)
            ->whereNotNull($column)
            ->selectRaw('currency, SUM('.$column.') as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return $rows->map(fn ($row) => [
            'currency' => strtoupper((string) $row->currency),
            'amount' => $this->formatMoney($row->total),
        ])->all();
    }

    /**
     * Submitted money: requested amounts of claims that reached the
     * broker, including claims closed after submission.
     *
     * @return array<int, array{currency: string, amount: string}>
     */
    protected function submittedMoney(Builder $claims): array
    {
        $values = array_map(fn (ClaimStatus $status) => $status->value, self::SUBMITTED_STATUSES);

        $rows = (clone $claims)
            ->where(function ($query) use ($values) {
                $query->whereIn('status', $values)
                    ->orWhere(function ($query) {
                        $query->where('status', ClaimStatus::Closed->value)
                            ->whereNotNull('submitted_at');
                    });
            })
            ->selectRaw('currency, SUM(requested_amount) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return $rows->map(fn ($row) => [
            'currency' => strtoupper((string) $row->currency),
            'amount' => $this->formatMoney($row->total),
        ])->all();
    }

    /**
     * Outstanding money per currency: accepted minus recovered,
     * floored at zero. A currency with no accepted money does not
     * appear.
     *
     * @return array<int, array{currency: string, amount: string}>
     */
    protected function outstandingMoney(Builder $claims): array
    {
        $accepted = $this->moneyByCurrency($claims, self::ACCEPTED_STATUSES, 'accepted_amount');
        $recovered = $this->moneyByCurrency($claims, [ClaimStatus::Paid], 'recovered_amount');

        $recoveredByCurrency = [];

        foreach ($recovered as $row) {
            $recoveredByCurrency[$row['currency']] = $row['amount'];
        }

        $outstanding = [];

        foreach ($accepted as $row) {
            $difference = bcsub(
                $row['amount'],
                $recoveredByCurrency[$row['currency']] ?? '0',
                2
            );

            if (bccomp($difference, '0', 2) > 0) {
                $outstanding[] = [
                    'currency' => $row['currency'],
                    'amount' => $difference,
                ];
            }
        }

        return $outstanding;
    }

    /**
     * Format a database sum as a two-decimal money string.
     */
    protected function formatMoney(mixed $total): string
    {
        return number_format((float) $total, 2, '.', '');
    }
}
