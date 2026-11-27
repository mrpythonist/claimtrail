<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflow;
use App\Models\Notification;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use App\Services\WorkflowEngine;
use App\Services\WorkflowStepRunner;
use App\WorkflowRunStatus;
use App\WorkflowStepStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The workflow run job (v2.0.0).
 *
 * The job uses the standard retry profile: three attempts with
 * growing backoff inside a 24-hour window. A transient step
 * failure is rethrown so the queue retries it, and the next
 * attempt resumes from the failed step; a permanent failure
 * (a step the claim cannot take) fails the run without
 * rethrowing, so it is never retried by the queue. When the
 * retries are exhausted, the failed hook marks the run failed
 * with the reason instead of silently dropping it.
 */
class RunWorkflowJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_workflow_job_uses_the_standard_retry_profile(): void
    {
        $job = new RunWorkflow('run-1');

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertTrue($job->retryUntil()->isAfter(now()->addHours(23)));
        $this->assertTrue($job->retryUntil()->isBefore(now()->addHours(25)));
    }

    public function test_transient_step_failure_is_rethrown_and_resumes_from_the_failed_step(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(\App\OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $workflow = $this->createWorkflow($organization, $user);

        $run = $this->createRun($organization, $workflow, $claim, $user);

        $runner = Mockery::mock(WorkflowStepRunner::class);
        $runner->shouldReceive('run')->once()->withAnyArgs()->andThrow(new \RuntimeException('transient database blip'));
        $runner->shouldReceive('run')->once()->withAnyArgs()->andReturn(['note_id' => 'note-1']);
        $this->app->bind(WorkflowStepRunner::class, fn () => $runner);

        $job = new RunWorkflow((string) $run->getKey());

        try {
            $job->handle(app(WorkflowEngine::class));
            $this->fail('The transient failure should have been rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('transient database blip', $exception->getMessage());
        }

        $step = $run->steps()->first();
        $this->assertSame(WorkflowStepStatus::Failed, $step->status);
        $this->assertSame(1, $step->attempts);
        $this->assertSame(WorkflowRunStatus::Running, $run->fresh()->status);

        $job->handle(app(WorkflowEngine::class));

        $this->assertSame(WorkflowStepStatus::Completed, $step->fresh()->status);
        $this->assertSame(2, $step->fresh()->attempts);
        $this->assertSame(WorkflowRunStatus::Completed, $run->fresh()->status);
    }

    public function test_permanent_step_failure_fails_the_run_without_rethrowing(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(\App\OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        // A transition straight to paid is invalid: a claim can
        // only be marked paid by recording a payment. The engine
        // treats that as a permanent failure.
        $workflow = $this->createWorkflow($organization, $user, [
            ['type' => 'transition', 'to_status' => 'paid'],
        ]);

        $run = $this->createRun($organization, $workflow, $claim, $user);

        (new RunWorkflow((string) $run->getKey()))->handle(app(WorkflowEngine::class));

        $this->assertSame(WorkflowRunStatus::Failed, $run->fresh()->status);
        $this->assertStringContainsString('paid', (string) $run->fresh()->failure_reason);
        $this->assertSame(WorkflowStepStatus::Failed, $run->steps()->first()->status);
        $this->assertSame('potential', $claim->fresh()->status->value);
    }

    public function test_failed_hook_marks_the_run_failed_after_retries_are_exhausted(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(\App\OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $workflow = $this->createWorkflow($organization, $user);

        $run = $this->createRun($organization, $workflow, $claim, $user);

        (new RunWorkflow((string) $run->getKey()))->failed(new \RuntimeException('queue down'));

        $this->assertSame(WorkflowRunStatus::Failed, $run->fresh()->status);
        $this->assertStringContainsString('after all retries', (string) $run->fresh()->failure_reason);
        $this->assertStringContainsString('queue down', (string) $run->fresh()->failure_reason);

        $this->assertTrue(Notification::where('organization_id', $organization->getKey())
            ->where('type', 'workflow.run_failed')
            ->exists());
    }

    public function test_failed_hook_leaves_a_completed_run_alone(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(\App\OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $workflow = $this->createWorkflow($organization, $user);

        $run = $this->createRun($organization, $workflow, $claim, $user);
        $run->update(['status' => WorkflowRunStatus::Completed, 'finished_at' => now()]);

        (new RunWorkflow((string) $run->getKey()))->failed(new \RuntimeException('too late'));

        $this->assertSame(WorkflowRunStatus::Completed, $run->fresh()->status);
    }

    protected function createWorkflow($organization, $user, array $steps = [['type' => 'note', 'message' => 'Checked by workflow.']])
    {
        return Workflow::create([
            'organization_id' => $organization->getKey(),
            'name' => 'Test Workflow '.uniqid(),
            'trigger' => 'claim.created',
            'is_active' => true,
            'conditions' => [],
            'steps' => $steps,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
    }

    protected function createRun($organization, $workflow, $claim, $user): WorkflowRun
    {
        $run = WorkflowRun::create([
            'organization_id' => $organization->getKey(),
            'workflow_id' => $workflow->getKey(),
            'claim_id' => $claim->getKey(),
            'trigger' => 'claim.created',
            'status' => WorkflowRunStatus::Running,
            'actor_user_id' => $user->getKey(),
            'current_step_index' => 0,
            'started_at' => now(),
        ]);

        foreach ($workflow->steps as $index => $step) {
            WorkflowRunStep::create([
                'organization_id' => $organization->getKey(),
                'workflow_run_id' => $run->getKey(),
                'step_index' => $index,
                'step_type' => $step['type'],
                'status' => WorkflowStepStatus::Pending,
            ]);
        }

        return $run;
    }
}
