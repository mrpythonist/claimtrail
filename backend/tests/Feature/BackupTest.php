<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\OrganizationRole;
use App\Services\BackupService;
use App\Support\BackupException;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backup configuration tests (v1.1.3).
 *
 * Covers the API surface, the artisan command, and the honest
 * failure modes: an in-memory SQLite database and a missing
 * pg_dump binary both fail with a clear message instead of a
 * fake success. The full create-wipe-restore round trip lives
 * in BackupRestoreTest, which runs against a file database.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_create_fails_on_an_in_memory_database(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/backups')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The SQLite database is in-memory and cannot be backed up to a file. Configure a file-based SQLite database or PostgreSQL to enable backups.');

        $this->assertSame(0, BackupRun::count());
    }

    public function test_backup_create_command_fails_on_an_in_memory_database(): void
    {
        $exit = Artisan::call('backup:create');

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('in-memory', Artisan::output());
        $this->assertSame(0, BackupRun::count());
    }

    public function test_pgsql_backup_fails_gracefully_when_pg_dump_is_missing(): void
    {
        config([
            'database.default' => 'pgsql',
            'backup.pg_dump_path' => '/nonexistent/pg_dump',
        ]);

        try {
            $this->expectException(BackupException::class);
            $this->expectExceptionMessage('pg_dump was not found');

            app(BackupService::class)->create();
        } finally {
            // The default connection must be sqlite again before
            // tearDown: RefreshDatabase rolls its transaction back
            // on the default connection, and there is no Postgres
            // server on this VM.
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_backup_endpoints_require_manage_security(): void
    {
        [$viewer, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/backups')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/backups')->assertForbidden();

        [$admin, $adminOrg] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($adminOrg))->getJson('/api/v1/backups')->assertOk();
    }

    public function test_backup_verify_reports_a_missing_manifest(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        Storage::fake('private');

        $run = BackupRun::create([
            'organization_id' => $organization->getKey(),
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_COMPLETED,
            'path' => 'backups/does-not-exist',
            'size_bytes' => 0,
            'checksum' => 'abc',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/backups/'.$run->getKey().'/verify')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.mismatches.0', 'manifest.json is missing from the backup directory.');
    }

    public function test_backup_show_is_404_for_another_organizations_run(): void
    {
        [$userA, $orgA] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $orgB = $this->createOrganization(['name' => 'Other Carriers Inc.']);

        $runB = BackupRun::create([
            'organization_id' => $orgB->getKey(),
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_COMPLETED,
            'path' => 'backups/some-run',
            'size_bytes' => 0,
            'checksum' => 'abc',
        ]);

        $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/backups/'.$runB->getKey())
            ->assertNotFound();
        $this->withHeaders($this->orgHeaders($orgA))
            ->postJson('/api/v1/backups/'.$runB->getKey().'/verify')
            ->assertNotFound();
    }

    public function test_verify_of_a_well_formed_backup_passes(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        Storage::fake('private');

        $run = BackupRun::create([
            'organization_id' => $organization->getKey(),
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_COMPLETED,
            'path' => 'backups/test-run',
            'size_bytes' => 11,
            'checksum' => hash('sha256', 'database!'),
        ]);

        Storage::disk('private')->put('backups/test-run/database.sqlite', 'database!');
        Storage::disk('private')->put('backups/test-run/manifest.json', json_encode([
            'driver' => 'sqlite',
            'app_version' => '1.1.3',
            'created_at' => now()->toIso8601String(),
            'database' => [
                'file' => 'database.sqlite',
                'sha256' => hash('sha256', 'database!'),
                'size_bytes' => 9,
            ],
            'files' => [
                ['key' => 'evidence/org/x.pdf', 'sha256' => hash('sha256', 'file!'), 'size_bytes' => 5],
            ],
        ]));
        Storage::disk('private')->put('backups/test-run/files/evidence/org/x.pdf', 'file!');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/backups/'.$run->getKey().'/verify')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.mismatches', []);
    }

    public function test_verify_catches_a_tampered_file(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        Storage::fake('private');

        $run = BackupRun::create([
            'organization_id' => $organization->getKey(),
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_COMPLETED,
            'path' => 'backups/test-run',
            'size_bytes' => 11,
            'checksum' => hash('sha256', 'database!'),
        ]);

        Storage::disk('private')->put('backups/test-run/database.sqlite', 'database!');
        Storage::disk('private')->put('backups/test-run/manifest.json', json_encode([
            'driver' => 'sqlite',
            'app_version' => '1.1.3',
            'created_at' => now()->toIso8601String(),
            'database' => [
                'file' => 'database.sqlite',
                'sha256' => hash('sha256', 'database!'),
                'size_bytes' => 9,
            ],
            'files' => [
                ['key' => 'evidence/org/x.pdf', 'sha256' => hash('sha256', 'file!'), 'size_bytes' => 5],
            ],
        ]));
        Storage::disk('private')->put('backups/test-run/files/evidence/org/x.pdf', 'tampered!');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/backups/'.$run->getKey().'/verify')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.mismatches.0', 'Stored file checksum mismatch: evidence/org/x.pdf');
    }
}
