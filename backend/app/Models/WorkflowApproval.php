<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human decision on a workflow approval checkpoint.
 *
 * A checkpoint step opens one approval row naming the role that
 * may decide it. The run pauses until a member holding that role
 * (or the organization owner) approves or rejects it; the
 * decision, the decider, and the time are recorded here, and
 * the run only continues after an approval. A rejection ends
 * the run.
 */
class WorkflowApproval extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'organization_id',
        'workflow_run_id',
        'step_index',
        'role',
        'status',
        'decided_by',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The run this approval belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /**
     * The member who decided the checkpoint.
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Whether the checkpoint is still open.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
