<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\OrganizationRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backup verification command (v1.2.3).
 *
 * `backup:verify` re-hashes the latest completed backup run (or
 * --run=<uuid>) against its manifest. Exit code 0 means the
 * backup is intact; 1 means it is missing, corrupt, or not
 * completed. The API endpoint verifies the same way; the command
 * is the scheduled path (weekly, Sundays 04:00).
 */
class VerifyBackupTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function wellFormedRun(): BackupRun
    {
        Storage::fake('private');

        $run = BackupRun::create([
            'organization_id' => null,
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_COMPLETED,
            'path' => 'backups/test-run',
            'size_bytes' => 11,
            'checksum' => hash('sha256', 'database!'),
        ]);

        Storage::disk('private')->put('backups/test-run/database.sqlite', 'database!');
        Storage::disk('private')->put('backups/test-run/manifest.json', json_encode([
            'driver' => 'sqlite',
            'app_version' => '1.2.3',
            'created_at' => now()->toIso8601String(),
            'database' => [
                'file' => 'database.sqlite',
                'sha256' => hash('sha256', 'database!'),
                'size_bytes' => 9,
            ],
            'files' => [],
        ]));

        return $run;
    }

    public function test_verify_latest_completed_run_passes(): void
    {
        $this->wellFormedRun();

        $exitCode = Artisan::call('backup:verify');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('verified OK', Artisan::output());
    }

    public function test_verify_named_run_passes(): void
    {
        $run = $this->wellFormedRun();

        $exitCode = Artisan::call('backup:verify', ['--run' => $run->getKey()]);

        $this->assertSame(0, $exitCode);
    }

    public function test_verify_fails_when_a_file_is_tampered(): void
    {
        $run = $this->wellFormedRun();

        Storage::disk('private')->put('backups/test-run/database.sqlite', 'tampered!');

        $exitCode = Artisan::call('backup:verify', ['--run' => $run->getKey()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAILED verification', Artisan::output());
    }

    public function test_verify_fails_with_no_completed_run(): void
    {
        $exitCode = Artisan::call('backup:verify');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No completed backup run found', Artisan::output());
    }

    public function test_verify_fails_for_an_unknown_run(): void
    {
        $exitCode = Artisan::call('backup:verify', ['--run' => '00000000-0000-0000-0000-000000000000']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found', Artisan::output());
    }

    public function test_verify_fails_for_an_incomplete_run(): void
    {
        Storage::fake('private');

        $run = BackupRun::create([
            'organization_id' => null,
            'driver' => 'sqlite',
            'status' => BackupRun::STATUS_RUNNING,
            'path' => 'backups/running-run',
            'size_bytes' => 0,
            'checksum' => null,
        ]);

        $exitCode = Artisan::call('backup:verify', ['--run' => $run->getKey()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cannot be verified', Artisan::output());
    }
}
