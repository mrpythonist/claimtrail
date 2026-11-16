<?php

namespace App;

/**
 * Claim categories. The values match the event category slugs, so a
 * claim created from an event inherits the event's category unless
 * the caller picks another one.
 */
enum ClaimCategory: string
{
    case Detention = 'detention';
    case Layover = 'layover';
    case Tonu = 'tonu';
    case Lumper = 'lumper';
    case AdditionalStop = 'additional_stop';
    case AdditionalMiles = 'additional_miles';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Detention => 'Detention',
            self::Layover => 'Layover',
            self::Tonu => 'Truck Ordered Not Used (TONU)',
            self::Lumper => 'Lumper',
            self::AdditionalStop => 'Additional Stop',
            self::AdditionalMiles => 'Additional Miles',
            self::Other => 'Other',
        };
    }

    /**
     * Every category value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }
}
