<?php

namespace App;

/**
 * Types of generated documents. Only the claim package exists in
 * Phase 1; the enum leaves room for later document types without a
 * schema change.
 */
enum GeneratedDocumentType: string
{
    case ClaimPackage = 'claim_package';

    public function label(): string
    {
        return match ($this) {
            self::ClaimPackage => 'Claim Package',
        };
    }

    /**
     * Every type value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
