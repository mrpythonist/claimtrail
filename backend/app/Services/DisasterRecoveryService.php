<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\DisasterRecoveryDrill;
use App\Models\Organization;
use App\Models\User;
use App\Support\BackupException;
use Illuminate\Support\Facades\Storage;
use PDO;

/**
 * Disaster recovery status and drills (v2.0.3).
 *
 * RPO and RTO are configured objectives from
 * config/enterprise.php, not measured values. A drill proves a
 * backup can be read back; it does not prove failover, because
 * there is no standby host and no failover exists:
 * - verify: re-hash a backup against its manifest (the same
 *   check as `backup:verify`).
 * - restore_dry_run: copy the backup's SQLite database to a
 *   temporary file, open it read-only, and count its rows, then
 *   delete the temporary file. The live database is never
 *   touched. PostgreSQL backups cannot be dry-run restored
 *   here: that needs a live server and pg_restore, so the drill
 *   records a failure with the reason instead of pretending.
 *
 * The DR runbook lives in docs/ENTERPRISE-READINESS.md.
 */
class DisasterRecoveryService
{
    public function __construct(
        protected BackupService $backups,
    ) {}

    /**
     * The disaster recovery status for an organization.
     *
     * @return array<string, mixed>
     */
    public function status(Organization $organization): array
    {
        $latestBackup = $this->latestBackup($organization);
        $latestDrill = $this->latestDrill($organization);
        $lastVerified = $this->lastVerifiedBackup($organization);

        return [
            'rpo_minutes' => (int) config('enterprise.rpo_minutes', 1440),
            'rto_minutes' => (int) config('enterprise.rto_minutes', 240),
            'latest_backup' => $latestBackup !== null ? $this->presentBackup($latestBackup) : null,
            'last_verified_backup' => $lastVerified,
            'latest_drill' => $latestDrill !== null ? $this->presentDrill($latestDrill) : null,
            'total_drills' => DisasterRecoveryDrill::forOrganization($organization->getKey())->count(),
        ];
    }

    /**
     * Run a drill and store the result.
     */
    public function runDrill(
        Organization $organization,
        string $kind,
        ?string $backupRunId = null,
        ?User $actor = null,
    ): DisasterRecoveryDrill {
        $run = $this->resolveBackup($organization, $backupRunId);

        if ($run === null) {
            return $this->recordDrill($organization, $kind, null, 'failed', [
                'ok' => false,
                'error' => 'No completed backup run found. Create a backup first.',
            ], $actor);
        }

        if ($kind === DisasterRecoveryDrill::KIND_VERIFY) {
            $result = $this->backups->verify($run);

            return $this->recordDrill($organization, $kind, $run, $result['ok'] ? 'completed' : 'failed', [
                'ok' => $result['ok'],
                'backup_run_id' => $run->getKey(),
                'mismatches' => $result['mismatches'],
            ], $actor);
        }

        return $this->restoreDryRun($organization, $run, $actor);
    }

