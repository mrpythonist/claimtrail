<?php

namespace App;

/**
 * How a claim payment was received (v1.5.0).
 *
 * The method is recorded for the ledger and the reconciliation
 * report; it is a label the organization picks, not a live
 * payment rail. Nothing here contacts a bank or a processor.
 */
enum PaymentMethod: string
{
    case WireTransfer = 'wire_transfer';
    case Ach = 'ach';
    case Cheque = 'cheque';
    case Cash = 'cash';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WireTransfer => 'Wire transfer',
            self::Ach => 'ACH',
            self::Cheque => 'Cheque',
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Other => 'Other',
        };
    }

    /**
     * Every method value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $method) => $method->value, self::cases());
    }
}
