<?php

namespace App;

/**
 * Evidence readiness states for a claim.
 *
 * Readiness is informational only: it counts required evidence
 * categories against attached evidence. It never certifies that a
 * claim is valid or will be accepted by a broker.
 */
enum ReadinessStatus: string
{
    case NotStarted = 'not_started';
    case Partial = 'partial';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Started',
            self::Partial => 'Partial',
            self::Ready => 'Ready',
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
