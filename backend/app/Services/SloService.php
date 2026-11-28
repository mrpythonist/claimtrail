<?php

namespace App\Services;

use App\Models\MonitoringCheck;
use App\Models\Organization;
use App\Models\Slo;

/**
 * Service-level objectives (v2.0.3).
 *
 * SLOs are configured objectives, not guarantees. The measured
 * value for each comes only from this instance's own recorded
 * data, and carries its sample size:
 * - availability: the share of monitoring checks in the window
 *   whose status is ok. Sample size is the check count.
 * - error_rate: 5xx errors in the local error log for the
 *   organization, as a percentage of (errors + hourly health
 *   probes) in the window — the same probe-relative definition
 *   as the alert metric, because the app records no per-request
 *   counter. Sample size is errors + probes.
 * - latency_p95: the 95th percentile of the per-check latency
 *   values stored on the monitoring check rows in the window.
 *   Sample size is the number of latency samples.
 *
 * A measurement with no underlying data reports null with the
 * status insufficient_data. Status bands (documented in
 * docs/ENTERPRISE-READINESS.md): availability is at risk within
 * 1 point below target; error rate within 0.5 points above;
 * latency within 10% above. A disabled SLO still reports its
 * measurement; the enabled flag is what says it is not in
 * force.
 */
class SloService
{
    public function __construct(
        protected ErrorLogService $errors,
    ) {}

    /**
     * The organization's SLOs, seeded from config on first
     * read, each with its measured value and status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusFor(Organization $organization): array
    {
        $this->seedDefaults($organization);

        return Slo::forOrganization($organization->getKey())
            ->orderBy('key')
            ->get()
            ->map(fn (Slo $slo) => $this->present($slo, $this->measure($slo)))
            ->all();
    }

    /**
     * Find one SLO of an organization by key.
     */
    public function findFor(Organization $organization, string $key): ?Slo
    {
        $this->seedDefaults($organization);

        return Slo::forOrganization($organization->getKey())
            ->where('key', $key)
            ->first();
    }

    /**
     * Measure one SLO from local data.
     *
     * @return array{measured_value: float|null, sample_size: int, status: string}
     */
    public function measure(Slo $slo): array
    {
        return match ($slo->key) {
            Slo::KEY_AVAILABILITY => $this->measureAvailability($slo),
            Slo::KEY_ERROR_RATE => $this->measureErrorRate($slo),
            Slo::KEY_LATENCY_P95 => $this->measureLatency($slo),
            default => ['measured_value' => null, 'sample_size' => 0, 'status' => 'insufficient_data'],
        };
    }

    /**
     * One SLO as the API returns it.
     *
     * @param  array{measured_value: float|null, sample_size: int, status: string}  $measured
     * @return array<string, mixed>
     */
    public function present(Slo $slo, array $measured): array
    {
        return [
            'key' => $slo->key,
            'name' => $slo->name,
            'kind' => $slo->kind,
            'target_value' => (float) $slo->target_value,
            'target_unit' => $slo->target_unit,
            'window_days' => (int) $slo->window_days,
            'enabled' => (bool) $slo->enabled,
            'measured_value' => $measured['measured_value'],
            'sample_size' => $measured['sample_size'],
            'status' => $measured['status'],
            'measured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Create the config-default SLO rows for an organization
     * that has none yet.
     */
    protected function seedDefaults(Organization $organization): void
    {
        foreach ((array) config('enterprise.slos', []) as $key => $definition) {
            Slo::firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'key' => $key,
                ],
                [
                    'name' => $definition['name'] ?? $key,
                    'kind' => $definition['kind'] ?? $key,
                    'target_value' => $definition['target_value'] ?? 0,
                    'target_unit' => $definition['target_unit'] ?? '%',
                    'window_days' => $definition['window_days'] ?? 30,
                    'enabled' => true,
                ],
            );
        }
    }

    /**
     * @return array{measured_value: float|null, sample_size: int, status: string}
     */
    protected function measureAvailability(Slo $slo): array
    {
        $checks = $this->checksInWindow($slo);
        $total = $checks->count();

        if ($total === 0) {
            return ['measured_value' => null, 'sample_size' => 0, 'status' => 'insufficient_data'];
        }

        $ok = $checks->where('status', 'ok')->count();
        $value = round(100 * $ok / $total, 4);

        return [
            'measured_value' => $value,
            'sample_size' => $total,
            'status' => $this->statusForHigherIsBetter($value, (float) $slo->target_value, 1.0),
        ];
    }

    /**
     * @return array{measured_value: float|null, sample_size: int, status: string}
     */
    protected function measureErrorRate(Slo $slo): array
    {
        $windowMinutes = $slo->window_days * 1440;
        $errors = $this->errors->errorCountForOrganization(
            $slo->organization_id, $windowMinutes
        )['errors'];

        $probes = $this->checksInWindow($slo)->count();

        if ($errors === 0 && $probes === 0) {
            return ['measured_value' => null, 'sample_size' => 0, 'status' => 'insufficient_data'];
        }

        $value = round(100 * $errors / max(1, $errors + $probes), 4);

        return [
            'measured_value' => $value,
            'sample_size' => $errors + $probes,
            'status' => $this->statusForLowerIsBetter($value, (float) $slo->target_value, 0.5),
        ];
    }

    /**
     * @return array{measured_value: float|null, sample_size: int, status: string}
     */
    protected function measureLatency(Slo $slo): array
    {
        $latencies = [];

        foreach ($this->checksInWindow($slo) as $check) {
            foreach ((array) ($check->checks ?? []) as $result) {
                if (isset($result['latency_ms']) && is_numeric($result['latency_ms'])) {
                    $latencies[] = (float) $result['latency_ms'];
                }
            }
        }

        if ($latencies === []) {
            return ['measured_value' => null, 'sample_size' => 0, 'status' => 'insufficient_data'];
        }

        sort($latencies);
        $index = max(0, (int) ceil(0.95 * count($latencies)) - 1);
        $value = round($latencies[$index], 2);

        return [
            'measured_value' => $value,
            'sample_size' => count($latencies),
            'status' => $this->statusForLowerIsBetter(
                $value, (float) $slo->target_value, (float) $slo->target_value * 0.10
            ),
        ];
    }

    /**
     * Monitoring checks in the SLO's window. Monitoring checks
     * are deployment-wide (not per organization), so every
     * organization reads the same probe history; the doc states
     * this.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitoringCheck>
     */
    protected function checksInWindow(Slo $slo)
    {
        return MonitoringCheck::query()
            ->where('checked_at', '>=', now()->subDays($slo->window_days))
            ->orderBy('checked_at')
            ->get();
    }

    /**
     * Status for a higher-is-better value (availability):
     * meeting at or above target, at risk within $margin below,
     * breaching beyond that.
     */
    protected function statusForHigherIsBetter(float $value, float $target, float $margin): string
    {
        if ($value >= $target) {
            return 'meeting';
        }

        return $value >= $target - $margin ? 'at_risk' : 'breaching';
    }

    /**
     * Status for a lower-is-better value (error rate, latency):
     * meeting at or below target, at risk within $margin above,
     * breaching beyond that.
     */
    protected function statusForLowerIsBetter(float $value, float $target, float $margin): string
    {
        if ($value <= $target) {
            return 'meeting';
        }

        return $value <= $target + $margin ? 'at_risk' : 'breaching';
    }
}
