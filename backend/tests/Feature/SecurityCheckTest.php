<?php

namespace Tests\Feature;

use App\OrganizationRole;
use App\Services\SecurityCheckService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Production deployment hardening tests (v1.1.3).
 *
 * The security:check command and the GET /security/check
 * endpoint share one service, so both are exercised here: the
 * command's exit code and the endpoint's permission gating.
 */
class SecurityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_check_command_passes_with_sane_config(): void
    {
        $exit = Artisan::call('security:check');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('[PASS] APP_DEBUG is off', $output);
        $this->assertStringContainsString('[PASS] APP_KEY is set', $output);
        // No backup has completed in the last 7 days in a fresh
        // test database, so the backup check warns but does not
        // fail the run.
        $this->assertStringContainsString('[WARN] A backup completed in the last 7 days', $output);
        $this->assertStringContainsString('Overall: WARN', $output);
    }

    public function test_security_check_command_fails_without_an_app_key(): void
    {
        config(['app.key' => '']);

        $exit = Artisan::call('security:check');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('[FAIL] APP_KEY is set', $output);
        $this->assertStringContainsString('Overall: FAIL', $output);
    }

    public function test_security_check_command_fails_with_debug_on_in_production(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $exit = Artisan::call('security:check');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('[FAIL] APP_DEBUG is off', $output);
    }

    public function test_security_check_command_supports_json_output(): void
    {
        $exit = Artisan::call('security:check', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        $result = json_decode(Artisan::output(), true);

        $this->assertSame('warn', $result['overall']);
        $this->assertCount(10, $result['checks']);
        $ids = array_column($result['checks'], 'id');
        $this->assertContains('app_debug', $ids);
        $this->assertContains('app_key', $ids);
        $this->assertContains('app_url', $ids);
        $this->assertContains('session_cookies', $ids);
        $this->assertContains('sanctum_expiration', $ids);
        $this->assertContains('evidence_disk', $ids);
        $this->assertContains('cors_origin', $ids);
        $this->assertContains('backup_freshness', $ids);
        $this->assertContains('mail_driver', $ids);
        $this->assertContains('queue_driver', $ids);
    }

    public function test_security_check_service_and_command_agree(): void
    {
        $service = app(SecurityCheckService::class)->run();

        $this->assertSame('warn', $service['overall']);

        $byId = [];
        foreach ($service['checks'] as $check) {
            $byId[$check['id']] = $check['status'];
        }

        $this->assertSame('pass', $byId['app_debug']);
        $this->assertSame('pass', $byId['app_key']);
        $this->assertSame('warn', $byId['sanctum_expiration']);
        $this->assertSame('warn', $byId['backup_freshness']);
    }

    public function test_security_check_endpoint_requires_manage_security(): void
    {
        [$viewer, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/security/check')
            ->assertForbidden();

        [$admin, $adminOrg] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($adminOrg))
            ->getJson('/api/v1/security/check')
            ->assertOk()
            ->assertJsonPath('data.overall', 'warn')
            ->assertJsonCount(10, 'data.checks');
    }
}
