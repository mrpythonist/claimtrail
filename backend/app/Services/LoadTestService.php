<?php

namespace App\Services;

use App\Models\LoadTestRun;
use App\Models\Organization;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * Bounded local synthetic load testing (v2.0.3).
 *
 * A run dispatches N requests through the HTTP kernel
 * in-process, measures each response, and stores the timing
 * summary. No external service is ever touched: the health
 * target hits the public health endpoint, and the shipments
 * target hits the authenticated shipment list with the
 * caller's own credentials. Requests are capped by
 * config/enterprise.php load_test.max_requests, and a run
 * stops early on its first exception only when the kernel
 * itself cannot be reached — per-request failures are counted,
 * not fatal. This is a local synthetic pass, not a production
 * load test: it measures this instance on this machine, under
 * no concurrency (requests run one after another), and says
 * nothing about production traffic.
 */
class LoadTestService
{
    /**
     * Run a bounded load pass and store the result.
     *
     * @param  array<string, string>  $headers  headers to copy onto each synthetic request
     */
    public function run(
        Organization $organization,
        string $target,
        int $requests,
        ?string $name = null,
        array $headers = [],
    ): LoadTestRun {
        $requests = max(1, min(
            (int) config('enterprise.load_test.max_requests', 200),
            $requests,
        ));

        $run = LoadTestRun::create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'target' => $target,
            'requests' => $requests,
            'status' => LoadTestRun::STATUS_COMPLETED,
            'started_at' => now(),
        ]);

        $uri = $target === LoadTestRun::TARGET_SHIPMENTS
            ? '/api/v1/shipments'
            : '/api/v1/health';

        $kernel = app(Kernel::class);
        $latencies = [];
        $success = 0;
        $errors = 0;
        $firstError = null;
        $started = microtime(true);

        for ($i = 0; $i < $requests; $i++) {
            $request = Request::create($uri, 'GET');
            $request->headers->set('Accept', 'application/json');

            foreach ($headers as $header => $value) {
                $request->headers->set($header, $value);
            }

            $attemptStarted = microtime(true);

            try {
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 400) {
                    $success++;
                } else {
                    $errors++;
                    $firstError ??= 'HTTP '.$status.' on request '.($i + 1).'.';
                }
            } catch (Throwable $e) {
                $errors++;
                $firstError ??= $e->getMessage();
            }

            $latencies[] = round((microtime(true) - $attemptStarted) * 1000, 2);
        }

        $elapsed = max(0.001, microtime(true) - $started);

        sort($latencies);
        $count = count($latencies);
        $p95Index = max(0, (int) ceil(0.95 * $count) - 1);

        $run->update([
            'status' => $errors === $count && $count > 0
                ? LoadTestRun::STATUS_FAILED
                : LoadTestRun::STATUS_COMPLETED,
            'total_requests' => $count,
            'success_count' => $success,
            'error_count' => $errors,
            'avg_ms' => $count > 0 ? round(array_sum($latencies) / $count, 2) : 0,
            'p95_ms' => $count > 0 ? $latencies[$p95Index] : 0,
            'max_ms' => $count > 0 ? max($latencies) : 0,
            'requests_per_second' => round($count / $elapsed, 2),
            'detail' => [
                'uri' => $uri,
                'concurrency' => 1,
                'first_error' => $firstError,
                'note' => 'Local synthetic load only: sequential in-process requests against this instance, not production traffic.',
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    /**
     * One load test run as the API returns it.
     *
     * @return array<string, mixed>
     */
    public function present(LoadTestRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'name' => $run->name,
            'target' => $run->target,
            'requests' => (int) $run->requests,
            'status' => $run->status,
            'total_requests' => (int) $run->total_requests,
            'success_count' => (int) $run->success_count,
            'error_count' => (int) $run->error_count,
            'avg_ms' => (float) $run->avg_ms,
            'p95_ms' => (float) $run->p95_ms,
            'max_ms' => (float) $run->max_ms,
            'requests_per_second' => (float) $run->requests_per_second,
            'detail' => $run->detail,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at->toIso8601String(),
        ];
    }
}
