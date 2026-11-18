<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Retries, forgets, and flushes failed jobs, tenant-scoped.
 *
 * Every action verifies the failed job belongs to the calling
 * organization first (see QueueMonitorService): retrying or
 * deleting another tenant's failed job is a cross-tenant write
 * and must not happen. Retry re-queues the original payload
 * exactly as Laravel's queue:retry does, then removes the
 * failed row.
 */
class FailedJobService
{
    public function __construct(
        protected QueueMonitorService $monitor,
    ) {}

    /**
     * Retry one failed job. Returns false when the job does not
     * exist or does not belong to the organization.
     */
    public function retry(string $uuid, string $organizationId): bool
    {
        $failed = $this->findForOrganization($uuid, $organizationId);

        if ($failed === null) {
            return false;
        }

        $this->requeue($failed);
        $this->forgetRow($uuid);

        return true;
    }

    /**
     * Retry every failed job of the organization. Returns the
     * number retried and the uuids that failed to re-queue.
     *
     * @return array{retried: int, failed: array<int, string>}
     */
    public function retryAll(string $organizationId): array
    {
        $retried = 0;
        $failed = [];

        foreach ($this->monitor->failedJobsForOrganization($organizationId) as $job) {
            try {
                if ($this->retry($job['uuid'], $organizationId)) {
                    $retried++;
                }
            } catch (Throwable $e) {
                $failed[] = $job['uuid'];
            }
        }

        return ['retried' => $retried, 'failed' => $failed];
    }

    /**
     * Delete one failed job without retrying it. Returns false
     * when the job does not exist or does not belong to the
     * organization.
     */
    public function forget(string $uuid, string $organizationId): bool
    {
        if ($this->findForOrganization($uuid, $organizationId) === null) {
            return false;
        }

        $this->forgetRow($uuid);

        return true;
    }

    /**
     * Delete the organization's failed jobs older than the given
     * hours. Returns the number deleted. Jobs whose organization
     * cannot be determined are left alone.
     */
    public function flushOlderThan(string $organizationId, int $hours): int
    {
        if (! $this->failedTableExists()) {
            return 0;
        }

        $cutoff = now()->subHours($hours);
        $deleted = 0;

        $rows = DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->where('failed_at', '<', $cutoff)
            ->orderBy('failed_at')
            ->limit(QueueMonitorService::FAILED_SCAN_LIMIT)
            ->get();

        foreach ($rows as $row) {
            if ($this->monitor->organizationIdForFailedJob($row) !== $organizationId) {
                continue;
            }

            $this->forgetRow((string) $row->uuid);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * The failed row, only if it belongs to the organization.
     */
    protected function findForOrganization(string $uuid, string $organizationId): ?object
    {
        if (! $this->failedTableExists()) {
            return null;
        }

        $row = DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->where('uuid', $uuid)
            ->first();

        if ($row === null || $this->monitor->organizationIdForFailedJob($row) !== $organizationId) {
            return null;
        }

        return $row;
    }

    /**
     * Push the original payload back onto its queue, exactly as
     * Laravel's queue:retry command does.
     */
    protected function requeue(object $failed): void
    {
        Queue::connection($failed->connection)->pushRaw($failed->payload, $failed->queue);
    }

    protected function forgetRow(string $uuid): void
    {
        DB::connection($this->failedDatabase())
            ->table($this->failedTable())
            ->where('uuid', $uuid)
            ->delete();
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
