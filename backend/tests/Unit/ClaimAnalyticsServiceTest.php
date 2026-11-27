<?php

namespace Tests\Unit;

use App\Services\ClaimAnalyticsService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers of the analytics service (v1.5.1).
 *
 * Rates are exact decimal math and null when the denominator
 * is zero; averages are in days with two decimals and null
 * when there is nothing to average; month and year buckets
 * cover the whole range, both ends inclusive.
 */
class ClaimAnalyticsServiceTest extends TestCase
{
    public function test_rate_percent_is_null_on_a_zero_denominator(): void
    {
        $this->assertNull(ClaimAnalyticsService::ratePercent('250.00', '0.00'));
        $this->assertNull(ClaimAnalyticsService::ratePercent('0.00', '0.00'));
    }

    public function test_rate_percent_rounds_to_two_decimals(): void
    {
        $this->assertSame(62.5, ClaimAnalyticsService::ratePercent('250.00', '400.00'));
        $this->assertSame(83.33, ClaimAnalyticsService::ratePercent('250.00', '300.00'));
        $this->assertSame(100.0, ClaimAnalyticsService::ratePercent('100.00', '100.00'));
        $this->assertSame(0.0, ClaimAnalyticsService::ratePercent('0.00', '100.00'));
    }

    public function test_rate_percent_rounds_instead_of_truncating_repeating_decimals(): void
    {
        // 400/600 is 66.666...%, 300/700 is 42.857...%: a real
        // round to two decimals, not a truncation at four.
        $this->assertSame(66.67, ClaimAnalyticsService::ratePercent('400.00', '600.00'));
        $this->assertSame(42.86, ClaimAnalyticsService::ratePercent('300.00', '700.00'));
    }

    public function test_average_days_is_null_with_no_durations(): void
    {
        $this->assertNull(ClaimAnalyticsService::averageDays([]));
    }

    public function test_average_days_means_the_durations_in_days(): void
    {
        $day = 86400;

        // 3 days, 1 day, 1 day: mean 1.67 days.
        $this->assertSame(1.67, ClaimAnalyticsService::averageDays([3 * $day, $day, $day]));
        $this->assertSame(5.0, ClaimAnalyticsService::averageDays([5 * $day]));
        // Half a day rounds to 0.5.
        $this->assertSame(0.5, ClaimAnalyticsService::averageDays([12 * 3600]));
    }

    public function test_month_buckets_cover_the_whole_range(): void
    {
        $this->assertSame(
            ['2026-01', '2026-02', '2026-03'],
            ClaimAnalyticsService::monthBuckets(
                CarbonImmutable::parse('2026-01-15'),
                CarbonImmutable::parse('2026-03-02'),
            ),
        );
        $this->assertSame(
            ['2025-12', '2026-01'],
            ClaimAnalyticsService::monthBuckets(
                CarbonImmutable::parse('2025-12-01'),
                CarbonImmutable::parse('2026-01-31'),
            ),
        );
        $this->assertSame(
            ['2026-01'],
            ClaimAnalyticsService::monthBuckets(
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2026-01-31'),
            ),
        );
    }

    public function test_year_buckets_cover_the_whole_range(): void
    {
        $this->assertSame(
            ['2025', '2026'],
            ClaimAnalyticsService::yearBuckets(
                CarbonImmutable::parse('2025-06-01'),
                CarbonImmutable::parse('2026-02-01'),
            ),
        );
        $this->assertSame(
            ['2026'],
            ClaimAnalyticsService::yearBuckets(
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2026-12-31'),
            ),
        );
    }
}
