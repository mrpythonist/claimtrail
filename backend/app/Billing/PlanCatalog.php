<?php

namespace App\Billing;

/**
 * The plan catalog, read from config/billing.php.
 *
 * Plans are the v0.5.0 section 8 tiers. Monthly prices are in USD
 * cents; annual is 10x monthly (two months free). The Agency tier
 * is $149/mo base for up to 10 carriers plus $12 per extra carrier.
 * The success-fee plan is $0 base and is unlisted: it is offered
 * when price blocks a trial buyer, never as the default.
 */
class PlanCatalog
{
    public const FREE = 'free';

    public const STARTER = 'starter';

    public const GROWTH = 'growth';

    public const FLEET = 'fleet';

    public const AGENCY = 'agency';

    public const SUCCESS_FEE = 'success_fee';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_ANNUAL = 'annual';

    /**
     * Every plan key, in display order.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(config('billing.plans'));
    }

    /**
     * The plans listed on the pricing page. The success-fee plan is
     * unlisted by design (v0.5.0 section 6.4).
     *
     * @return array<int, string>
     */
    public static function listedKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key) => empty(config("billing.plans.{$key}.unlisted")),
        ));
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function isSuccessFee(string $key): bool
    {
        return $key === self::SUCCESS_FEE;
    }

    /**
     * One plan's definition, or null for an unknown key.
     *
     * @return array<string, mixed>|null
     */
    public static function plan(string $key): ?array
    {
        $plan = config("billing.plans.{$key}");

        return is_array($plan) ? $plan : null;
    }

    public static function name(string $key): string
    {
        return (string) (self::plan($key)['name'] ?? $key);
    }

    /**
     * Monthly price in cents for a plan at the given carrier count.
     * The Agency tier adds the per-extra-carrier price past 10.
     */
    public static function monthlyCents(string $key, int $extraCarriers = 0): int
    {
        $plan = self::plan($key);

        if ($plan === null) {
            return 0;
        }

        $base = (int) $plan['monthly_cents'];

        if ($key === self::AGENCY) {
            $base += (int) ($plan['per_extra_carrier_cents'] ?? 0) * max(0, $extraCarriers);
        }

        return $base;
    }

    /**
     * Price in cents for one billing period of the given interval.
     */
    public static function periodCents(string $key, string $interval, int $extraCarriers = 0): int
    {
        $monthly = self::monthlyCents($key, $extraCarriers);

        return $interval === self::INTERVAL_ANNUAL ? $monthly * 10 : $monthly;
    }

    /**
     * Monthly-equivalent price in cents, for comparing plans (an
     * upgrade is a move to a higher monthly-equivalent price).
     */
    public static function monthlyEquivalentCents(string $key, string $interval, int $extraCarriers = 0): int
    {
        $period = self::periodCents($key, $interval, $extraCarriers);

        return $interval === self::INTERVAL_ANNUAL
            ? (int) round($period / 12)
            : $period;
    }

    /**
     * The full catalog for the API: every plan with its prices,
     * limits, and whether it is listed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $plans = [];

        foreach (self::keys() as $key) {
            $plan = self::plan($key);

            $plans[] = [
                'key' => $key,
                'name' => $plan['name'],
                'monthly_cents' => (int) $plan['monthly_cents'],
                'annual_cents' => (int) $plan['monthly_cents'] * 10,
                'per_extra_carrier_cents' => (int) ($plan['per_extra_carrier_cents'] ?? 0),
                'included_carriers' => (int) ($plan['included_carriers'] ?? 0),
                'trucks' => $plan['trucks'] ?? null,
                'limits' => $plan['limits'] ?? [],
                'description' => $plan['description'] ?? null,
                'listed' => empty($plan['unlisted']),
            ];
        }

        return $plans;
    }
}
