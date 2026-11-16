<?php

namespace App;

/**
 * Verification states for evidence files.
 *
 * Uploads start as pending. A reviewer marks a document verified when
 * it is the document it claims to be, or rejected when it is not
 * usable. Verification says nothing about whether a claim will be
 * accepted; it only records that someone checked the document.
 */
enum EvidenceVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Every status value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
