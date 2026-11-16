<?php

namespace App;

/**
 * Shipment event review states.
 *
 * Every event starts as potential: a possible claim opportunity, never
 * an automatic qualification. Review moves it to under_review, and
 * events that do not lead anywhere are dismissed. No status here means
 * the event qualifies for compensation.
 */
enum ShipmentEventStatus: string
{
    case Potential = 'potential';
    case UnderReview = 'under_review';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Potential => 'Potential',
            self::UnderReview => 'Under Review',
            self::Dismissed => 'Dismissed',
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
