<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DisasterRecoveryDrill;
use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Disaster recovery drills (v2.0.3).
 *
 * Runs against a file-based SQLite database, like
 * BackupRestoreTest: a backup is a copy of the database file,
 * so the drills need a real backup made from a real file. A
 * verify drill re-hashes the backup; a restore dry-run opens
 * a temporary copy read-only and counts its rows, without
 * ever touching the live database.
 */
class DisasterRecoveryTest extends TestCase
{
    protected string $tempDir;

    protected string $dbPath;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/claimtrail-dr-'.Str::random(8);
        mkdir($this->tempDir, 0777, true);
        $this->dbPath = $this->tempDir.'/test.sqlite';
        touch($this->dbPath);

        config(['database.connections.sqlite.database' => $this->dbPath]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        Storage::fake('private');

        $this->organization = Organization::create([
            'name' => 'Test Trucking Co.',
            'slug' => 'test-trucking-'.Str::lower(Str::random(8)),
            'timezone' => 'UTC',
        ]);

        [$user] = $this->actingAsMemberOf(OrganizationRole::Owner, $this->organization);
        $this->subscribe($this->organization, 'growth');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    protected function makeBackup(): BackupRun
    {
        $exit = Artisan::call('backup:create', [
            '--organization' => $this->organization->getKey(),
        ]);

        $this->assertSame(Command::SUCCESS, $exit, Artisan::output());

        return BackupRun::orderByDesc('created_at')->firstOrFail();
    }

    public function test_verify_drill_passes_on_a_real_backup(): void
    {
        $backup = $this->makeBackup();

        $this->withHeaders($this->orgHeaders($this->organization))
            ->postJson('/api/v1/enterprise/disaster-recovery/drills', [
                'kind' => 'verify',
                'backup_run_id' => $backup->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'verify')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.backup_run_id', $backup->getKey());

        $this->withHeaders($this->orgHeaders($this->organization))
            ->getJson('/api/v1/enterprise/disaster-recovery')
            ->assertOk()
            ->assertJsonPath('data.latest_backup.id', $backup->getKey())
            ->assertJsonPath('data.last_verified_backup.id', $backup->getKey())
            ->assertJsonPath('data.last_verified_backup.ok', true)
            ->assertJsonPath('data.total_drills', 1);
    }

    public function test_restore_dry_run_reads_the_backup_back_without_touching_live_data(): void
    {
        $backup = $this->makeBackup();
        $liveCount = Organization::count();

        $response = $this->withHeaders($this->orgHeaders($this->organization))
            ->postJson('/api/v1/enterprise/disaster-recovery/drills', [
                'kind' => 'restore_dry_run',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.kind', 'restore_dry_run')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.ok', true);

        $tables = $response->json('data.result.tables');
        $this->assertIsArray($tables);
        $this->assertArrayHasKey('organizations', $tables);
        $this->assertSame($liveCount, (int) $tables['organizations']);
        $this->assertGreaterThan(0, (int) $response->json('data.result.total_rows'));

        // The live database is untouched: same organizations,
        // and the drill recorded itself.
        $this->assertSame($liveCount, Organization::count());
        $this->assertSame(1, DisasterRecoveryDrill::count());
    }

    public function test_drill_command_runs_a_restore_dry_run(): void
    {
        $this->makeBackup();

        $exit = Artisan::call('dr:drill', [
            '--kind' => 'restore_dry_run',
            '--organization' => $this->organization->getKey(),
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('completed', Artisan::output());
        $this->assertSame(1, DisasterRecoveryDrill::count());
    }

    public function test_drill_command_fails_without_a_backup(): void
    {
        $exit = Artisan::call('dr:drill', [
            '--kind' => 'verify',
            '--organization' => $this->organization->getKey(),
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('No completed backup', Artisan::output());
    }
}
