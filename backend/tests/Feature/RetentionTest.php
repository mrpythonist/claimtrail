<?php

namespace Tests\Feature;

use App\GeneratedDocumentStatus;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\JobExecution;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\SecurityEvent;
use App\NotificationType;
use App\OrganizationRole;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Data retention policies (v1.1.3).
 *
 * Rows older than the configured retention are purged per
 * organization (audit logs, notifications, failed documents,
 * security events) or globally (job executions, unattributed
 * security events). Dry runs delete nothing, the expiry
 * boundary keeps rows exactly at the cutoff, and every purge
 * writes one audit row with the counts.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'retention.audit_logs_days' => 365,
            'retention.notifications_days' => 90,
            'retention.job_executions_days' => 30,
            'retention.failed_documents_days' => 30,
            'retention.security_events_days' => 180,
        ]);

        Storage::fake('private');
    }

    /**
     * Backdate a row's created_at without touching anything else.
     */
    protected function backdate(string $table, string $id, int $days): void
    {
        DB::table($table)
            ->where('id', $id)
            ->update(['created_at' => now()->subDays($days)]);
    }

    public function test_retention_settings_endpoint_reports_effective_settings(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/retention')
            ->assertOk()
            ->assertJsonPath('data.effective.audit_logs', 365)
            ->assertJsonPath('data.effective.notifications', 90)
            ->assertJsonPath('data.defaults.audit_logs', 365)
            ->assertJsonPath('data.overrides', []);
    }

    public function test_retention_overrides_can_be_set_and_cleared(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/retention', ['audit_logs' => 30])
            ->assertOk()
            ->assertJsonPath('data.effective.audit_logs', 30)
            ->assertJsonPath('data.effective.notifications', 90)
            ->assertJsonPath('data.overrides.audit_logs', 30);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/retention', ['audit_logs' => null])
            ->assertOk()
            ->assertJsonPath('data.effective.audit_logs', 365)
            ->assertJsonPath('data.overrides', []);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/retention', ['audit_logs' => -1])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/retention', ['audit_logs' => 99999])
            ->assertUnprocessable();

        [$viewer, $viewerOrg] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($viewerOrg))
            ->putJson('/api/v1/retention', ['audit_logs' => 30])
            ->assertForbidden();
    }

    public function test_purge_deletes_expired_rows_and_keeps_rows_at_the_cutoff(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $oldLog = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => $shipment->getKey(),
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $oldLog->getKey(), 366);

        $boundaryLog = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => $shipment->getKey(),
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $boundaryLog->getKey(), 365);

        $oldNotification = Notification::create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->id,
            'type' => NotificationType::ClaimCreated,
            'title' => 'Old',
            'body' => 'Old notification',
        ]);
        $this->backdate('notifications', $oldNotification->getKey(), 91);

        $counts = app(RetentionService::class)->purge($organization);

        $this->assertSame(1, $counts['audit_logs']);
        $this->assertSame(1, $counts['notifications']);
        $this->assertNull(AuditLog::withoutGlobalScope('organization')->whereKey($oldLog->getKey())->first());
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($boundaryLog->getKey())->first());
        $this->assertNull(Notification::withoutGlobalScope('organization')->whereKey($oldNotification->getKey())->first());

        // The purge itself is on the audit record.
        $purgeRow = AuditLog::withoutGlobalScope('organization')
            ->where('action', 'retention.purged')
            ->first();
        $this->assertNotNull($purgeRow);
        $this->assertSame(1, $purgeRow->after['audit_logs']);
    }

    public function test_dry_run_reports_counts_without_deleting(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $oldLog = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => 'x',
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $oldLog->getKey(), 400);

        $counts = app(RetentionService::class)->purge($organization, true);

        $this->assertSame(1, $counts['audit_logs']);
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($oldLog->getKey())->first());
        $this->assertSame(0, AuditLog::withoutGlobalScope('organization')->where('action', 'retention.purged')->count());
    }

    public function test_purge_is_scoped_to_one_organization(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Carriers Inc.']);

        $logB = AuditLog::create([
            'organization_id' => $orgB->getKey(),
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => 'x',
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $logB->getKey(), 400);

        $counts = app(RetentionService::class)->purge($orgA);

        $this->assertSame(0, $counts['audit_logs']);
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($logB->getKey())->first());
    }

    public function test_an_organization_override_changes_the_cutoff(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $log = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => 'x',
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $log->getKey(), 20);

        // 20 days is inside the 365-day default: kept.
        app(RetentionService::class)->purge($organization);
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($log->getKey())->first());

        // With a 10-day override the same row is expired.
        $settings = $organization->settings ?? [];
        $settings['retention'] = ['audit_logs' => 10];
        $organization->update(['settings' => $settings]);

        app(RetentionService::class)->purge($organization->fresh());
        $this->assertNull(AuditLog::withoutGlobalScope('organization')->whereKey($log->getKey())->first());
    }

    public function test_purge_removes_only_failed_documents_and_their_files(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $failed = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'storage_key' => 'packages/'.$organization->getKey().'/'.$claim->getKey().'/failed.pdf',
            'disk' => 'private',
            'file_size' => 10,
            'status' => GeneratedDocumentStatus::Failed,
            'error_message' => 'Render failed',
        ]);
        $this->backdate('generated_documents', $failed->getKey(), 40);
        Storage::disk('private')->put($failed->storage_key, 'failed-bytes');

        $ready = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'storage_key' => 'packages/'.$organization->getKey().'/'.$claim->getKey().'/ready.pdf',
            'disk' => 'private',
            'file_size' => 10,
            'status' => GeneratedDocumentStatus::Ready,
            'generated_at' => now(),
        ]);
        $this->backdate('generated_documents', $ready->getKey(), 400);
        Storage::disk('private')->put($ready->storage_key, 'ready-bytes');

        $counts = app(RetentionService::class)->purge($organization);

        $this->assertSame(1, $counts['failed_documents']);
        $this->assertNull(GeneratedDocument::withoutGlobalScope('organization')->whereKey($failed->getKey())->first());
        $this->assertFalse(Storage::disk('private')->exists($failed->storage_key));
        // A ready package is never purged, however old.
        $this->assertNotNull(GeneratedDocument::withoutGlobalScope('organization')->whereKey($ready->getKey())->first());
        $this->assertTrue(Storage::disk('private')->exists($ready->storage_key));
    }

    public function test_purge_all_clears_global_job_executions_and_unattributed_events(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $job = JobExecution::create([
            'key' => 'test-job-'.uniqid(),
            'job' => 'TestJob',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $this->backdate('job_executions', $job->getKey(), 40);

        $event = SecurityEvent::create([
            'organization_id' => null,
            'user_id' => null,
            'type' => SecurityEvent::TYPE_LOGIN_FAILED,
            'ip_address' => '127.0.0.1',
            'metadata' => ['email' => 'nobody@example.com'],
        ]);
        $this->backdate('security_events', $event->getKey(), 200);

        $result = app(RetentionService::class)->purgeAll();

        $this->assertSame(1, $result['global']['job_executions']);
        $this->assertSame(1, $result['global']['security_events']);
        $this->assertNull(JobExecution::whereKey($job->getKey())->first());
        $this->assertNull(SecurityEvent::whereKey($event->getKey())->first());
    }

    public function test_retention_purge_command_supports_dry_run(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $log = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => 'x',
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $log->getKey(), 400);

        $exit = Artisan::call('retention:purge', ['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('audit_logs: 1', Artisan::output());
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($log->getKey())->first());
    }

    public function test_zero_days_disables_purging_for_a_kind(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        config(['retention.audit_logs_days' => 0]);

        $log = AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'action' => 'shipment.created',
            'entity_type' => 'Shipment',
            'entity_id' => 'x',
            'created_at' => now(),
        ]);
        $this->backdate('audit_logs', $log->getKey(), 9000);

        $counts = app(RetentionService::class)->purge($organization);

        $this->assertSame(0, $counts['audit_logs']);
        $this->assertNotNull(AuditLog::withoutGlobalScope('organization')->whereKey($log->getKey())->first());
    }
}
