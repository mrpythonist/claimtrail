<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApiPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_an_x_response_time_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}ms$/',
            (string) $response->headers->get('X-Response-Time')
        );
    }

    public function test_event_categories_return_an_etag_and_a_304_on_match(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $this->systemCategory('detention');

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/event-categories')
            ->assertOk();

        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->withHeaders(array_merge($this->orgHeaders($organization), [
            'If-None-Match' => $etag,
        ]))
            ->getJson('/api/v1/event-categories')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
    }

    public function test_event_categories_etag_changes_when_a_category_is_added(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $this->systemCategory('detention');

        $etagBefore = (string) $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/event-categories')
            ->assertOk()
            ->headers->get('ETag');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/event-categories', ['label' => 'Weigh station'])
            ->assertCreated();

        $etagAfter = (string) $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/event-categories')
            ->assertOk()
            ->headers->get('ETag');

        $this->assertNotSame($etagBefore, $etagAfter);
    }

    public function test_slow_query_logging_fires_when_the_threshold_is_zero(): void
    {
        Log::spy();
        config(['database.slow_log_ms' => 0]);

        Organization::count();

        Log::shouldHaveReceived('warning', [
            'Slow query detected.',
            \Mockery::on(fn (array $context) => isset($context['sql'])
                && isset($context['time_ms'])
                && $context['connection'] === 'sqlite'),
        ])->atLeast()->once();
    }

    public function test_slow_query_logging_stays_quiet_below_the_threshold(): void
    {
        Log::spy();
        config(['database.slow_log_ms' => PHP_INT_MAX]);

        Organization::count();

        Log::shouldNotHaveReceived('warning', ['Slow query detected.', \Mockery::any()]);
    }
}
