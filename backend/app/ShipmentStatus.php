<?php

namespace App;

/**
 * Shipment lifecycle states.
 *
 * Transitions move forward only: draft -> planned -> in_transit ->
 * delivered -> completed. Any non-archived status may move to archived,
 * and archived is terminal: an archived shipment is read-only and
 * cannot move back.
 */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Planned => 'Planned',
            self::InTransit => 'In Transit',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Statuses this status may move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Planned, self::Archived],
            self::Planned => [self::InTransit, self::Archived],
            self::InTransit => [self::Delivered, self::Archived],
            self::Delivered => [self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    /**
     * Whether a move to the given status is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
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
