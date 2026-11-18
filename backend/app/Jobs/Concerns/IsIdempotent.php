<?php

namespace App\Jobs\Concerns;

use App\Models\JobExecution;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a queued job idempotent: a redelivery of a job that
 * already completed is skipped instead of running twice.
 *
 * The job provides a stable key for the unit of work
 * (idempotencyKey) and wraps its real work in runIdempotently().
 * A failed run releases its key, so a retry can claim it again;
 * a completed run keeps its key, so a duplicate delivery skips.
 */
trait IsIdempotent
{
    /**
     * Stable key for this unit of work. Two deliveries of the
     * same work must produce the same key; different work must
     * produce different keys.
     */
    abstract public function idempotencyKey(): string;

    /**
     * Run the work once per key. Returns true when the work ran,
     * false when an earlier run already completed it.
     *
     * @param  callable(): void  $work
     */
    protected function runIdempotently(callable $work): bool
    {
        $key = $this->idempotencyKey();

        if (! JobExecution::claim($key, static::class)) {
            Log::info('Skipping duplicate job run: key already executed or in progress.', [
                'job' => static::class,
                'key' => $key,
            ]);

            return false;
        }

        try {
            $work();
        } catch (Throwable $e) {
            JobExecution::release($key);

            throw $e;
        }

        JobExecution::complete($key);

        return true;
    }
}
