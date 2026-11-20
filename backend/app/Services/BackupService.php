<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Support\BackupException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Creates, verifies, and restores deployment backups.
 *
 * A backup is deployment-wide: the whole database plus a copy of
 * every evidence and package file, with a manifest.json holding
 * SHA-256 checksums for all of it. The run directory is
 * `{backup.path}/{run-id}` on the backup disk.
 *
 * Driver support is honest about what this VM can do:
 * - sqlite with a file database: fully supported (copy + verify
 *   + restore, all tested).
 * - sqlite with an in-memory database: refused with a clear
 *   message; there is no file to copy.
 * - pgsql: pg_dump / pg_restore are attempted when the binaries
 *   exist; when they do not, the run fails with a clear message
 *   instead of reporting success. Never tested against live
 *   PostgreSQL on this VM.
 *
 * Restore verifies every checksum in the manifest before it
 * touches the live database, and refuses on any mismatch.
 */
class BackupService
{
    /**
     * Create a backup and return its run row.
     *
     * The run row is created first (status running) so a failure
     * mid-backup is still recorded, with the error message.
     *
     * @throws BackupException when the backup cannot be made.
     */
    public function create(?Organization $organization = null): BackupRun
    {
        $driver = (string) config('database.default');

        $this->assertDriverSupported($driver);

        $run = BackupRun::create([
            'organization_id' => $organization?->getKey(),
            'driver' => $driver,
            'status' => BackupRun::STATUS_RUNNING,
        ]);

        try {
            $directory = config('backup.path').'/'.$run->getKey();
            $disk = Storage::disk(config('backup.disk'));

            $database = $this->dumpDatabase($driver, $disk, $directory);
            $files = $this->copyStoredFiles($disk, $directory);

            $manifest = [
                'driver' => $driver,
                'app_version' => config('app.version'),
                'created_at' => now()->toIso8601String(),
                'database' => $database,
                'files' => $files,
            ];

            $disk->put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            $size = $database['size_bytes']
                + array_sum(array_column($files, 'size_bytes'))
                + $disk->size($directory.'/manifest.json');

            $run->update([
                'status' => BackupRun::STATUS_COMPLETED,
                'path' => $directory,
                'size_bytes' => $size,
                'checksum' => $database['sha256'],
            ]);

            return $run->fresh();
        } catch (BackupException $e) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw new BackupException('Backup failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Re-hash the database copy and every file in the manifest.
     *
     * @return array{ok: bool, mismatches: array<int, string>}
     */
    public function verify(BackupRun $run): array
    {
        $mismatches = [];
        $disk = Storage::disk(config('backup.disk'));

        if ($run->path === null || ! $disk->exists($run->path.'/manifest.json')) {
            return ['ok' => false, 'mismatches' => ['manifest.json is missing from the backup directory.']];
        }

        $manifest = json_decode($disk->get($run->path.'/manifest.json'), true);

        if (! is_array($manifest) || ! isset($manifest['database'])) {
            return ['ok' => false, 'mismatches' => ['manifest.json is unreadable.']];
        }

        $databaseFile = $run->path.'/'.$manifest['database']['file'];

        if (! $disk->exists($databaseFile)) {
            $mismatches[] = 'Database copy is missing: '.$manifest['database']['file'];
        } elseif (hash('sha256', $disk->get($databaseFile)) !== $manifest['database']['sha256']) {
            $mismatches[] = 'Database copy checksum mismatch: '.$manifest['database']['file'];
        }

        if ($run->checksum !== null && $run->checksum !== $manifest['database']['sha256']) {
            $mismatches[] = 'Backup run checksum does not match the manifest.';
        }

        foreach ($manifest['files'] ?? [] as $file) {
            $stored = $run->path.'/files/'.$file['key'];

            if (! $disk->exists($stored)) {
                $mismatches[] = 'Stored file is missing from the backup: '.$file['key'];

                continue;
            }

            if (hash('sha256', $disk->get($stored)) !== $file['sha256']) {
                $mismatches[] = 'Stored file checksum mismatch: '.$file['key'];
            }
        }

        return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
    }

    /**
     * Restore a backup over the live database and stored files.
     *
     * Every checksum in the manifest is verified first; on any
     * mismatch the restore refuses and the live data is untouched.
     *
     * @throws BackupException when verification fails or the
     *                         restore cannot be performed for this driver.
     */
    public function restore(BackupRun $run): void
    {
        if (! $run->isCompleted()) {
            throw new BackupException('Only a completed backup can be restored.');
        }

        $check = $this->verify($run);

        if (! $check['ok']) {
            throw new BackupException(
                'Backup failed verification and was not restored: '.implode('; ', $check['mismatches'])
            );
        }

        $disk = Storage::disk(config('backup.disk'));
        $manifest = json_decode($disk->get($run->path.'/manifest.json'), true);
        $driver = $manifest['database']['driver'] ?? $run->driver;

        if ($driver === 'sqlite') {
            $this->restoreSqlite($run, $manifest, $disk);
        } elseif ($driver === 'pgsql') {
            $this->restorePostgres($run, $manifest, $disk);
        } else {
            throw new BackupException('Cannot restore a backup from driver "'.$driver.'".');
        }

        // Files are restored after the database, from the verified
        // copies, onto the evidence disk.
        $evidenceDisk = Storage::disk(config('evidence.disk'));

        foreach ($manifest['files'] ?? [] as $file) {
            $evidenceDisk->put(
                $file['key'],
                $disk->get($run->path.'/files/'.$file['key'])
            );
        }

        $run->update(['restored_at' => now()]);
    }

    /**
     * Fail fast for drivers this service cannot back up.
     *
     * @throws BackupException
     */
    protected function assertDriverSupported(string $driver): void
    {
        if ($driver === 'sqlite') {
            $database = (string) config('database.connections.sqlite.database');

            if ($database === ':memory:' || $database === '') {
                throw new BackupException(
                    'The SQLite database is in-memory and cannot be backed up to a file. '.
                    'Configure a file-based SQLite database or PostgreSQL to enable backups.'
                );
            }

            if (! is_file($database)) {
                throw new BackupException(
                    'The SQLite database file does not exist: '.$database
                );
            }

            return;
        }

        if ($driver === 'pgsql') {
            if ($this->binaryPath(config('backup.pg_dump_path')) === null) {
                throw new BackupException(
                    'pg_dump was not found on this machine, so the PostgreSQL database cannot be backed up. '.
                    'Install the PostgreSQL client tools or set PG_DUMP_PATH.'
                );
            }

            return;
        }

        throw new BackupException('Database driver "'.$driver.'" is not supported by backups.');
    }

    /**
     * Dump the database into the run directory.
     *
     * @return array{driver: string, file: string, sha256: string, size_bytes: int}
     *
     * @throws BackupException
     */
    protected function dumpDatabase(string $driver, $disk, string $directory): array
    {
        if ($driver === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new BackupException('Could not read the SQLite database file.');
            }

            $disk->put($directory.'/database.sqlite', $contents);

            return [
                'driver' => 'sqlite',
                'file' => 'database.sqlite',
                'sha256' => hash('sha256', $contents),
                'size_bytes' => strlen($contents),
            ];
        }

        // pgsql: stream pg_dump into the run directory. This path
        // has never run against live PostgreSQL on this VM; a
        // missing binary is caught by assertDriverSupported, and a
        // failed dump throws instead of recording a success.
        $connection = config('database.connections.pgsql');
        $binary = $this->binaryPath(config('backup.pg_dump_path'));

        // pg_dump will not create the run directory itself.
        $disk->makeDirectory($directory);
        $target = $disk->path($directory.'/database.dump');

        $process = new Process([
            $binary,
            '--format=custom',
            '--file='.$target,
            '--dbname='.(string) ($connection['database'] ?? ''),
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            '--no-password',
        ], null, [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($target)) {
            throw new BackupException(
                'pg_dump failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $contents = file_get_contents($target);

        return [
            'driver' => 'pgsql',
            'file' => 'database.dump',
            'sha256' => hash('sha256', $contents),
            'size_bytes' => strlen($contents),
        ];
    }

    /**
     * Copy every evidence and package file into the run directory.
     *
     * @return array<int, array{key: string, sha256: string, size_bytes: int}>
     */
    protected function copyStoredFiles($disk, string $directory): array
    {
        $evidenceDisk = Storage::disk(config('evidence.disk'));
        $copied = [];

        foreach (['evidence', 'packages'] as $prefix) {
            foreach ($evidenceDisk->allFiles($prefix) as $key) {
                $contents = $evidenceDisk->get($key);

                if ($contents === null) {
                    continue;
                }

                $disk->put($directory.'/files/'.$key, $contents);

                $copied[] = [
                    'key' => $key,
                    'sha256' => hash('sha256', $contents),
                    'size_bytes' => strlen($contents),
                ];
            }
        }

        return $copied;
    }

    /**
     * Restore a SQLite backup: replace the live file, then drop
     * the connection so the next query opens the restored file.
     */
    protected function restoreSqlite(BackupRun $run, array $manifest, $disk): void
    {
        $livePath = (string) config('database.connections.sqlite.database');

        if ($livePath === ':memory:' || $livePath === '') {
            throw new BackupException(
                'Cannot restore a SQLite backup into an in-memory database.'
            );
        }

        $contents = $disk->get($run->path.'/'.$manifest['database']['file']);

        if (file_put_contents($livePath, $contents) === false) {
            throw new BackupException('Could not write the restored database file.');
        }

        DB::purge(config('database.default'));
        DB::connection()->getPdo();
    }

    /**
     * Restore a PostgreSQL backup with pg_restore.
     *
     * Never run against live PostgreSQL on this VM.
     *
     * @throws BackupException
     */
    protected function restorePostgres(BackupRun $run, array $manifest, $disk): void
    {
        $binary = $this->binaryPath(config('backup.pg_restore_path'));

        if ($binary === null) {
            throw new BackupException(
                'pg_restore was not found on this machine, so the PostgreSQL backup cannot be restored. '.
                'Install the PostgreSQL client tools or set PG_RESTORE_PATH.'
            );
        }

        $connection = config('database.connections.pgsql');

        $process = new Process([
            $binary,
            '--clean',
            '--if-exists',
            '--dbname='.(string) ($connection['database'] ?? ''),
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            '--no-password',
            $disk->path($run->path.'/'.$manifest['database']['file']),
        ], null, [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new BackupException(
                'pg_restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Resolve a binary name to an executable path, or null.
     */
    protected function binaryPath(?string $binary): ?string
    {
        if ($binary === null || $binary === '') {
            return null;
        }

        if (str_contains($binary, '/') && is_executable($binary)) {
            return $binary;
        }

        $found = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        return $found !== '' && is_executable($found) ? $found : null;
    }
}
