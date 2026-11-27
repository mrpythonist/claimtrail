<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasStandardRetries;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use App\WorkflowRunStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one workflow run (v2.0.0).
 *
 * Three attempts with growing backoff (30s, 2m, 10m) inside a
 * 24-hour window, the standard retry behaviour. A run that is
 * already finished, failed, rejected, or waiting for approval
 * is skipped: the engine guards on run status, so a redelivered
 * job never re-runs completed steps. When the retries are
 * exhausted, failed() marks the run failed with the reason so
 * it can be retried by hand; the run is never silently
 * dropped.
 */
class RunWorkflow implements ShouldQueue
{
    use HasStandardRetries, Queueable;

    public function __construct(
        protected string $workflowRunId,
    ) {}

    /**
     * Execute the run from its current step.
     */
    public function handle(WorkflowEngine $engine): void
    {
        $run = WorkflowRun::query()
            ->withoutGlobalScope('organization')
            ->find($this->workflowRunId);

        if ($run === null) {
            Log::warning('Workflow run job ran for a missing run.', [
                'workflow_run_id' => $this->workflowRunId,
            ]);

            return;
        }

        $engine->execute($run);
    }

    /**
     * The retries are exhausted: mark the run failed with the
     * last error, so it shows up in the run history and can be
     * retried by hand. A run that already finished another way
     * (completed, rejected, or failed permanently inside the
     * engine) is left alone.
     */
    public function failed(Throwable $exception): void
    {
        $run = WorkflowRun::query()
            ->withoutGlobalScope('organization')
            ->find($this->workflowRunId);

        if ($run === null || $run->status !== WorkflowRunStatus::Running) {
            return;
        }

        app(WorkflowEngine::class)->markFailed($run, 'Step failed after all retries: '.$exception->getMessage());
    }
}
