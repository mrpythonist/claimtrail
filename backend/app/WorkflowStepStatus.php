<?php

namespace App;

/**
 * Workflow run step states (v2.0.0).
 *
 * A step is pending until the engine reaches it, running
 * while it executes, completed when it returns a result,
 * failed when it throws, skipped when an earlier step or a
 * rejected checkpoint stops the run, and waiting_approval
 * while its checkpoint is open.
 */
enum WorkflowStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case WaitingApproval = 'waiting_approval';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::WaitingApproval => 'Waiting for approval',
        };
    }

    /**
     * Every step status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
