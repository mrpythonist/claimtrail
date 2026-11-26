<?php

namespace Tests\Feature;

use App\Models\MonitoringCheck;
use App\OrganizationRole;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Production monitoring (v1.2.3).
 *
 * `monitoring:check` runs the health checks, counts failed jobs
 * and queue depth, and stores the result. The operations page
 * reads the latest run and the history; the run-now endpoint
 * stores a fresh run. There is no alerting channel yet.
 */
class MonitoringTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_monitoring_check_command_stores_a_run(): void
    {
        $exitCode = Artisan::call('monitoring:check');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('monitoring_checks', 1);

        $check = MonitoringCheck::first();
        $this->assertSame('ok', $check->status);
        $this->assertSame(0, $check->failed_jobs);
        $this->assertArrayHasKey('database', $check->checks);
        $this->assertArrayHasKey('cache', $check->checks);
        $this->assertArrayHasKey('queue', $check->checks);
        $this->assertArrayHasKey('storage', $check->checks);
    }

    public function test_monitoring_endpoints_return_latest_and_history(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        Artisan::call('monitoring:check');
        Artisan::call('monitoring:check');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/monitoring')
            ->assertOk()
            ->assertJsonPath('data.latest.status', 'ok')
            ->assertJsonCount(2, 'data.history');

        // A viewer cannot read monitoring.
        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/monitoring')
            ->assertForbidden();
    }

    public function test_run_now_endpoint_stores_a_fresh_run(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/ops/monitoring/check', [])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.failed_jobs', 0);

        $this->assertDatabaseCount('monitoring_checks', 1);
    }

    public function test_monitoring_history_is_empty_before_the_first_run(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/monitoring')
            ->assertOk()
            ->assertJsonPath('data.latest', null)
            ->assertJsonCount(0, 'data.history');
    }
}
