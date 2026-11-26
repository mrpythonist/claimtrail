<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Verify a backup by re-hashing it (v1.2.3).
 *
 * Re-hashes the database copy and every file in the manifest and
 * compares against the manifest checksums. Verifies the latest
 * completed run by default, or a named run with --run. Exit code
 * 0 means the backup is intact; 1 means it is missing, corrupt,
 * or not completed. Scheduled weekly (Sunday 04:00) so a
 * silently rotting backup is caught before it is needed.
 */
class VerifyBackup extends Command
{
    protected $signature = 'backup:verify {--run= : UUID of the backup run to verify (default: latest completed)} {--latest : Verify the latest completed run}';

    protected $description = 'Verify a backup by re-hashing its contents against the manifest';

    public function handle(BackupService $backups): int
    {
        $run = null;

        if ($this->option('run') !== null) {
            $run = BackupRun::withoutGlobalScope('organization')
                ->whereKey((string) $this->option('run'))
                ->first();

            if ($run === null) {
                $this->error('Backup run not found: '.$this->option('run'));

                return self::FAILURE;
            }
        } else {
            $run = BackupRun::withoutGlobalScope('organization')
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->first();

            if ($run === null) {
                $this->error('No completed backup run found. Create one with backup:create first.');

                return self::FAILURE;
            }
        }

        if (! $run->isCompleted()) {
            $this->error('Backup '.$run->getKey().' is '.$run->status.' and cannot be verified.');

            return self::FAILURE;
        }

        $result = $backups->verify($run);

        if ($result['ok']) {
            $this->info('Backup '.$run->getKey().' verified OK ('.$run->created_at.').');

            return self::SUCCESS;
        }

        $this->error('Backup '.$run->getKey().' FAILED verification:');

        foreach ($result['mismatches'] as $mismatch) {
            $this->line('  - '.$mismatch);
        }

        return self::FAILURE;
    }
}
