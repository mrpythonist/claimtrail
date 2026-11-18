<?php

namespace App\Jobs\Concerns;

use DateTimeInterface;

/**
 * Standard retry behaviour for ClaimTrail jobs.
 *
 * Three attempts with growing backoff, a two-minute timeout per
 * attempt, and a 24-hour retry window: a job that keeps failing
 * is moved to the failed table instead of retrying forever. A job
 * that needs different backoff defines its own backoff() method;
 * the class method wins over the trait's.
 */
trait HasStandardRetries
{
    /**
     * Attempts before the job is moved to the failed table.
     */
    public int $tries = 3;

    /**
     * Seconds a single attempt may run before it is killed.
     */
    public int $timeout = 120;

    /**
     * Backoff between attempts, in seconds: 30s, 2m, 10m.
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * Stop retrying after 24 hours, even if attempts remain.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }
}
