<?php

namespace App;

/**
 * Document types the rule-based classifier can assign (v2.0.1).
 *
 * Classification is rule-based over the extracted text and the
 * filename: keyword coverage per type, documented in
 * DocumentClassifier. A document that matches no type is
 * "unclassified", never forced into a type. "other" means the
 * text looks like a document (it carries a date, an amount, or
 * a reference) but matches no known type.
 */
enum DocumentClassification: string
{
    case BillOfLading = 'bill_of_lading';
    case ProofOfDelivery = 'proof_of_delivery';
    case Invoice = 'invoice';
    case RateConfirmation = 'rate_confirmation';
    case DetentionNotice = 'detention_notice';
    case Other = 'other';
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::BillOfLading => 'Bill of Lading',
            self::ProofOfDelivery => 'Proof of Delivery',
            self::Invoice => 'Invoice',
            self::RateConfirmation => 'Rate Confirmation',
            self::DetentionNotice => 'Detention Notice',
            self::Other => 'Other document',
            self::Unclassified => 'Unclassified',
        };
    }

    /**
     * Every classification value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $classification) => $classification->value, self::cases());
    }

    /**
     * The classes a human verifier may assign when correcting
     * a classification. "unclassified" is not a correction: a
     * human who reviews a document names what it is.
     *
     * @return array<int, string>
     */
    public static function verifiableValues(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $value) => $value !== self::Unclassified->value,
        ));
    }
}
