<?php

namespace App\Services;

use App\GeneratedDocumentStatus;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\JobExecution;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Storage;

/**
 * Data retention: deletes old rows that no longer need keeping.
 *
 * Retention is configured per kind in days (config/retention.php
 * defaults, overridable per organization through the retention
 * settings endpoint). A value of 0 disables purging for that
 * kind: the records are kept forever.
 *
 * What is purged, and the boundary:
 * - audit_logs: rows older than the cutoff. This is the one
 *   sanctioned deletion path for the append-only audit trail,
 *   done with a query delete (which bypasses the model's
 *   delete-cancelling hook on purpose).
 * - notifications: in-app notifications older than the cutoff.
 * - job_executions: global idempotency rows older than the
 *   cutoff. These carry no organization, so they are purged by
 *   the all-organizations run only.
 * - failed_documents: generated documents that failed, older
 *   than the cutoff. Ready packages are never purged: a ready
 *   package is the record of a submitted claim. The stored
 *   object is deleted with the row when one exists.
 * - security_events: rows older than the cutoff. Rows with no
 *   organization are purged by the all-organizations run only.
 *
 * Every purge of an organization writes one audit row
 * (retention.purged) with the counts, after the deletions, so
 * the purge itself is on the record. A dry run writes nothing.
 */
class RetentionService
{
    /**
     * The retention kinds, in the order they are purged.
     *
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return [
            'audit_logs',
            'notifications',
            'job_executions',
            'failed_documents',
            'security_events',
        ];
    }

    /**
     * The config key for one kind's default, in days.
     */
    public static function configKey(string $kind): string
    {
        return match ($kind) {
            'audit_logs' => 'audit_logs_days',
            'notifications' => 'notifications_days',
            'job_executions' => 'job_executions_days',
            'failed_documents' => 'failed_documents_days',
            'security_events' => 'security_events_days',
        };
    }

    /**
     * Effective retention for an organization: config defaults
     * merged over with the organization's own overrides.
     *
     * @return array<string, int>
     */
    public function effectiveSettings(?Organization $organization): array
    {
        $settings = [];

        foreach (self::kinds() as $kind) {
            $settings[$kind] = (int) config('retention.'.self::configKey($kind), 0);
        }

        $overrides = $organization?->settings['retention'] ?? [];

        foreach (self::kinds() as $kind) {
            if (isset($overrides[$kind]) && is_numeric($overrides[$kind])) {
                $settings[$kind] = max(0, (int) $overrides[$kind]);
            }
        }

        return $settings;
    }

    /**
     * Purge one organization. Global kinds (job executions and
     * unattributed security events) are only purged when no
     * organization is given, by purgeAll().
     *
     * @return array<string, int> deleted counts per kind.
     */
    public function purge(Organization $organization, bool $dryRun = false): array
    {
        $settings = $this->effectiveSettings($organization);
        $counts = [];

        $counts['audit_logs'] = $this->purgeAuditLogs($organization, $settings['audit_logs'], $dryRun);
        $counts['notifications'] = $this->purgeNotifications($organization, $settings['notifications'], $dryRun);
        $counts['failed_documents'] = $this->purgeFailedDocuments($organization, $settings['failed_documents'], $dryRun);
        $counts['security_events'] = $this->purgeSecurityEvents($organization, $settings['security_events'], $dryRun);

        if (! $dryRun) {
            // The purge is recorded after the deletions, so the
            // row itself is never caught by its own cutoff.
            app(AuditService::class)->record(
                $organization,
                'retention.purged',
                $organization,
                null,
                null,
                $counts,
            );
        }

        return $counts;
    }

    /**
     * Purge every organization, then the global kinds once.
     *
     * @return array{organizations: array<string, array<string, int>>, global: array<string, int>}
     */
    public function purgeAll(bool $dryRun = false): array
    {
        $result = ['organizations' => [], 'global' => []];

        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            $result['organizations'][$organization->getKey()] = $this->purge($organization, $dryRun);
        }

        $result['global']['job_executions'] = $this->purgeJobExecutions(
            (int) config('retention.job_executions_days', 0), $dryRun
        );
        $result['global']['security_events'] = $this->purgeUnattributedSecurityEvents(
            (int) config('retention.security_events_days', 0), $dryRun
        );

        return $result;
    }

    /**
     * Cutoff timestamp for a retention in days, or null when the
     * kind is disabled (0 days means keep forever).
     */
    protected function cutoff(int $days): ?\DateTimeImmutable
    {
        return $days > 0 ? now()->subDays($days)->toImmutable() : null;
    }

    protected function purgeAuditLogs(Organization $organization, int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        // Query delete on purpose: the AuditLog model cancels
        // model-level deletes, and retention purging is the one
        // sanctioned exception to the append-only rule.
        $query = AuditLog::forOrganization($organization->getKey())
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    protected function purgeNotifications(Organization $organization, int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $query = Notification::forOrganization($organization->getKey())
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    protected function purgeFailedDocuments(Organization $organization, int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $documents = GeneratedDocument::forOrganization($organization->getKey())
            ->where('status', GeneratedDocumentStatus::Failed)
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($dryRun) {
            return $documents->count();
        }

        $disk = Storage::disk(config('evidence.disk'));

        foreach ($documents as $document) {
            if ($document->storage_key !== null) {
                $disk->delete($document->storage_key);
            }

            $document->delete();
        }

        return $documents->count();
    }

    protected function purgeSecurityEvents(Organization $organization, int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $query = SecurityEvent::forOrganization($organization->getKey())
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    protected function purgeJobExecutions(int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $query = JobExecution::query()->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    protected function purgeUnattributedSecurityEvents(int $days, bool $dryRun): int
    {
        $cutoff = $this->cutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $query = SecurityEvent::query()
            ->whereNull('organization_id')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }
}
