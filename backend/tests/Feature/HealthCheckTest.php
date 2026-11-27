<?php

namespace Tests\Feature;

use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_returns_ok_with_all_checks(): void
    {
        Storage::fake('private');

        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.storage.status', 'ok')
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['status', 'latency_ms'],
                    'cache' => ['status', 'latency_ms'],
                    'queue' => ['status', 'latency_ms'],
                    'storage' => ['status', 'latency_ms'],
                ],
                'checked_at',
            ]);
    }

    public function test_public_health_needs_no_authentication(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
    }

    public function test_queue_check_is_skipped_for_non_database_drivers(): void
    {
        config(['queue.default' => 'sync']);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('checks.queue.status', 'skipped');
    }

    public function test_ops_health_requires_the_operations_permission(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/health')
            ->assertForbidden();
    }

    public function test_ops_health_returns_version_and_queue_totals_for_admins(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'checks',
                    'version',
                    'php_version',
                    'laravel_version',
                    'queue_totals' => ['pending', 'reserved', 'delayed', 'failed'],
                    'queue_supported',
                ],
            ]);
    }

    public function test_ops_health_requires_an_organization(): void
    {
        $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->getJson('/api/v1/ops/health')
            ->assertStatus(400);
    }
}
