<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Subscription;
use App\Services\ProrationService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Proration arithmetic through the real preview() API, on an
 * unpersisted Subscription. The numbers are the ones the billing
 * feature tests rely on (half a 30-day period of Growth $49 vs
 * Fleet $99: credit $24.50, charge $49.50, net $25.00).
 */
final class ProrationServiceTest extends TestCase
{
    private ProrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProrationService;
    }

    public function test_half_a_thirty_day_period_growth_to_fleet(): void
    {
        $subscription = $this->subscription(
            'growth',
            'monthly',
            Carbon::parse('2026-11-10 12:00:00'),
            Carbon::parse('2026-12-10 12:00:00'),
        );

        $result = $this->service->preview($subscription, 'fleet', 'monthly', 0, Carbon::parse('2026-11-25 12:00:00'));

        $this->assertSame(30, $result['period_days']);
        $this->assertSame(15, $result['remaining_days']);
        $this->assertSame(2450, $result['credit_cents']);
        $this->assertSame(4950, $result['charge_cents']);
        $this->assertSame(2500, $result['net_cents']);
        $this->assertTrue($result['is_upgrade']);
    }

    public function test_downgrade_produces_a_negative_net(): void
    {
        $subscription = $this->subscription(
            'fleet',
            'monthly',
            Carbon::parse('2026-11-10 12:00:00'),
            Carbon::parse('2026-12-10 12:00:00'),
        );

        $result = $this->service->preview($subscription, 'growth', 'monthly', 0, Carbon::parse('2026-11-25 12:00:00'));

        $this->assertSame(4950, $result['credit_cents']);
        $this->assertSame(2450, $result['charge_cents']);
        $this->assertSame(-2500, $result['net_cents']);
        $this->assertFalse($result['is_upgrade']);
    }

    public function test_same_plan_produces_zero_net(): void
    {
        $subscription = $this->subscription(
            'growth',
            'monthly',
            Carbon::parse('2026-11-10 12:00:00'),
            Carbon::parse('2026-12-10 12:00:00'),
        );

        $result = $this->service->preview($subscription, 'growth', 'monthly', 0, Carbon::parse('2026-11-25 12:00:00'));

        $this->assertSame(0, $result['net_cents']);
        $this->assertSame(2450, $result['credit_cents']);
        $this->assertSame(2450, $result['charge_cents']);
        $this->assertFalse($result['is_upgrade']);
    }

    public function test_annual_prices_are_prorated_on_the_same_daily_rate(): void
    {
        // Growth annual $490 (10x monthly) vs Fleet annual $990.
        $subscription = $this->subscription(
            'growth',
            'annual',
            Carbon::parse('2026-11-10 12:00:00'),
            Carbon::parse('2027-11-10 12:00:00'),
        );

        $result = $this->service->preview($subscription, 'fleet', 'annual', 0, Carbon::parse('2027-05-11 12:00:00'));

        $this->assertSame(365, $result['period_days']);
        $this->assertSame(183, $result['remaining_days']);
        $this->assertSame(24567, $result['credit_cents']);
        $this->assertSame(49636, $result['charge_cents']);
        $this->assertSame(25069, $result['net_cents']);
        $this->assertTrue($result['is_upgrade']);
    }

    public function test_no_remaining_days_when_the_period_has_ended(): void
    {
        $subscription = $this->subscription(
            'growth',
            'monthly',
            Carbon::parse('2026-11-10 12:00:00'),
            Carbon::parse('2026-12-10 12:00:00'),
        );

        $result = $this->service->preview($subscription, 'fleet', 'monthly', 0, Carbon::parse('2026-12-11 12:00:00'));

        $this->assertSame(0, $result['remaining_days']);
        $this->assertSame(0, $result['credit_cents']);
        $this->assertSame(0, $result['charge_cents']);
        $this->assertSame(0, $result['net_cents']);
    }

    public function test_zero_length_period_is_treated_as_no_proration(): void
    {
        $same = Carbon::parse('2026-11-25 12:00:00');

        $subscription = $this->subscription('growth', 'monthly', $same, $same);

        $result = $this->service->preview($subscription, 'fleet', 'monthly', 0, $same);

        $this->assertSame(1, $result['period_days']);
        $this->assertSame(0, $result['remaining_days']);
        $this->assertSame(0, $result['net_cents']);
    }

    private function subscription(string $plan, string $interval, Carbon $start, Carbon $end): Subscription
    {
        $subscription = new Subscription;
        $subscription->plan = $plan;
        $subscription->interval = $interval;
        $subscription->extra_carriers = 0;
        $subscription->current_period_start = $start;
        $subscription->current_period_end = $end;

        return $subscription;
    }
}
