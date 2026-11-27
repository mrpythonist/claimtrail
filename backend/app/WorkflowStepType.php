<?php

namespace App;

/**
 * Workflow step types (v2.0.0).
 *
 * Every step acts on the organization's own recorded data and
 * stays inside the app. No step sends anything to a broker or
 * calls an outside service: prepare_package assembles a draft
 * package locally, and escalate and notify write notes and
 * in-app notifications only.
 */
enum WorkflowStepType: string
{
    case EvidenceCheck = 'evidence_check';
    case PreparePackage = 'prepare_package';
    case Notify = 'notify';
    case Escalate = 'escalate';
    case Approval = 'approval';
    case Note = 'note';
    case Transition = 'transition';

    public function label(): string
    {
        return match ($this) {
            self::EvidenceCheck => 'Automated evidence check',
            self::PreparePackage => 'Prepare claim package draft',
            self::Notify => 'Send notification',
            self::Escalate => 'Escalate to a role',
            self::Approval => 'Human approval checkpoint',
            self::Note => 'Add a review note',
            self::Transition => 'Change claim status',
        };
    }

    /**
     * Every step type value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
