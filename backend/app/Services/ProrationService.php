<?php

namespace App\Services;

use App\Billing\PlanCatalog;
use App\Models\Subscription;
use Carbon\CarbonInterface;

/**
 * Proration for immediate plan changes.
 *
 * An upgrade (a move to a higher monthly-equivalent price) takes
 * effect immediately: the buyer is credited for the unused days of
 * the old plan and charged for the remaining days of the new plan,
 * both at daily rates over the current period. The net amount is
 * charged at once; a negative net (rounding only, for a true
 * upgrade) becomes credit on the subscription.
 *
 * Downgrades never prorate: they are scheduled for the next
 * renewal by BillingService, so this service is only asked about
 * upgrades and interval changes that raise the monthly-equivalent
 * price.
 */
class ProrationService
{
    /**
     * Proration for moving a subscription to a new plan now.
     *
     * @return array{credit_cents: int, charge_cents: int, net_cents: int, remaining_days: int, period_days: int, is_upgrade: bool}
     */
    public function preview(
        Subscription $subscription,
        string $newPlan,
        string $newInterval,
        int $extraCarriers,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= now();

        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;

        $periodDays = $periodStart !== null && $periodEnd !== null
            ? max(1, (int) $periodStart->diffInDays($periodEnd))
            : 30;

        $remainingDays = $periodEnd !== null && $now->lt($periodEnd)
            ? (int) $now->diffInDays($periodEnd)
            : 0;

        $oldPeriodCents = PlanCatalog::periodCents($subscription->plan, $subscription->interval, $subscription->extra_carriers);
        $newPeriodCents = PlanCatalog::periodCents($newPlan, $newInterval, $extraCarriers);

        $oldDaily = $oldPeriodCents / $periodDays;
        $newDaily = $newPeriodCents / $periodDays;

        $credit = (int) round($oldDaily * $remainingDays);
        $charge = (int) round($newDaily * $remainingDays);

        $oldMonthly = PlanCatalog::monthlyEquivalentCents($subscription->plan, $subscription->interval, $subscription->extra_carriers);
        $newMonthly = PlanCatalog::monthlyEquivalentCents($newPlan, $newInterval, $extraCarriers);

        return [
            'credit_cents' => $credit,
            'charge_cents' => $charge,
            'net_cents' => $charge - $credit,
            'remaining_days' => $remainingDays,
            'period_days' => $periodDays,
            'is_upgrade' => $newMonthly > $oldMonthly,
        ];
    }
}
