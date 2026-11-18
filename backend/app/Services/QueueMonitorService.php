<?php

namespace App\Services;

use App\Models\GeneratedDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads queue depth and failed jobs for the operations endpoints.
 *
 * Queue stats only work for the database driver: other drivers do
 * not expose their depth through a table this service can read.
 * Failed jobs are tenant-filtered: a job is shown to an
 * organization only when its payload names that organization
 * (directly, or through the document it renders). Jobs whose
 * organization cannot be determined are hidden rather than
 * leaked across tenants.
 */
class QueueMonitorService
{
    /**
     * Rows scanned when listing failed jobs. Failed jobs are
     * flushed regularly, so the table stays small; the cap keeps
     * a runaway table from loading the whole thing into memory.
     */
    public const FAILED_SCAN_LIMIT = 500;

    /**
     * Queue depth by queue name, plus totals.
     *
     * @return array{supported: bool, driver: string, connection: string, queues: array<int, array{queue: string, pending: int, reserved: int, delayed: int, oldest_pending_age_seconds: int|null}>, totals: array{pending: int, reserved: int, delayed: int, failed: int}}
     */
    public function stats(): array
    {
        $driver = (string) config('queue.default');
        $totals = ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'failed' => $this->failedCount()];

        if ($driver !== 'database') {
            return [
                'supported' => false,
                'driver' => $driver,
                'connection' => $driver,
                'queues' => [],
                'totals' => $totals,
            ];
        }

        $table = (string) config('queue.connections.database.table', 'jobs');
        $now = time();

        $rows = DB::table($table)
            ->select('queue')
            ->selectRaw('sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as pending', [$now])
            ->selectRaw('sum(case when reserved_at is not null then 1 else 0 end) as reserved', [])
            ->selectRaw('sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed', [$now])
            ->selectRaw('min(case when reserved_at is null and available_at <= ? then created_at end) as oldest_pending_created_at', [$now])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $queues = [];
        foreach ($rows as $row) {
            $queues[] = [
                'queue' => $row->queue,
                'pending' => (int) $row->pending,
                'reserved' => (int) $row->reserved,
                'delayed' => (int) $row->delayed,
                'oldest_pending_age_seconds' => $row->oldest_pending_created_at !== null
                    ? max(0, $now - (int) $row->oldest_pending_created_at)
                    : null,
            ];
            $totals['pending'] += (int) $row->pending;
            $totals['reserved'] += (int) $row->reserved;
            $totals['delayed'] += (int) $row->delayed;
        }

        return [
            'supported' => true,
            'driver' => $driver,
            'connection' => $driver,
            'queues' => $queues,
            'totals' => $totals,
        ];
    }

    /**
     * Failed jobs belonging to the organization, newest first.
     *
     * @return array<int, array{uuid: string, connection: string, queue: string, job: string, failed_at: string, exception: string}>
     */
    public function failedJobsForOrganization(string $organizationId): array
    {
        $jobs = [];

        foreach ($this->failedRows() as $row) {
            if ($this->organizationIdForFailedJob($row) !== $organizationId) {
                continue;
            }

            $jobs[] = $this->summarizeFailedJob($row);
        }

        return $jobs;
    }

    /**
     * One failed job, only if it belongs to the organization.
     *
     * @return array{uuid: string, connection: string, queue: string, job: string, failed_at: string, exception: string}|null
     */
    public function failedJobForOrganization(string $uuid, string $organizationId): ?array
    {
        $row = $this->failedRow($uuid);

        if ($row === null || $this->organizationIdForFailedJob($row) !== $organizationId) {
            return null;
        }

        return $this->summarizeFailedJob($row);
    }

    /**
     * The organization a failed job belongs to, from its payload.
     *
     * SendNotificationEmail carries its organization id in the
     * serialized command; GenerateClaimPackage carries a document
     * id, resolved through the generated_documents row. Anything
     * else returns null and is treated as not belonging to any
     * organization the API will show.
     *
     * The payload is JSON, and the serialized command inside it
     * has its quotes backslash-escaped, so the JSON must be
     * decoded before the regex runs against the command string.
     */
    public function organizationIdForFailedJob(object $failed): ?string
    {
        $payload = is_string($failed->payload ?? null) ? $failed->payload : '';
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) && is_string($decoded['data']['command'] ?? null)
            ? $decoded['data']['command']
            : $payload;

        if (preg_match('/organizationId";s:\d+:"([0-9a-f-]{36})"/', $command, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/documentId";s:\d+:"([0-9a-f-]{36})"/', $command, $m) === 1) {
            return GeneratedDocument::query()
                ->withoutGlobalScope('organization')
                ->whereKey($m[1])
                ->value('organization_id');
        }

        return null;
    }

    /**
     * Total failed jobs across all organizations (a bare count;
     * no payload content, so it is safe to show to any org admin).
     */
    public function failedCount(): int
    {
        if (! $this->failedTableExists()) {
            return 0;
        }

        return (int) DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->count();
    }

    /**
     * Short summary of a failed job row for the API.
     *
     * @return array{uuid: string, connection: string, queue: string, job: string, failed_at: string, exception: string}
     */
    protected function summarizeFailedJob(object $row): array
    {
        $payload = json_decode(is_string($row->payload ?? null) ? $row->payload : '', true);
        $displayName = is_array($payload) ? (string) ($payload['displayName'] ?? '') : '';
        $job = $displayName !== '' ? class_basename($displayName) : 'Unknown job';

        return [
            'uuid' => (string) $row->uuid,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'job' => $job,
            'failed_at' => (string) $row->failed_at,
            'exception' => mb_substr((string) ($row->exception ?? ''), 0, 2000),
        ];
    }

    /**
     * Failed rows, newest first, capped at the scan limit.
     *
     * @return array<int, object>
     */
    protected function failedRows(): array
    {
        if (! $this->failedTableExists()) {
            return [];
        }

        return DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->orderByDesc('failed_at')
            ->limit(self::FAILED_SCAN_LIMIT)
            ->get()
            ->all();
    }

    protected function failedRow(string $uuid): ?object
    {
        if (! $this->failedTableExists()) {
            return null;
        }

        return DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->where('uuid', $uuid)
            ->first();
    }

    protected function failedTableExists(): bool
    {
        if ((string) config('queue.failed.driver') !== 'database-uuids') {
            return false;
        }

        return Schema::connection($this->failedDatabase())->hasTable($this->failedTable());
    }

    protected function failedDatabase(): ?string
    {
        return config('queue.failed.database') ?: null;
    }

    protected function failedTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }
}
