<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\BackupService;
use App\Support\BackupException;
use Illuminate\Console\Command;

/**
 * Create a deployment backup (database + stored files).
 *
 * The run is attributed to the organization given with
 * --organization; a scheduled run has none. On SQLite the
 * database must be a file: an in-memory database fails with a
 * clear message instead of a fake success. On PostgreSQL the
 * pg_dump binary must exist.
 */
class CreateBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:create {--organization= : UUID of the organization to attribute the run to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a backup of the database and stored files';

    /**
     * Execute the console command.
     */
    public function handle(BackupService $backups): int
    {
        $organization = null;

        if ($this->option('organization') !== null) {
            $organization = Organization::query()
                ->whereKey($this->option('organization'))
                ->first();

            if ($organization === null) {
                $this->error('Organization not found: '.$this->option('organization'));

                return self::FAILURE;
            }
        }

        try {
            $run = $backups->create($organization);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup completed: '.$run->getKey());
        $this->line('Driver: '.$run->driver);
        $this->line('Size: '.number_format($run->size_bytes ?? 0).' bytes');
        $this->line('Checksum: '.$run->checksum);

        return self::SUCCESS;
    }
}
