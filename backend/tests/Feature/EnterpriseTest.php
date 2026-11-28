<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\DisasterRecoveryDrill;
use App\Models\EnterpriseAlert;
use App\Models\LoadTestRun;
use App\Models\MonitoringCheck;
use App\Models\Notification;
use App\Models\SecurityAssessment;
use App\Models\SecurityPolicy;
use App\Models\Slo;
use App\Models\User;
use App\NotificationType;
use App\OrganizationRole;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enterprise readiness (v2.0.3).
 *
 * Security policies (defaults, overrides, password and IP
 * checks, password-change enforcement), audit export (CSV and
 * JSON), retention preview and purge, disaster recovery
 * status and drills, bounded local load tests, alert rules
 * and alerts with notifications, SLOs measured from local
 * data, and the enterprise security self-assessment. Every
 * enterprise endpoint needs manage-enterprise and the Growth
 * plan or better.
 */
class EnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected function setupOrg(string $plan = 'growth', OrganizationRole $role = OrganizationRole::Owner): array
    {
        [$user, $organization] = $this->actingAsMemberOf($role);

        if ($plan !== null) {
            $this->subscribe($organization, $plan);
        }

        return [$user, $organization];
    }

    protected function memberOf($organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->addMember($organization, $user, $role);

        return $user;
    }

    protected function actAs(User $user): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    protected function growthHeaders($organization): array
    {
        return $this->orgHeaders($organization);
    }

    // -- Security policies -------------------------------------------------

    public function test_security_policy_returns_defaults_until_set(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/security/policies')
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 8)
            ->assertJsonPath('data.password_require_numbers', false)
            ->assertJsonPath('data.session_timeout_minutes', 120)
            ->assertJsonPath('data.allowed_ip_ranges', [])
            ->assertJsonPath('data.require_two_factor', false)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_security_policy_can_be_set_and_read_back(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/security/policies', [
                'password_min_length' => 12,
                'password_require_numbers' => true,
                'password_require_symbols' => true,
                'session_timeout_minutes' => 60,
                'max_concurrent_sessions' => 3,
                'allowed_ip_ranges' => ['10.0.0.0/8', '203.0.113.5'],
                'require_two_factor' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 12)
            ->assertJsonPath('data.password_require_numbers', true)
            ->assertJsonPath('data.max_concurrent_sessions', 3)
            ->assertJsonPath('data.allowed_ip_ranges', ['10.0.0.0/8', '203.0.113.5'])
            ->assertJsonPath('data.is_default', false);

        $this->assertSame(1, SecurityPolicy::count());
    }

    public function test_security_policy_validation_rejects_short_minimums(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/security/policies', ['password_min_length' => 4])
            ->assertUnprocessable();
    }

    public function test_password_check_reports_policy_failures(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/security/policies', [
                'password_min_length' => 12,
                'password_require_numbers' => true,
                'password_require_symbols' => true,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/security/policies/check-password', ['password' => 'short'])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonCount(3, 'data.errors');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/security/policies/check-password', ['password' => 'LongEnough1!'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.errors', []);
    }

    public function test_ip_check_reports_ranges_and_allows_all_when_unset(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/security/policies/check-ip', ['ip' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.in_range', null);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/security/policies', ['allowed_ip_ranges' => ['10.0.0.0/8']])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/security/policies/check-ip', ['ip' => '10.1.2.3'])
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.in_range', true);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/security/policies/check-ip', ['ip' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.in_range', false);
    }

    public function test_password_change_enforces_the_organization_policy(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/security/policies', [
                'password_min_length' => 12,
                'password_require_numbers' => true,
            ])
            ->assertOk();

        // Long enough for the base 8-character rule, but short
        // of the 12-character policy and with no number: the
        // policy refuses it with both reasons.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'longenough',
                'password_confirmation' => 'longenough',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Password must be at least 12 characters long.')
            ->assertJsonPath('errors.password.1', 'Password must contain at least one number.');

        // Long enough, with a number: accepted.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'LongEnough123',
                'password_confirmation' => 'LongEnough123',
            ])
            ->assertOk();
    }

    // -- Audit export ------------------------------------------------------

    public function test_audit_export_csv_downloads_the_filtered_rows(): void
    {
        [$user, $organization] = $this->setupOrg();

        $shipment = $this->createShipment($organization);
        $this->assertTrue(AuditLog::forOrganization($organization->getKey())->exists());

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/audit-logs/export?format=csv');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('id,created_at,actor_type', $response->getContent());
        $this->assertStringContainsString('shipment.created', $response->getContent());
    }

    public function test_audit_export_json_returns_rows_and_honors_filters(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs/export?format=json&action=shipment.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'shipment.created');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs/export?format=json&action=no.such.action')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // -- Retention preview and purge ---------------------------------------

    public function test_retention_preview_counts_rows_and_purge_dry_run_deletes_nothing(): void
    {
        [$user, $organization] = $this->setupOrg();

        config(['retention.audit_logs_days' => 30]);

        $this->createShipment($organization);

        $old = AuditLog::forOrganization($organization->getKey())->firstOrFail();
        DB::table('audit_logs')->where('id', $old->getKey())->update(['created_at' => now()->subDays(40)]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/retention/preview')
            ->assertOk()
            ->assertJsonPath('data.effective.audit_logs', 30)
            ->assertJsonPath('data.preview.audit_logs.count', 1)
            ->assertJsonPath('data.preview.notifications.count', 0);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/retention/purge', ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.counts.audit_logs', 1);

        $this->assertTrue(AuditLog::forOrganization($organization->getKey())->whereKey($old->getKey())->exists());
    }

    public function test_retention_purge_deletes_old_rows_and_audits_itself(): void
    {
        [$user, $organization] = $this->setupOrg();

        config(['retention.audit_logs_days' => 30]);

        $this->createShipment($organization);

        $old = AuditLog::forOrganization($organization->getKey())->firstOrFail();
        DB::table('audit_logs')->where('id', $old->getKey())->update(['created_at' => now()->subDays(40)]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/retention/purge', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.counts.audit_logs', 1);

        $this->assertFalse(AuditLog::forOrganization($organization->getKey())->whereKey($old->getKey())->exists());
        $this->assertTrue(AuditLog::forOrganization($organization->getKey())
            ->where('action', 'retention.purged')->exists());
    }

    // -- Disaster recovery -------------------------------------------------

    public function test_disaster_recovery_status_without_a_backup(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/disaster-recovery')
            ->assertOk()
            ->assertJsonPath('data.rpo_minutes', 1440)
            ->assertJsonPath('data.rto_minutes', 240)
            ->assertJsonPath('data.latest_backup', null)
            ->assertJsonPath('data.last_verified_backup', null)
            ->assertJsonPath('data.latest_drill', null)
            ->assertJsonPath('data.total_drills', 0);
    }

    public function test_verify_drill_without_a_backup_records_a_failed_drill(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/disaster-recovery/drills', ['kind' => 'verify'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'verify')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result.ok', false);

        $this->assertSame(1, DisasterRecoveryDrill::count());
    }

    public function test_drill_validation_rejects_unknown_kinds(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/disaster-recovery/drills', ['kind' => 'failover'])
            ->assertUnprocessable();
    }

    // -- Load tests --------------------------------------------------------

    public function test_load_test_runs_against_health_and_reports_timings(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/load-tests', [
                'name' => 'Smoke load',
                'requests' => 5,
                'target' => 'health',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Smoke load')
            ->assertJsonPath('data.target', 'health')
            ->assertJsonPath('data.requests', 5)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_requests', 5)
            ->assertJsonPath('data.success_count', 5)
            ->assertJsonPath('data.error_count', 0);

        $this->assertSame(1, LoadTestRun::count());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/load-tests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_load_test_runs_against_shipments_with_the_callers_credentials(): void
    {
        [$user, $organization] = $this->setupOrg();

        $this->createShipment($organization);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(array_merge($this->orgHeaders($organization), [
            'Authorization' => 'Bearer '.$token,
        ]))
            ->postJson('/api/v1/enterprise/load-tests', [
                'requests' => 3,
                'target' => 'shipments',
            ])
            ->assertCreated()
            ->assertJsonPath('data.target', 'shipments')
            ->assertJsonPath('data.total_requests', 3)
            ->assertJsonPath('data.success_count', 3)
            ->assertJsonPath('data.error_count', 0);
    }

    public function test_load_test_validation_caps_requests(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/load-tests', ['requests' => 0])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/load-tests', ['requests' => 201])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/load-tests', ['target' => 'production'])
            ->assertUnprocessable();
    }

    public function test_load_test_command_runs_and_reports(): void
    {
        [, $organization] = $this->setupOrg();

        $exit = Artisan::call('load:test', [
            '--requests' => 3,
            '--organization' => $organization->getKey(),
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('3/3 succeeded', Artisan::output());
        $this->assertSame(1, LoadTestRun::count());
    }

    // -- Alert rules and alerts --------------------------------------------

    protected function createRule($organization, array $attributes = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'organization_id' => $organization->getKey(),
            'name' => 'Queue depth watch',
            'metric' => AlertRule::METRIC_QUEUE_DEPTH,
            'operator' => 'gte',
            'threshold' => 0,
            'window_minutes' => 60,
            'enabled' => true,
            'severity' => 'warning',
        ], $attributes));
    }

    public function test_alert_rule_crud(): void
    {
        [, $organization] = $this->setupOrg();

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/alert-rules', [
                'name' => 'Failed jobs watch',
                'metric' => 'failed_jobs',
                'operator' => 'gt',
                'threshold' => 5,
                'window_minutes' => 60,
                'severity' => 'critical',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Failed jobs watch')
            ->assertJsonPath('data.metric', 'failed_jobs')
            ->assertJsonPath('data.severity', 'critical')
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/alert-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/enterprise/alert-rules/{$created}")
            ->assertOk()
            ->assertJsonPath('data.id', $created);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/enterprise/alert-rules/{$created}", [
                'threshold' => 10,
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.threshold', 10)
            ->assertJsonPath('data.enabled', false);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/enterprise/alert-rules/{$created}")
            ->assertOk();

        $this->assertSame(0, AlertRule::count());
    }

    public function test_alert_rule_validation_rejects_unknown_metrics(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/alert-rules', [
                'name' => 'Bad metric',
                'metric' => 'cpu',
                'threshold' => 1,
            ])
            ->assertUnprocessable();
    }

    public function test_evaluating_a_breaching_rule_opens_one_alert_and_notifies(): void
    {
        [$owner, $organization] = $this->setupOrg();
        $admin = $this->memberOf($organization, OrganizationRole::Admin);

        $rule = $this->createRule($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.rule_id', $rule->getKey())
            ->assertJsonPath('data.metric', 'queue_depth')
            ->assertJsonPath('data.observed_value', 0)
            ->assertJsonPath('data.breached', true)
            ->assertJsonPath('data.alert.status', 'active');

        $this->assertSame(1, EnterpriseAlert::count());

        // The admin is notified; the owner (the actor) is excluded.
        $this->assertTrue(Notification::where('user_id', $admin->getKey())
            ->where('type', NotificationType::EnterpriseAlertTriggered)->exists());
        $this->assertFalse(Notification::where('user_id', $owner->getKey())
            ->where('type', NotificationType::EnterpriseAlertTriggered)->exists());

        // A repeat evaluation refreshes the open alert instead of
        // opening a second one.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.breached', true);

        $this->assertSame(1, EnterpriseAlert::count());
    }

    public function test_unmeasurable_metric_does_not_breach(): void
    {
        [, $organization] = $this->setupOrg();

        $rule = $this->createRule($organization, [
            'name' => 'Backup age watch',
            'metric' => AlertRule::METRIC_BACKUP_AGE_HOURS,
            'operator' => 'gt',
            'threshold' => 24,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.observed_value', null)
            ->assertJsonPath('data.breached', false)
            ->assertJsonPath('data.alert', null);

        $this->assertSame(0, EnterpriseAlert::count());
    }

    public function test_alerts_can_be_acknowledged_and_resolved_then_reopen(): void
    {
        [, $organization] = $this->setupOrg();

        $rule = $this->createRule($organization);

        $alertId = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}/evaluate")
            ->assertOk()
            ->json('data.alert.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alerts/{$alertId}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alerts/{$alertId}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        // A breach after resolution opens a fresh alert.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.breached', true);

        $this->assertSame(2, EnterpriseAlert::count());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/alerts?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_alerts_evaluate_command_opens_alerts(): void
    {
        [, $organization] = $this->setupOrg();

        $this->createRule($organization);

        $exit = Artisan::call('alerts:evaluate', [
            '--organization' => $organization->getKey(),
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('1 breach', Artisan::output());
        $this->assertSame(1, EnterpriseAlert::count());
    }

    // -- SLOs ---------------------------------------------------------------

    public function test_slos_report_insufficient_data_with_no_history(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/slos')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.key', 'availability')
            ->assertJsonPath('data.0.status', 'insufficient_data')
            ->assertJsonPath('data.0.measured_value', null)
            ->assertJsonPath('data.0.sample_size', 0)
            ->assertJsonPath('data.1.key', 'error_rate')
            ->assertJsonPath('data.2.key', 'latency_p95');

        $this->assertSame(3, Slo::count());
    }

    public function test_slos_measure_from_monitoring_check_history(): void
    {
        [, $organization] = $this->setupOrg();

        MonitoringCheck::create([
            'status' => 'ok',
            'checks' => [
                'database' => ['status' => 'ok', 'latency_ms' => 12.5],
                'cache' => ['status' => 'ok', 'latency_ms' => 3.0],
            ],
            'failed_jobs' => 0,
            'queue_depth' => 0,
            'checked_at' => now()->subHour(),
        ]);
        MonitoringCheck::create([
            'status' => 'degraded',
            'checks' => [
                'database' => ['status' => 'ok', 'latency_ms' => 400.0],
                'cache' => ['status' => 'ok', 'latency_ms' => 250.0],
            ],
            'failed_jobs' => 0,
            'queue_depth' => 0,
            'checked_at' => now()->subMinutes(30),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/slos')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'availability')
            ->assertJsonPath('data.0.measured_value', 50)
            ->assertJsonPath('data.0.sample_size', 2)
            ->assertJsonPath('data.0.status', 'breaching')
            ->assertJsonPath('data.1.key', 'error_rate')
            ->assertJsonPath('data.1.measured_value', 0)
            ->assertJsonPath('data.1.status', 'meeting')
            ->assertJsonPath('data.2.key', 'latency_p95')
            ->assertJsonPath('data.2.measured_value', 400)
            ->assertJsonPath('data.2.sample_size', 4)
            ->assertJsonPath('data.2.status', 'meeting');
    }

    public function test_slo_targets_can_be_updated(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/enterprise/slos/availability', [
                'target_value' => 99.9,
                'window_days' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'availability')
            ->assertJsonPath('data.target_value', 99.9)
            ->assertJsonPath('data.window_days', 7);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/enterprise/slos/nope', ['target_value' => 50])
            ->assertNotFound();
    }

    // -- Security assessments ----------------------------------------------

    public function test_assessment_generation_reports_open_findings(): void
    {
        [, $organization] = $this->setupOrg();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/assessments/latest')
            ->assertNotFound();

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/enterprise/assessments')
            ->assertCreated();

        $response->assertJsonPath('data.overall', 'warn');
        $this->assertGreaterThanOrEqual(16, (int) $response->json('data.total_checks'));
        $this->assertGreaterThanOrEqual(7, (int) $response->json('data.open_findings'));

        $findings = $response->json('data.findings');
        $ids = array_column($findings, 'id');
        $this->assertContains('permission-coverage', $ids);
        $this->assertContains('live-postgres', $ids);
        $this->assertContains('penetration-test', $ids);

        $open = array_filter($findings, fn ($finding) => $finding['status'] === 'open');
        $this->assertSame(7, count($open));

        $this->assertSame(1, SecurityAssessment::count());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/assessments/latest')
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'));

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/assessments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_assessment_command_generates_for_all_organizations(): void
    {
        [, $organization] = $this->setupOrg();

        $exit = Artisan::call('enterprise:assess', [
            '--organization' => $organization->getKey(),
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Assessment: warn', Artisan::output());
        $this->assertSame(1, SecurityAssessment::count());
    }

    // -- Authorization and plan gating --------------------------------------

    public function test_enterprise_endpoints_require_manage_enterprise(): void
    {
        [, $organization] = $this->setupOrg('growth', OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/enterprise/slos')
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/security/policies')
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs/export?format=json')
            ->assertForbidden();
    }

    public function test_enterprise_endpoints_refuse_free_and_starter_plans(): void
    {
        [$freeUser, $freeOrg] = $this->setupOrg('free');
        [$starterUser, $starterOrg] = $this->setupOrg('starter');

        foreach ([[$freeUser, $freeOrg], [$starterUser, $starterOrg]] as [$user, $organization]) {
            // Each org's requests must run as its own owner:
            // the organization middleware 404s a non-member
            // before the plan gate is ever reached.
            $this->actAs($user);

            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/enterprise/slos')
                ->assertStatus(402);

            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/enterprise/disaster-recovery')
                ->assertStatus(402);

            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/security/policies')
                ->assertStatus(402);

            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/retention/preview')
                ->assertStatus(402);
        }
    }

    public function test_cross_tenant_alert_rule_is_a_404(): void
    {
        [$user, $organization] = $this->setupOrg();
        [, $otherOrg] = $this->setupOrg();

        $rule = $this->createRule($otherOrg);

        // The request runs as the first org's owner, who is a
        // member of the resolved organization: the 404 comes
        // from the cross-tenant check, not the middleware.
        $this->actAs($user);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/enterprise/alert-rules/{$rule->getKey()}")
            ->assertNotFound();
    }

    public function test_unauthenticated_enterprise_requests_are_401(): void
    {
        $this->getJson('/api/v1/enterprise/slos')->assertUnauthorized();
    }
}
