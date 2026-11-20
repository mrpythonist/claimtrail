<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\BackupService;
use App\Support\BackupException;
use Illuminate\Console\Command;

/**
 * Restore a backup over the live database and stored files.
 *
 * Every checksum in the backup manifest is verified before
 * anything is touched; on any mismatch the restore refuses and
 * the live data is unchanged. Restoring replaces the live
 * database: run it during a maintenance window, and only after
 * confirming the backup you mean to restore.
 */
class RestoreBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore {backup : UUID of the backup run to restore} {--force : Restore without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a backup over the live database and stored files';

    /**
     * Execute the console command.
     */
    public function handle(BackupService $backups): int
    {
        $run = BackupRun::withoutGlobalScope('organization')
            ->whereKey($this->argument('backup'))
            ->first();

        if ($run === null) {
            $this->error('Backup run not found: '.$this->argument('backup'));

            return self::FAILURE;
        }

        if (! $run->isCompleted()) {
            $this->error('Backup '.$run->getKey().' is '.$run->status.' and cannot be restored.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This replaces the live database and stored files with backup '.$run->getKey().
            ' (created '.$run->created_at.'). Continue?'
        )) {
            $this->line('Restore cancelled.');

            return self::SUCCESS;
        }

        try {
            $backups->restore($run);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup '.$run->getKey().' restored.');

        return self::SUCCESS;
    }
}
