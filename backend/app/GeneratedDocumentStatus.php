<?php

namespace App;

/**
 * States for a generated claim package document.
 */
enum GeneratedDocumentStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
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
