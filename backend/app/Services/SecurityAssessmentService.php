<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SecurityAssessment;
use App\Models\User;
use App\Permission;

/**
 * Enterprise security self-assessment (v2.0.3).
 *
 * This is a SELF-ASSESSMENT, not a penetration test and not an
 * external audit. It runs the existing production-readiness
 * checks (SecurityCheckService) plus configuration checks
 * against this instance's own data, and records the standing
 * unverified items as open findings. Because the open findings
 * can only be closed by work outside this instance (a live
 * Postgres run, a pen test, and so on), the overall result is
 * never "pass" while any of them stand — that is deliberate,
 * so the report can never be read as a clean bill of health.
 *
 * Findings have one of four statuses: pass, warn, fail, and
 * open. Open findings are the standing unverified items: they
 * are neither passing nor failing checks, they are work not
 * yet done. open_findings counts findings with status fail or
 * open; total_checks counts the pass/warn/fail checks only.
 */
class SecurityAssessmentService
{
    /**
     * Standing unverified items: work that no check on this
     * instance can close. Each becomes an open finding in
     * every assessment until the underlying verification is
     * done elsewhere.
     *
     * @return array<int, array{id: string, label: string, detail: string}>
     */
    public static function openItems(): array
    {
        return [
            [
                'id' => 'live-postgres',
                'label' => 'Live PostgreSQL verification',
                'detail' => 'Migrations have never run against real PostgreSQL; all tests used SQLite.',
            ],
            [
                'id' => 'live-redis',
                'label' => 'Live Redis verification',
                'detail' => 'The queue worker and cache have never run against real Redis; the database driver is the default.',
            ],
            [
                'id' => 'docker',
                'label' => 'Docker verification',
                'detail' => 'The compose file and Dockerfiles were never built or run; no Docker on the build VM.',
            ],
            [
                'id' => 'browser-e2e',
                'label' => 'Browser end-to-end tests',
                'detail' => 'Workflows pass over HTTP, but no browser run and no Playwright setup exist.',
            ],
            [
                'id' => 'real-mailer',
                'label' => 'Real mailer verification',
                'detail' => 'Email is queued and verified with a fake mailer only; the configured mailer is log.',
            ],
            [
                'id' => 'penetration-test',
                'label' => 'Penetration test',
                'detail' => 'No penetration test has been run against this application.',
            ],
            [
                'id' => 'dependency-audit',
                'label' => 'Dependency audit',
                'detail' => 'No dependency vulnerability audit has been run against the current lockfiles.',
            ],
        ];
    }

    public function __construct(
        protected SecurityCheckService $checks,
        protected RetentionService $retention,
        protected AlertingService $alerting,
        protected DisasterRecoveryService $disasterRecovery,
    ) {}

    /**
     * Generate and store an assessment for an organization.
     */
    public function generate(Organization $organization, ?User $actor = null): SecurityAssessment
    {
        $findings = $this->checks($organization);

        foreach (self::openItems() as $item) {
            $findings[] = [
                'id' => $item['id'],
                'label' => $item['label'],
                'status' => 'open',
                'detail' => $item['detail'],
                'category' => 'unverified',
            ];
        }

        $passed = count(array_filter($findings, fn ($finding) => $finding['status'] === 'pass'));
        $warnings = count(array_filter($findings, fn ($finding) => $finding['status'] === 'warn'));
        $failures = count(array_filter($findings, fn ($finding) => $finding['status'] === 'fail'));
        $open = count(array_filter($findings, fn ($finding) => in_array($finding['status'], ['open', 'fail'], true)));

        $overall = $failures > 0
            ? SecurityAssessment::OVERALL_FAIL
            : ($warnings > 0 || $open > 0
                ? SecurityAssessment::OVERALL_WARN
                : SecurityAssessment::OVERALL_PASS);

        return SecurityAssessment::create([
            'organization_id' => $organization->getKey(),
            'overall' => $overall,
            'open_findings' => $open,
            'total_checks' => $passed + $warnings + $failures,
            'passed' => $passed,
            'warnings' => $warnings,
            'failures' => $failures,
            'findings' => $findings,
            'generated_by' => $actor?->getKey(),
            'generated_at' => now(),
        ]);
    }

    /**
     * The latest assessment for an organization, if any.
     */
    public function latest(Organization $organization): ?SecurityAssessment
    {
        return SecurityAssessment::forOrganization($organization->getKey())
            ->orderByDesc('generated_at')
            ->first();
    }

