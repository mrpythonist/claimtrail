<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Claim;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Services\BackupService;
use App\Support\BackupException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Restore testing (v1.1.3).
 *
 * The backup/restore round trip runs against a file-based SQLite
 * database, not the :memory: database the rest of the suite uses:
 * a backup is a copy of the database file, and an in-memory
 * database has no file to copy. Each test points the sqlite
 * connection at a fresh temp file and migrates it.
 */
class BackupRestoreTest extends TestCase
{
    protected string $tempDir;

    protected string $dbPath;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/claimtrail-restore-'.Str::random(8);
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

    /**
     * Seed one shipment, one claim, and one evidence file with
     * real bytes on the fake disk.
     *
     * @return array{shipment: Shipment, claim: Claim, evidence: EvidenceFile, bytes: string}
     */
    protected function seedData(): array
    {
        $shipment = $this->createShipment($this->organization);
        $claim = $this->createClaim($this->organization, $shipment);
        $evidence = $this->createEvidence($this->organization, $shipment);
        $bytes = 'evidence-bytes-'.Str::random(8);
        Storage::disk('private')->put($evidence->storage_key, $bytes);

        return [
            'shipment' => $shipment,
            'claim' => $claim,
            'evidence' => $evidence,
            'bytes' => $bytes,
        ];
    }

    /**
     * Wipe the seeded rows and the evidence file, as if the
     * data was lost.
     */
    protected function wipeData(array $seeded): void
    {
        Storage::disk('private')->delete($seeded['evidence']->storage_key);
        DB::table('evidence_files')->delete();
        DB::table('claims')->delete();
        DB::table('shipments')->delete();
    }

    public function test_backup_restore_round_trip_brings_the_data_back(): void
    {
        $seeded = $this->seedData();

        $run = app(BackupService::class)->create($this->organization);

        $this->assertTrue($run->isCompleted());
        $this->assertNotNull($run->checksum);
        $this->assertTrue(Storage::disk('private')->exists($run->path.'/manifest.json'));

        $this->wipeData($seeded);

        $this->assertSame(0, DB::table('shipments')->count());
        $this->assertSame(0, DB::table('claims')->count());
        $this->assertFalse(Storage::disk('private')->exists($seeded['evidence']->storage_key));

        app(BackupService::class)->restore($run);

        $this->assertSame(1, DB::table('shipments')->count());
        $this->assertSame(1, DB::table('claims')->count());
        $this->assertSame(1, DB::table('evidence_files')->count());
        $this->assertSame(
            $seeded['bytes'],
            Storage::disk('private')->get($seeded['evidence']->storage_key)
        );
        $this->assertNotNull($run->fresh()->restored_at);
    }

    public function test_restore_refuses_a_tampered_backup_and_leaves_live_data_alone(): void
    {
        $seeded = $this->seedData();

        $run = app(BackupService::class)->create($this->organization);

        // Tamper with the database copy after the backup.
        Storage::disk('private')->put($run->path.'/database.sqlite', 'tampered!');

        $check = app(BackupService::class)->verify($run);

        $this->assertFalse($check['ok']);
        $this->assertContains('Database copy checksum mismatch: database.sqlite', $check['mismatches']);

        try {
            app(BackupService::class)->restore($run);
            $this->fail('Restore should have refused the tampered backup.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Backup failed verification', $e->getMessage());
        }

        // The live data was never touched.
        $this->assertSame(1, DB::table('shipments')->count());
        $this->assertSame(1, DB::table('claims')->count());
    }

    public function test_restore_refuses_a_failed_backup_run(): void
    {
        $run = BackupRun::create([
            'organization_id' => $this->organization->getKey(),
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_FAILED,
            'error_message' => 'Something went wrong',
        ]);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Only a completed backup can be restored.');

        app(BackupService::class)->restore($run);
    }

    public function test_restore_command_restores_with_force(): void
    {
        $seeded = $this->seedData();

        $run = app(BackupService::class)->create($this->organization);
        $this->wipeData($seeded);

        $exit = Artisan::call('backup:restore', [
            'backup' => $run->getKey(),
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('restored', Artisan::output());
        $this->assertSame(1, DB::table('shipments')->count());
    }

    public function test_restore_command_refuses_an_unknown_backup(): void
    {
        $exit = Artisan::call('backup:restore', [
            'backup' => (string) Str::uuid(),
            '--force' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', Artisan::output());
    }
}
