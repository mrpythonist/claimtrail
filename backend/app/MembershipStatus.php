<?php

namespace App;

enum MembershipStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
