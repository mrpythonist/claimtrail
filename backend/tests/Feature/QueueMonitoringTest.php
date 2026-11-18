<?php

namespace Tests\Feature;

use App\Jobs\GenerateClaimPackage;
use App\Jobs\SendNotificationEmail;
use App\Models\GeneratedDocument;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test suite runs on the sync queue; the monitoring
        // endpoints read the database queue tables.
        config(['queue.default' => 'database']);
    }

    /**
     * Seed one pending, one reserved, and one delayed job on the
     * default queue, plus one pending job on the mail queue.
     */
    protected function seedJobs(): void
    {
        $now = time();

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateClaimPackage']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now - 60,
                'created_at' => $now - 3600,
            ],
            [
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateClaimPackage']),
                'attempts' => 1,
                'reserved_at' => $now - 30,
                'available_at' => $now - 120,
                'created_at' => $now - 7200,
            ],
            [
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\SendNotificationEmail']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now + 600,
                'created_at' => $now - 10,
            ],
            [
                'queue' => 'mail',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\SendNotificationEmail']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now - 5,
                'created_at' => $now - 300,
            ],
        ]);
    }

    /**
     * Insert a failed SendNotificationEmail job for the org.
     */
    protected function seedFailedEmailJob(string $organizationId, ?string $failedAt = null): string
    {
        $uuid = (string) Str::uuid();
        $job = new SendNotificationEmail(1, $organizationId, 'claim.submitted', 'Title', 'Body');

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => SendNotificationEmail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3,
                'data' => [
                    'commandName' => SendNotificationEmail::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => "RuntimeException: Connection refused\nat vendor/...",
            'failed_at' => $failedAt ?? now()->toDateTimeString(),
        ]);

        return $uuid;
    }

    /**
     * Insert a failed GenerateClaimPackage job for the org.
     */
    protected function seedFailedPackageJob(string $organizationId, string $documentId): string
    {
        $uuid = (string) Str::uuid();
        $job = new GenerateClaimPackage($documentId);

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => GenerateClaimPackage::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3,
                'data' => [
                    'commandName' => GenerateClaimPackage::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => 'RuntimeException: PDF renderer crashed',
            'failed_at' => now()->toDateTimeString(),
        ]);

        return $uuid;
    }

    public function test_queue_stats_report_pending_reserved_and_delayed_counts(): void
    {
        $this->seedJobs();
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/queue')
            ->assertOk()
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.totals.pending', 2)
            ->assertJsonPath('data.totals.reserved', 1)
            ->assertJsonPath('data.totals.delayed', 1)
            ->assertJsonPath('data.totals.failed', 0);

        $queues = collect($response->json('data.queues'))->keyBy('queue');
        $this->assertSame(1, $queues['default']['pending']);
        $this->assertSame(1, $queues['default']['reserved']);
        $this->assertSame(1, $queues['default']['delayed']);
        $this->assertSame(1, $queues['mail']['pending']);
        $this->assertGreaterThanOrEqual(3590, $queues['default']['oldest_pending_age_seconds']);
        $this->assertLessThanOrEqual(3610, $queues['default']['oldest_pending_age_seconds']);
    }

    public function test_queue_stats_are_unsupported_for_non_database_drivers(): void
    {
        config(['queue.default' => 'sync']);
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/queue')
            ->assertOk()
            ->assertJsonPath('data.supported', false)
            ->assertJsonPath('data.driver', 'sync');
    }

    public function test_queue_endpoints_require_the_operations_permission(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/queue')
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/failed-jobs')
            ->assertForbidden();
    }

    public function test_failed_jobs_list_only_shows_the_organizations_jobs(): void
    {
        [, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization();

        $uuidA = $this->seedFailedEmailJob((string) $orgA->getKey());
        $this->seedFailedEmailJob((string) $orgB->getKey());

        // A job whose organization cannot be determined (unknown
        // payload shape) is hidden from everyone.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\MysteryJob']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now()->toDateTimeString(),
        ]);

        $response = $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/ops/failed-jobs')
            ->assertOk();

        $uuids = collect($response->json('data'))->pluck('uuid')->all();
        $this->assertSame([$uuidA], $uuids);
        $this->assertSame('SendNotificationEmail', $response->json('data.0.job'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_failed_package_job_resolves_its_organization_through_the_document(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $document = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'status' => 'queued',
        ]);

        $uuid = $this->seedFailedPackageJob((string) $organization->getKey(), (string) $document->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/failed-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.job', 'GenerateClaimPackage');
    }

    public function test_retry_requeues_the_payload_and_removes_the_failed_row(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $uuid = $this->seedFailedEmailJob((string) $organization->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/ops/failed-jobs/{$uuid}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Failed job re-queued.');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString(
            'SendNotificationEmail',
            (string) DB::table('jobs')->value('payload')
        );
    }

    public function test_retry_of_another_organizations_job_returns_404(): void
    {
        [, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization();
        $uuid = $this->seedFailedEmailJob((string) $orgB->getKey());

        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson("/api/v1/ops/failed-jobs/{$uuid}/retry")
            ->assertNotFound();

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_forget_deletes_the_failed_row(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $uuid = $this->seedFailedEmailJob((string) $organization->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/ops/failed-jobs/{$uuid}/forget")
            ->assertOk();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_retry_all_retries_only_the_organizations_jobs(): void
    {
        [, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization();
        $this->seedFailedEmailJob((string) $orgA->getKey());
        $this->seedFailedEmailJob((string) $orgA->getKey());
        $uuidB = $this->seedFailedEmailJob((string) $orgB->getKey());

        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/ops/failed-jobs/retry-all')
            ->assertOk()
            ->assertJsonPath('data.retried', 2)
            ->assertJsonPath('data.failed', []);

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuidB]);
    }

    public function test_flush_deletes_only_old_jobs_of_the_organization(): void
    {
        [, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization();

        $oldA = $this->seedFailedEmailJob((string) $orgA->getKey(), now()->subDays(10)->toDateTimeString());
        $recentA = $this->seedFailedEmailJob((string) $orgA->getKey(), now()->subHour()->toDateTimeString());
        $oldB = $this->seedFailedEmailJob((string) $orgB->getKey(), now()->subDays(10)->toDateTimeString());

        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/ops/failed-jobs/flush', ['hours' => 168])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $oldA]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $recentA]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $oldB]);
    }
}
