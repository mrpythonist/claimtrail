<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\WorkflowTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workflow: one trigger, a set of conditions, and ordered steps.
 *
 * A workflow belongs to one organization and fires only for its
 * own claims. Conditions are all AND: every condition must match
 * the claim as recorded or the workflow does not run. Steps run
 * in order; an approval step pauses the run until a member with
 * the named role approves or rejects it. Steps are internal
 * only: nothing here sends a claim package or calls an outside
 * service.
 */
class Workflow extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'trigger',
        'is_active',
        'conditions',
        'steps',
        'deadline_days_before',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => WorkflowTrigger::class,
            'is_active' => 'boolean',
            'conditions' => 'array',
            'steps' => 'array',
            'deadline_days_before' => 'integer',
        ];
    }

    /**
     * The runs this workflow has produced, newest first.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->orderByDesc('created_at');
    }

    /**
     * The member who created the workflow.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The member who last updated the workflow.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The days before a deadline at which the
     * deadline.approaching trigger fires: the workflow's own
     * window, or 7 days when none is set.
     */
    public function deadlineWindowDays(): int
    {
        return $this->deadline_days_before ?? 7;
    }
}
