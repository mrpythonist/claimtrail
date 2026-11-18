<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the application's health checks.
 *
 * Each check returns ok, degraded, or down plus the milliseconds
 * it took. The database and the evidence disk are critical: either
 * down makes the whole status down. The cache is not critical
 * (the app runs without it), so a cache failure only degrades.
 * The queue check only runs for the database driver; other drivers
 * report "skipped" because their depth is not queryable here.
 */
class HealthCheckService
{
    /**
     * Run every check and roll the results up into one status.
     *
     * @return array{status: string, checks: array<string, array{status: string, latency_ms: float, detail?: string}>, checked_at: string}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $status = 'ok';

        if ($checks['database']['status'] === 'down' || $checks['storage']['status'] === 'down') {
            $status = 'down';
        } elseif ($checks['cache']['status'] !== 'ok' || $checks['queue']['status'] === 'down') {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'checks' => $checks,
            'checked_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array{status: string, latency_ms: float, detail?: string}
     */
    protected function checkDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::connection()->getPdo()->query('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => $this->latency($started),
                'detail' => 'driver: '.DB::connection()->getDriverName(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => $this->latency($started),
                'detail' => 'database unreachable',
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms: float, detail?: string}
     */
    protected function checkCache(): array
    {
        $started = microtime(true);
        $key = 'health-check:'.Str::uuid();

        try {
            Cache::put($key, 'ok', 10);
            $roundtrip = Cache::get($key) === 'ok';
            Cache::forget($key);

            return [
                'status' => $roundtrip ? 'ok' : 'degraded',
                'latency_ms' => $this->latency($started),
                'detail' => 'store: '.config('cache.default'),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'degraded',
                'latency_ms' => $this->latency($started),
                'detail' => 'cache unreachable',
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms: float, detail?: string}
     */
    protected function checkQueue(): array
    {
        $started = microtime(true);
        $connection = config('queue.default');

        if ($connection !== 'database') {
            return [
                'status' => 'skipped',
                'latency_ms' => $this->latency($started),
                'detail' => 'driver '.$connection.' depth is not queryable here',
            ];
        }

        try {
            $pending = DB::table(config('queue.connections.database.table', 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<=', time())
                ->count();

            return [
                'status' => 'ok',
                'latency_ms' => $this->latency($started),
                'detail' => 'pending jobs: '.$pending,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => $this->latency($started),
                'detail' => 'jobs table unreachable',
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms: float, detail?: string}
     */
    protected function checkStorage(): array
    {
        $started = microtime(true);
        $disk = config('evidence.disk');
        $probe = 'health-checks/'.Str::uuid().'.txt';

        try {
            $storage = Storage::disk($disk);
            $storage->put($probe, 'ok');
            $readable = $storage->get($probe) === 'ok';
            $storage->delete($probe);

            return [
                'status' => $readable ? 'ok' : 'down',
                'latency_ms' => $this->latency($started),
                'detail' => 'disk: '.$disk,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => $this->latency($started),
                'detail' => 'disk '.$disk.' not writable',
            ];
        }
    }

    protected function latency(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
