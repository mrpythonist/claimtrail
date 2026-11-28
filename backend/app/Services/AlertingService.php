<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\BackupRun;
use App\Models\EnterpriseAlert;
use App\Models\MonitoringCheck;
use App\Models\Organization;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use Illuminate\Support\Facades\Storage;

/**
 * Monitoring and alerting (v2.0.3).
 *
 * Alert rules watch one local metric each. Every metric is
 * measured from this instance's own data only:
 * - queue_depth: pending jobs in the database queue.
 * - failed_jobs: failed jobs attributed to the organization.
 * - error_rate: 5xx errors in the local error log for the
 *   organization, as a percentage of (errors + hourly health
 *   probes) in the same window. The denominator is probes, not
 *   requests, because the app records no per-request counter;
 *   the doc states this plainly. Zero errors reads 0%.
 * - disk_usage_percent: the evidence disk's used share.
 * - backup_age_hours: hours since the latest completed backup
 *   (the organization's own, else the latest scheduled one).
 *
 * A breach opens one active alert per rule. A repeat breach
 * while one is active updates the observed value instead of
 * opening a second row, and notifies only when the alert is
 * first opened. Resolving an alert lets a later breach open a
 * fresh one. A rule whose metric cannot be measured (no backup
 * yet, unreadable disk) evaluates to "not breached" and records
 * a null observed value.
 */
class AlertingService
{
    public function __construct(
        protected QueueMonitorService $queues,
        protected ErrorLogService $errors,
        protected NotificationService $notifications,
    ) {}

    /**
     * Measure a metric for an organization, or null when it
     * cannot be measured right now.
     */
    public function measure(string $metric, Organization $organization, int $windowMinutes): ?float
    {
        return match ($metric) {
            AlertRule::METRIC_QUEUE_DEPTH => (float) (int) ($this->queues->stats()['totals']['pending'] ?? 0),
            AlertRule::METRIC_FAILED_JOBS => (float) count(
                $this->queues->failedJobsForOrganization($organization->getKey())
            ),
            AlertRule::METRIC_ERROR_RATE => $this->measureErrorRate($organization, $windowMinutes),
            AlertRule::METRIC_DISK_USAGE_PERCENT => $this->measureDiskUsage(),
            AlertRule::METRIC_BACKUP_AGE_HOURS => $this->measureBackupAge($organization),
            default => null,
        };
    }

    /**
     * Whether the observed value breaches the rule.
     */
    public function breached(AlertRule $rule, float $observed): bool
    {
        return $rule->operator === AlertRule::OPERATOR_GTE
            ? $observed >= (float) $rule->threshold
            : $observed > (float) $rule->threshold;
    }

    /**
     * Evaluate one rule now: measure, breach-check, and open or
     * update the alert. Returns the evaluation result with the
     * alert presenter when a breach is recorded.
     *
     * @return array{rule_id: string, metric: string, observed_value: float|null, breached: bool, alert: array<string, mixed>|null}
     */
    public function evaluateRule(AlertRule $rule, ?User $actor = null): array
    {
        $organization = $rule->organization;
        $observed = $this->measure($rule->metric, $organization, $rule->window_minutes);
        $isBreach = $observed !== null && $this->breached($rule, $observed);
        $alert = null;

        if ($isBreach) {
            $alert = $this->openOrUpdateAlert($rule, $observed, $actor);
        }

        return [
            'rule_id' => $rule->getKey(),
            'metric' => $rule->metric,
            'observed_value' => $observed,
            'breached' => $isBreach,
            'alert' => $alert !== null ? $this->presentAlert($alert) : null,
        ];
    }

    /**
     * Evaluate every enabled rule of one organization.
     *
     * @return array<int, array{rule_id: string, metric: string, observed_value: float|null, breached: bool, alert: array<string, mixed>|null}>
     */
    public function evaluateOrganization(Organization $organization): array
    {
        $results = [];

        foreach ($this->enabledRules($organization) as $rule) {
            $results[] = $this->evaluateRule($rule);
        }

        return $results;
    }

    /**
     * Evaluate every enabled rule of every organization (for
     * the `alerts:evaluate` command and the hourly schedule).
     *
     * @return array<string, array<int, array{rule_id: string, metric: string, observed_value: float|null, breached: bool, alert: array<string, mixed>|null}>>
     */
    public function evaluateAll(): array
    {
        $results = [];

        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            $results[$organization->getKey()] = $this->evaluateOrganization($organization);
        }

