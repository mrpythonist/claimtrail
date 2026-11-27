<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\WorkflowStepStatus;
use App\WorkflowStepType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of one workflow run.
 *
 * The step records its type, its outcome, how many attempts it
 * took, and the result it produced (or the error that stopped
 * it), so a failed run shows exactly which step failed and why,
 * and a retried run resumes from the first step that did not
 * complete.
 */
class WorkflowRunStep extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $fillable = [
        'organization_id',
        'workflow_run_id',
        'step_index',
        'step_type',
        'status',
        'attempts',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'step_type' => WorkflowStepType::class,
            'status' => WorkflowStepStatus::class,
            'attempts' => 'integer',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The run this step belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
