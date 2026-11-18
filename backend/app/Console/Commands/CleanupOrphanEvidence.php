<?php

namespace App\Console\Commands;

use App\Models\EvidenceFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes evidence objects with no database row.
 *
 * Orphans appear when an upload's file lands but the database
 * insert fails (or a delete removes the row but not the object).
 * Only objects older than one hour are touched, so a file whose
 * upload is still in flight is never deleted. --dry-run lists
 * what would be deleted without deleting anything.
 */
class CleanupOrphanEvidence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'claimtrail:cleanup-orphan-evidence {--dry-run : List orphaned objects without deleting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete evidence objects with no matching database row';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = Storage::disk(config('evidence.disk'));
        $cutoff = now()->subHour();
        $orphans = [];

        foreach ($disk->allFiles('evidence') as $key) {
            if (EvidenceFile::where('storage_key', $key)->exists()) {
                continue;
            }

            $modified = $disk->lastModified($key);

            if ($modified !== false && $modified > $cutoff->getTimestamp()) {
                continue;
            }

            $orphans[] = $key;
        }

        if ($orphans === []) {
            $this->info('No orphaned evidence objects found.');

            return self::SUCCESS;
        }

        foreach ($orphans as $key) {
            if ($this->option('dry-run')) {
                $this->line('Would delete: '.$key);
            } else {
                $disk->delete($key);
                $this->line('Deleted: '.$key);
            }
        }

        $this->info(count($orphans).' orphaned evidence object(s) '.($this->option('dry-run') ? 'found.' : 'deleted.'));

        return self::SUCCESS;
    }
}