        return $results;
    }

    /**
     * The enabled rules of an organization.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AlertRule>
     */
    public function enabledRules(Organization $organization)
    {
        return AlertRule::forOrganization($organization->getKey())
            ->where('enabled', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * One rule as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function presentRule(AlertRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'name' => $rule->name,
            'metric' => $rule->metric,
            'metric_label' => config('enterprise.alert_metrics.'.$rule->metric.'.label', $rule->metric),
            'operator' => $rule->operator,
            'threshold' => (float) $rule->threshold,
            'window_minutes' => (int) $rule->window_minutes,
            'enabled' => (bool) $rule->enabled,
            'severity' => $rule->severity,
            'created_at' => $rule->created_at->toIso8601String(),
            'updated_at' => $rule->updated_at->toIso8601String(),
        ];
    }

    /**
     * One alert as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function presentAlert(EnterpriseAlert $alert): array
    {
        return [
            'id' => $alert->getKey(),
            'alert_rule_id' => $alert->alert_rule_id,
            'metric' => $alert->metric,
            'metric_label' => config('enterprise.alert_metrics.'.$alert->metric.'.label', $alert->metric),
            'observed_value' => (float) $alert->observed_value,
            'threshold' => (float) $alert->threshold,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'message' => $alert->message,
            'triggered_at' => $alert->triggered_at->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'created_at' => $alert->created_at->toIso8601String(),
        ];
    }

    /**
     * Open a new alert, or refresh the open one for the rule.
     * Notifies the enterprise managers only when the alert is
     * first opened.
     */
    protected function openOrUpdateAlert(AlertRule $rule, float $observed, ?User $actor): EnterpriseAlert
    {
        $organization = $rule->organization;
        $message = $this->breachMessage($rule, $observed);

        $existing = EnterpriseAlert::forOrganization($organization->getKey())
            ->where('alert_rule_id', $rule->getKey())
            ->whereIn('status', [EnterpriseAlert::STATUS_ACTIVE, EnterpriseAlert::STATUS_ACKNOWLEDGED])
            ->orderByDesc('triggered_at')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'observed_value' => $observed,
                'message' => $message,
            ]);

            return $existing->fresh();
        }

        $alert = EnterpriseAlert::create([
            'organization_id' => $organization->getKey(),
            'alert_rule_id' => $rule->getKey(),
            'metric' => $rule->metric,
            'observed_value' => $observed,
            'threshold' => $rule->threshold,
            'severity' => $rule->severity,
            'status' => EnterpriseAlert::STATUS_ACTIVE,
            'message' => $message,
            'triggered_at' => now(),
        ]);

        $recipients = $this->notifications->membersWithPermission(
            $organization, Permission::ManageEnterprise, $actor
        );

        $this->notifications->notify(
            $organization,
            $recipients,
            NotificationType::EnterpriseAlertTriggered,
            'Alert: '.$rule->name,
            $message,
            ['alert_rule_id' => $rule->getKey(), 'alert_id' => $alert->getKey()],
        );

        return $alert;
    }

    protected function breachMessage(AlertRule $rule, float $observed): string
    {
        $label = config('enterprise.alert_metrics.'.$rule->metric.'.label', $rule->metric);
        $unit = config('enterprise.alert_metrics.'.$rule->metric.'.unit', '');

        return $label.' is '.$observed.($unit !== '' ? ' '.$unit : '')
            .' (threshold '.$rule->threshold.($unit !== '' ? ' '.$unit : '').').';
    }

    /**
     * Probe-relative error rate: errors / (errors + health
     * probes in the window), as a percentage. Null when there
     * is nothing to measure against (no probes and no errors).
     */
    protected function measureErrorRate(Organization $organization, int $windowMinutes): ?float
    {
        $errors = $this->errors->errorCountForOrganization(
            $organization->getKey(), $windowMinutes
        )['errors'];

        $probes = MonitoringCheck::query()
            ->where('checked_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($errors === 0 && $probes === 0) {
            return null;
        }

        return round(100 * $errors / max(1, $errors + $probes), 4);
    }

    /**
     * Used share of the evidence disk, or null when the disk
     * size cannot be read (for example a fake disk in tests).
     */
    protected function measureDiskUsage(): ?float
    {
        try {
            $disk = Storage::disk(config('evidence.disk'));

            if (! method_exists($disk, 'path')) {
                return null;
            }

            $path = $disk->path('');

            if (! is_string($path) || $path === '' || ! is_dir($path)) {
                return null;
            }

            $total = disk_total_space($path);
            $free = disk_free_space($path);

            if ($total === false || $free === false || $total <= 0) {
                return null;
            }

            return round(100 * (1 - $free / $total), 4);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hours since the latest completed backup: the
     * organization's own run first, else the latest scheduled
     * run. Null when no completed backup exists at all.
     */
    protected function measureBackupAge(Organization $organization): ?float
    {
        $run = BackupRun::forOrganization($organization->getKey())
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->first()
            ?? BackupRun::withoutGlobalScope('organization')
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->first();

        if ($run === null) {
            return null;
        }

        return round(now()->diffInSeconds($run->created_at) / 3600, 4);
    }
}
