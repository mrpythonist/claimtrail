<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\WorkflowRunStatus;
use App\WorkflowTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a workflow for one claim.
 *
 * The run records its trigger, the step it reached, and how it
 * ended, so the whole execution is auditable afterwards. A run
 * is created once per trigger event: dispatch skips when an
 * active run for the same workflow and claim already exists,
 * and the daily deadline command skips a run already made
 * today, so a claim is never run twice for the same event.
 */
class WorkflowRun extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'status' => 'running',
        'current_step_index' => 0,
    ];

    protected $fillable = [
        'organization_id',
        'workflow_id',
        'claim_id',
        'trigger',
        'status',
        'actor_user_id',
        'current_step_index',
        'failure_reason',
        'context',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => WorkflowTrigger::class,
            'status' => WorkflowRunStatus::class,
            'current_step_index' => 'integer',
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The workflow this run executes.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * The claim the run is about.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The user whose action triggered the run, or the workflow
     * creator for system-triggered runs.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The run's steps in execution order.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class)->orderBy('step_index');
    }

    /**
     * The run's approval checkpoints in order.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class)->orderBy('step_index');
    }

    /**
     * The checkpoint that is currently open, if any.
     */
    public function pendingApproval(): ?WorkflowApproval
    {
        return $this->approvals()
            ->where('status', 'pending')
            ->orderBy('step_index')
            ->first();
    }
}
