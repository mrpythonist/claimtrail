<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\LoadTestService;
use Illuminate\Console\Command;

/**
 * Run a bounded local synthetic load pass (v2.0.3).
 *
 * Dispatches N requests through the HTTP kernel in-process
 * against the public health endpoint and reports timings.
 * Only the health target is available here: the shipments
 * target needs a caller's credentials, so it runs from the
 * API. Requests are capped by config. This is a local
 * synthetic pass, not a production load test.
 */
class LoadTestCommand extends Command
{
    protected $signature = 'load:test {--requests=50 : Number of requests to dispatch (1-200)} {--organization= : UUID of the organization the run is attributed to}';

    protected $description = 'Run a bounded local synthetic load pass against the health endpoint';

    public function handle(LoadTestService $loadTests): int
    {
        $requests = max(1, min(
            (int) config('enterprise.load_test.max_requests', 200),
            (int) $this->option('requests'),
        ));

        $organization = null;

        if ($this->option('organization') !== null) {
            $organization = Organization::query()
                ->whereKey((string) $this->option('organization'))
                ->first();

            if ($organization === null) {
                $this->error('Organization not found: '.$this->option('organization'));

                return self::FAILURE;
            }
        } else {
            $organization = Organization::query()->orderBy('name')->first();

            if ($organization === null) {
                $this->error('No organization exists yet. Create one first.');

                return self::FAILURE;
            }
        }

        $run = $loadTests->run($organization, 'health', $requests, 'CLI load test');

        $this->info(sprintf(
            'Load test: %d/%d succeeded, avg %.2f ms, p95 %.2f ms, max %.2f ms, %.2f req/s.',
            $run->success_count,
            $run->total_requests,
            (float) $run->avg_ms,
            (float) $run->p95_ms,
            (float) $run->max_ms,
            (float) $run->requests_per_second,
        ));

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