    /**
     * One assessment as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function present(SecurityAssessment $assessment): array
    {
        return [
            'id' => $assessment->getKey(),
            'overall' => $assessment->overall,
            'open_findings' => (int) $assessment->open_findings,
            'total_checks' => (int) $assessment->total_checks,
            'passed' => (int) $assessment->passed,
            'warnings' => (int) $assessment->warnings,
            'failures' => (int) $assessment->failures,
            'findings' => $assessment->findings,
            'generated_by' => $assessment->generated_by !== null ? [
                'id' => $assessment->generated_by,
                'name' => User::query()->whereKey($assessment->generated_by)->value('name'),
            ] : null,
            'generated_at' => $assessment->generated_at->toIso8601String(),
            'created_at' => $assessment->created_at->toIso8601String(),
        ];
    }

    /**
     * The pass/warn/fail checks, each as one finding.
     *
     * @return array<int, array{id: string, label: string, status: string, detail: string, category: string}>
     */
    protected function checks(Organization $organization): array
    {
        $findings = [];

        foreach ($this->checks->run()['checks'] as $check) {
            $findings[] = [
                'id' => 'config-'.$check['id'],
                'label' => $check['label'],
                'status' => $check['status'],
                'detail' => $check['detail'],
                'category' => 'configuration',
            ];
        }

        $findings[] = $this->permissionCoverageFinding();
        $findings[] = $this->auditCompletenessFinding($organization);
        $findings[] = $this->retentionFinding($organization);
        $findings[] = $this->backupFreshnessFinding($organization);
        $findings[] = $this->alertRulesFinding($organization);
        $findings[] = $this->slosFinding($organization);

        return $findings;
    }

    /**
     * Every permission is mapped to at least one role.
     */
    protected function permissionCoverageFinding(): array
    {
        $map = (array) config('permissions', []);
        $assigned = [];

        foreach ($map as $permissions) {
            foreach ((array) $permissions as $permission) {
                $assigned[$permission] = true;
            }
        }

        $missing = array_values(array_diff(Permission::values(), array_keys($assigned)));

        return [
            'id' => 'permission-coverage',
            'label' => 'Permission coverage',
            'status' => $missing === [] ? 'pass' : 'fail',
            'detail' => $missing === []
                ? 'Every permission is assigned to at least one role.'
                : 'Permissions with no role: '.implode(', ', $missing).'.',
            'category' => 'access_control',
        ];
    }

    /**
     * The audit trail has recent rows for this organization.
     */
    protected function auditCompletenessFinding(Organization $organization): array
    {
        $recent = $organization->auditLogs()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return [
            'id' => 'audit-completeness',
            'label' => 'Audit trail completeness',
            'status' => $recent > 0 ? 'pass' : 'warn',
            'detail' => $recent > 0
                ? $recent.' audit rows recorded for this organization in the last 30 days.'
                : 'No audit rows for this organization in the last 30 days. The trail is append-only; a quiet trail usually means a quiet organization, not a broken one.',
            'category' => 'audit',
        ];
    }

    /**
     * Audit log retention is configured (not kept forever by
     * accident, not purged immediately).
     */
    protected function retentionFinding(Organization $organization): array
    {
        $settings = $this->retention->effectiveSettings($organization);
        $days = (int) ($settings['audit_logs'] ?? 0);

        return [
            'id' => 'retention-configured',
            'label' => 'Retention configured',
            'status' => $days > 0 ? 'pass' : 'warn',
            'detail' => $days > 0
                ? 'Audit logs are kept '.$days.' days before the retention purge may delete them.'
                : 'Audit logs are kept forever (retention 0 days). That is a choice, not a default: set a retention if the data should ever expire.',
            'category' => 'data_retention',
        ];
    }

    /**
     * A completed backup exists and is recent.
     */
    protected function backupFreshnessFinding(Organization $organization): array
    {
        $backup = $this->disasterRecovery->latestBackup($organization);

        if ($backup === null) {
            return [
                'id' => 'backup-freshness',
                'label' => 'Backup freshness',
                'status' => 'warn',
                'detail' => 'No completed backup exists yet. Create one from the security page, or wait for the daily scheduled backup at 02:00.',
                'category' => 'disaster_recovery',
            ];
        }

        $ageDays = now()->diffInDays($backup->created_at);

        return [
            'id' => 'backup-freshness',
            'label' => 'Backup freshness',
            'status' => $ageDays <= 7 ? 'pass' : 'warn',
            'detail' => 'Latest completed backup is '.$ageDays.' day(s) old ('.$backup->created_at->toDateTimeString().').',
            'category' => 'disaster_recovery',
        ];
    }

    /**
     * At least one alert rule is enabled.
     */
    protected function alertRulesFinding(Organization $organization): array
    {
        $enabled = $this->alerting->enabledRules($organization)->count();

        return [
            'id' => 'alert-rules-configured',
            'label' => 'Alert rules configured',
            'status' => $enabled > 0 ? 'pass' : 'warn',
            'detail' => $enabled > 0
                ? $enabled.' alert rule(s) enabled.'
                : 'No alert rules are enabled. Monitoring runs, but nothing will raise an alert when a metric crosses a threshold.',
            'category' => 'monitoring',
        ];
    }

    /**
     * All three SLOs are enabled.
     */
    protected function slosFinding(Organization $organization): array
    {
        $slos = \App\Models\Slo::forOrganization($organization->getKey())->get();
        $enabled = $slos->where('enabled', true)->count();

        return [
            'id' => 'slos-configured',
            'label' => 'SLOs configured',
            'status' => $enabled >= 3 ? 'pass' : 'warn',
            'detail' => $enabled >= 3
                ? 'All three SLOs (availability, error rate, latency) are enabled.'
                : $enabled.' of 3 SLOs enabled. Disabled SLOs still report measurements, but are not in force.',
            'category' => 'monitoring',
        ];
    }
}