    /**
     * One drill as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function presentDrill(DisasterRecoveryDrill $drill): array
    {
        return [
            'id' => $drill->getKey(),
            'kind' => $drill->kind,
            'status' => $drill->status,
            'result' => $drill->result,
            'backup_run_id' => $drill->backup_run_id,
            'performed_by' => $drill->performed_by !== null ? [
                'id' => $drill->performed_by,
                'name' => $drill->performedBy?->name,
            ] : null,
            'performed_at' => $drill->performed_at->toIso8601String(),
            'created_at' => $drill->created_at->toIso8601String(),
        ];
    }

    /**
     * A restore dry-run: verify first, then open a temporary
     * copy of the backup database read-only and count rows.
     */
    protected function restoreDryRun(
        Organization $organization,
        BackupRun $run,
        ?User $actor,
    ): DisasterRecoveryDrill {
        $verification = $this->backups->verify($run);

        if (! $verification['ok']) {
            return $this->recordDrill($organization, DisasterRecoveryDrill::KIND_RESTORE_DRY_RUN, $run, 'failed', [
                'ok' => false,
                'backup_run_id' => $run->getKey(),
                'error' => 'Backup verification failed before the restore dry-run.',
                'mismatches' => $verification['mismatches'],
            ], $actor);
        }

        if ($run->driver !== 'sqlite') {
            return $this->recordDrill($organization, DisasterRecoveryDrill::KIND_RESTORE_DRY_RUN, $run, 'failed', [
                'ok' => false,
                'backup_run_id' => $run->getKey(),
                'error' => 'Restore dry-run supports SQLite backups only. A PostgreSQL backup needs a live server and pg_restore, which this instance does not have.',
            ], $actor);
        }

        $disk = Storage::disk(config('backup.disk'));
        $manifest = json_decode($disk->get($run->path.'/manifest.json'), true);
        $databaseFile = $run->path.'/'.($manifest['database']['file'] ?? 'database.sqlite');

        $tempPath = tempnam(sys_get_temp_dir(), 'claimtrail-dr-');

        if ($tempPath === false) {
            return $this->recordDrill($organization, DisasterRecoveryDrill::KIND_RESTORE_DRY_RUN, $run, 'failed', [
                'ok' => false,
                'backup_run_id' => $run->getKey(),
                'error' => 'Could not create a temporary file for the restore dry-run.',
            ], $actor);
        }

        try {
            file_put_contents($tempPath, $disk->get($databaseFile));

            $pdo = new PDO('sqlite:'.$tempPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $tables = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);

            $counts = [];
            $totalRows = 0;

            foreach ($tables as $table) {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
                $counts[$table] = $count;
                $totalRows += $count;
            }

            return $this->recordDrill($organization, DisasterRecoveryDrill::KIND_RESTORE_DRY_RUN, $run, 'completed', [
                'ok' => true,
                'backup_run_id' => $run->getKey(),
                'driver' => 'sqlite',
                'tables' => $counts,
                'table_count' => count($counts),
                'total_rows' => $totalRows,
                'note' => 'Read-only open of a temporary copy. The live database was not touched.',
            ], $actor);
        } catch (\Throwable $e) {
            return $this->recordDrill($organization, DisasterRecoveryDrill::KIND_RESTORE_DRY_RUN, $run, 'failed', [
                'ok' => false,
                'backup_run_id' => $run->getKey(),
                'error' => 'Restore dry-run failed: '.$e->getMessage(),
            ], $actor);
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    protected function recordDrill(
        Organization $organization,
        string $kind,
        ?BackupRun $run,
        string $status,
        array $result,
        ?User $actor,
    ): DisasterRecoveryDrill {
        return DisasterRecoveryDrill::create([
            'organization_id' => $organization->getKey(),
            'backup_run_id' => $run?->getKey(),
            'kind' => $kind,
            'status' => $status,
            'result' => $result,
            'performed_by' => $actor?->getKey(),
            'performed_at' => now(),
        ]);
    }

    /**
     * The latest completed backup: the organization's own run
     * first, else the latest scheduled (unattributed) run.
     */
    public function latestBackup(Organization $organization): ?BackupRun
    {
        return BackupRun::forOrganization($organization->getKey())
            ->where('status', BackupRun::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->first()
            ?? BackupRun::withoutGlobalScope('organization')
                ->where('status', BackupRun::STATUS_COMPLETED)
                ->orderByDesc('created_at')
                ->first();
    }

    protected function latestDrill(Organization $organization): ?DisasterRecoveryDrill
    {
        return DisasterRecoveryDrill::forOrganization($organization->getKey())
            ->orderByDesc('performed_at')
            ->first();
    }

    /**
     * The most recently verified backup, from the drill history:
     * the latest verify drill that passed.
     *
     * @return array<string, mixed>|null
     */
    protected function lastVerifiedBackup(Organization $organization): ?array
    {
        $drill = DisasterRecoveryDrill::forOrganization($organization->getKey())
            ->where('kind', DisasterRecoveryDrill::KIND_VERIFY)
            ->where('status', DisasterRecoveryDrill::STATUS_COMPLETED)
            ->orderByDesc('performed_at')
            ->first();

        if ($drill === null) {
            return null;
        }

        return [
            'id' => $drill->backup_run_id,
            'verified_at' => $drill->performed_at->toIso8601String(),
            'ok' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBackup(BackupRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'status' => $run->status,
            'driver' => $run->driver,
            'size_bytes' => $run->size_bytes !== null ? (int) $run->size_bytes : null,
            'checksum' => $run->checksum,
            'created_at' => $run->created_at->toIso8601String(),
        ];
    }

    /**
     * Resolve a backup by id (must belong to the organization
     * or be an unattributed scheduled run), or fall back to the
     * latest completed backup.
     */
    protected function resolveBackup(Organization $organization, ?string $backupRunId): ?BackupRun
    {
        if ($backupRunId !== null) {
            $run = BackupRun::withoutGlobalScope('organization')
                ->whereKey($backupRunId)
                ->first();

            if ($run === null) {
                return null;
            }

            if ($run->organization_id !== null && $run->organization_id !== $organization->getKey()) {
                return null;
            }

            return $run->status === BackupRun::STATUS_COMPLETED ? $run : null;
        }

        return $this->latestBackup($organization);
    }
}
