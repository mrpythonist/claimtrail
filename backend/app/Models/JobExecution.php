<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Idempotency ledger for queued jobs.
 *
 * A job claims its key before doing work and marks it completed
 * afterwards. The unique key makes the claim atomic: two workers
 * racing the same job cannot both claim it. A failed run releases
 * its key so a retry can claim it again, and a "running" row older
 * than the stale threshold is treated as abandoned (the worker
 * died without releasing) and may be re-claimed.
 *
 * This model has no organization scope: job keys are global, and
 * the key itself carries the entity it guards.
 */
class JobExecution extends Model
{
    use HasUuids;

    /**
     * Minutes after which a "running" row is treated as abandoned.
     */
    public const STALE_MINUTES = 60;

    protected $fillable = [
        'key',
        'job',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Claim a key for a job run.
     *
     * Returns true when the caller owns the run and should do the
     * work. Returns false when the key already completed (the work
     * was done; skip it) or another worker is running it now.
     */
    public static function claim(string $key, string $job): bool
    {
        try {
            self::create([
                'key' => $key,
                'job' => $job,
                'status' => 'running',
            ]);

            return true;
        } catch (QueryException $e) {
            // Not a unique violation: something else went wrong.
            // Let it surface rather than silently skipping work.
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }
        }

        $existing = self::where('key', $key)->first();

        if ($existing === null) {
            // The row vanished between the failed insert and the
            // lookup (a concurrent release). Try once more.
            return self::claim($key, $job);
        }

        if ($existing->status === 'completed') {
            return false;
        }

        if ($existing->updated_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
            // Abandoned run: the worker died without releasing.
            // Re-claim it by replacing the stale row.
            $existing->delete();

            return self::claim($key, $job);
        }

        return false;
    }

    /**
     * Mark a claimed key completed.
     */
    public static function complete(string $key): void
    {
        self::where('key', $key)->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Release a claimed key so a retry can claim it again.
     */
    public static function release(string $key): void
    {
        self::where('key', $key)->delete();
    }

    /**
     * Whether the query exception is a unique-constraint violation.
     * Checked by SQLSTATE so it works on SQLite, MySQL, and Postgres.
     */
    protected static function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
