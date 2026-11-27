<?php

namespace App;

/**
 * What a human decided about one extracted field (v2.0.1).
 *
 * pending: not yet reviewed. verified: the extracted value is
 * right as-is. corrected: the extracted value was wrong and the
 * human supplied the right one (stored on verified_value).
 * rejected: the extracted value is not usable and no
 * replacement was supplied. A human-verified value is what
 * downstream features use; extraction never silently
 * overwrites a verified value.
 */
enum DocumentFieldVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Corrected = 'corrected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Corrected => 'Corrected',
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
