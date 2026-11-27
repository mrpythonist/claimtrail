<?php

namespace App;

/**
 * Workflow run states (v2.0.0).
 *
 * A run moves running -> completed when every step finishes,
 * running -> waiting_approval at a checkpoint step and back
 * to running when it is approved, running -> failed when a
 * step fails permanently or exhausts its retries, and
 * waiting_approval -> rejected when a checkpoint is rejected.
 * Failed runs can be retried by hand; rejected runs are
 * terminal.
 */
enum WorkflowRunStatus: string
{
    case Running = 'running';
    case WaitingApproval = 'waiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::WaitingApproval => 'Waiting for approval',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Whether the run is still in progress.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::WaitingApproval], true);
    }

    /**
     * Every run status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
