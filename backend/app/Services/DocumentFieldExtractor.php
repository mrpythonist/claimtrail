<?php

namespace App\Services;

use App\DocumentClassification;

/**
 * Structured field extraction from document text (v2.0.1).
 *
 * Rule-based patterns only, per document type. Every field
 * carries a confidence from one of three documented tiers:
 *
 * - 95: a labelled value ("Total Due: $1,250.00", "Broker:
 *   Acme Logistics") — the label names the field.
 * - 80: a value found near its keyword ("detention ... $450",
 *   "delivery ... 2026-11-20") — the keyword is close but the
 *   layout does not name the field.
 * - 60: a bare pattern (a lone amount, date, or hour count
 *   with no label or keyword nearby) — present, but weakly
 *   identified.
 *
 * Amounts are normalized to two decimals; dates to ISO
 * (YYYY-MM-DD). A field that is not found is omitted, never
 * guessed. Shipment references are NOT extracted here: the
 * processing service owns them through the existing
 * ShipmentReferenceExtractor (v1.3.0), so there is one
 * reference vocabulary in the app.
 */
class DocumentFieldExtractor
{
    /**
     * Fields per document type: [field name, label, extractor].
     *
     * @var array<string, array<int, array{string, string, string}>>
     */
    protected const FIELDS = [
        'bill_of_lading' => [
            ['shipper', 'Shipper', 'party:shipper'],
            ['consignee', 'Consignee', 'party:consignee'],
            ['pro_number', 'PRO number', 'pro_number'],
            ['pickup_date', 'Pickup date', 'date:pickup'],
            ['delivery_date', 'Delivery date', 'date:delivery'],
            ['amount_total', 'Freight amount', 'amount:freight'],
        ],
        'proof_of_delivery' => [
            ['delivered_date', 'Delivered date', 'date:delivery'],
            ['received_by', 'Received by', 'party:received_by'],
            ['delivery_location', 'Delivery location', 'location:delivery'],
            ['amount_total', 'Amount', 'amount:any'],
        ],
        'invoice' => [
            ['invoice_number', 'Invoice number', 'invoice_number'],
            ['invoice_date', 'Invoice date', 'date:invoice'],
            ['amount_due', 'Amount due', 'amount:due'],
            ['due_date', 'Due date', 'date:due'],
            ['broker_name', 'Broker', 'party:broker'],
        ],
        'rate_confirmation' => [
            ['agreed_rate', 'Agreed rate', 'amount:rate'],
            ['linehaul_amount', 'Linehaul amount', 'amount:linehaul'],
            ['fuel_surcharge', 'Fuel surcharge', 'amount:fuel'],
            ['pickup_date', 'Pickup date', 'date:pickup'],
            ['delivery_date', 'Delivery date', 'date:delivery'],
            ['broker_name', 'Broker', 'party:broker'],
            ['carrier_name', 'Carrier', 'party:carrier'],
        ],
        'detention_notice' => [
            ['detention_hours', 'Detention hours', 'detention_hours'],
            ['detention_amount', 'Detention amount', 'amount:detention'],
            ['detention_date', 'Detention date', 'date:detention'],
            ['broker_name', 'Broker', 'party:broker'],
            ['carrier_name', 'Carrier', 'party:carrier'],
        ],
        'other' => [
            ['amount_total', 'Amount', 'amount:any'],
            ['document_date', 'Document date', 'date:any'],
        ],
        'unclassified' => [
            ['amount_total', 'Amount', 'amount:any'],
            ['document_date', 'Document date', 'date:any'],
        ],
    ];

    // Bare pattern (no delimiters): it is embedded inside
    // larger patterns at the use sites. The first
    // alternative requires at least one comma group, so a
    // plain digit run such as 1800 falls through to the
    // second alternative: with (?:,\d{3})* the engine
    // matched the first three digits of $1800.00 and
    // returned 180.00.
    protected const AMOUNT_PATTERN = '\\$\\s?(\\d{1,3}(?:,\\d{3})+(?:\\.\\d{2})?|\\d+(?:\\.\\d{2})?)';

    protected const ISO_DATE_PATTERN = '/\b(\d{4})-(\d{2})-(\d{2})\b/';

    protected const US_DATE_PATTERN = '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/';

    protected const MONTH_DATE_PATTERN = '/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+(\d{1,2}),\s+(\d{4})\b/i';

    /**
     * Extract the fields for one document type.
     *
     * @return array<int, array{name: string, label: string, value: string, confidence: float}>
     */
    public function extract(string $text, DocumentClassification $classification): array
    {
        $fields = [];

        foreach (self::FIELDS[$classification->value] ?? self::FIELDS['other'] as [$name, $label, $extractor]) {
            $found = $this->extractOne($text, $extractor);

            if ($found === null) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'label' => $label,
                'value' => $found['value'],
                'confidence' => $found['confidence'],
            ];
        }

        return $fields;
    }

    /**
     * One field by its extractor key.
     *
     * @return array{value: string, confidence: float}|null
     */
    protected function extractOne(string $text, string $extractor): ?array
    {
        [$kind, $arg] = array_pad(explode(':', $extractor, 2), 2, null);

        return match ($kind) {
            'party' => $this->labelledParty($text, (string) $arg),
            'location' => $this->labelledLocation($text, (string) $arg),
            'amount' => $this->amountField($text, (string) $arg),
            'date' => $this->dateField($text, (string) $arg),
            'invoice_number' => $this->labelledNumber($text, ['invoice']),
            'pro_number' => $this->labelledNumber($text, ['pro']),
            'detention_hours' => $this->detentionHours($text),
            default => null,
        };
    }

    /**
     * "Broker: Acme Logistics" — the label names the field.
     */
    protected function labelledParty(string $text, string $label): ?array
    {
        $labels = match ($label) {
            'broker' => ['broker'],
            'carrier' => ['carrier'],
            'shipper' => ['shipper'],
            'consignee' => ['consignee'],
            'received_by' => ['received by', 'received'],
            default => [$label],
        };

        foreach ($labels as $word) {
            if (preg_match('/'.preg_quote($word, '/').'\s*:\s*([A-Za-z0-9][A-Za-z0-9 .&\',\-]{1,79})/i', $text, $match) === 1) {
                $value = trim($match[1], " \t.,;");

                if ($value !== '') {
                    return ['value' => $value, 'confidence' => 95.00];
                }
            }
        }

        return null;
    }

    /**
     * "Delivery location: Dallas DC" — the label names it.
     */
    protected function labelledLocation(string $text, string $label): ?array
    {
        $words = match ($label) {
            'delivery' => ['delivery location', 'delivered to', 'delivery address'],
            default => [$label],
        };

        foreach ($words as $word) {
            if (preg_match('/'.preg_quote($word, '/').'\s*:\s*([A-Za-z0-9][A-Za-z0-9 .&\',\-]{1,79})/i', $text, $match) === 1) {
                $value = trim($match[1], " \t.,;");

                if ($value !== '') {
                    return ['value' => $value, 'confidence' => 95.00];
                }
            }
        }

        return null;
    }

    /**
     * Amounts: a labelled amount first (95), then an amount
     * near the field's keyword (80), then any amount (60).
     */
    protected function amountField(string $text, string $kind): ?array
    {
        $labels = match ($kind) {
            'freight' => ['freight amount', 'freight charges', 'freight'],
            'due' => ['amount due', 'total due', 'balance due', 'total'],
            'rate' => ['agreed rate', 'rate', 'total rate'],
            'linehaul' => ['linehaul'],
            'fuel' => ['fuel surcharge', 'fuel'],
            'detention' => ['detention amount', 'detention charge', 'detention fee', 'detention'],
            default => [],
        };

        foreach ($labels as $label) {
            $found = $this->amountAfterLabel($text, $label);

            if ($found !== null) {
                return ['value' => $found, 'confidence' => 95.00];
            }
        }

        $keywords = match ($kind) {
            'freight' => ['freight'],
            'due' => ['invoice', 'amount due', 'total'],
            'rate' => ['rate'],
            'linehaul' => ['linehaul'],
            'fuel' => ['fuel'],
            'detention' => ['detention'],
            default => [],
        };

        foreach ($keywords as $keyword) {
            $found = $this->amountNearKeyword($text, $keyword);

            if ($found !== null) {
                return ['value' => $found, 'confidence' => 80.00];
            }
        }

        $found = $this->firstAmount($text);

        if ($found !== null) {
            return ['value' => $found, 'confidence' => 60.00];
        }

        return null;
    }

    /**
     * Dates: a labelled date first (95), then a date near the
     * field's keyword (80), then any date (60).
     */
    protected function dateField(string $text, string $kind): ?array
    {
        $labels = match ($kind) {
            'pickup' => ['pickup date', 'pickup'],
            'delivery' => ['delivery date', 'delivered date', 'delivered', 'delivery'],
            'invoice' => ['invoice date', 'date'],
            'due' => ['due date', 'payment due'],
            'detention' => ['detention date', 'date of detention', 'date'],
            default => [],
        };

        foreach ($labels as $label) {
            $found = $this->dateAfterLabel($text, $label);

            if ($found !== null) {
                return ['value' => $found, 'confidence' => 95.00];
            }
        }

        $keywords = match ($kind) {
            'pickup' => ['pickup'],
            'delivery' => ['delivery', 'delivered'],
            'invoice' => ['invoice'],
            'due' => ['due'],
            'detention' => ['detention'],
            default => [],
        };

        foreach ($keywords as $keyword) {
            $found = $this->dateNearKeyword($text, $keyword);

            if ($found !== null) {
                return ['value' => $found, 'confidence' => 80.00];
            }
        }

        $found = $this->firstDate($text);

        if ($found !== null) {
            return ['value' => $found, 'confidence' => 60.00];
        }

        return null;
    }

    /**
     * "Invoice No. ABC-123" / "PRO # PLT-1001" — a number
     * after its label, on the same line. The separators are
     * spaces and tabs only: a newline must never be crossed,
     * or the label would capture the next line's first word
     * as its own number.
     */
    protected function labelledNumber(string $text, array $labels): ?array
    {
        foreach ($labels as $label) {
            if (preg_match('/'.preg_quote($label, '/').'[ \t]*(?:no\.?|number|#)?[ \t]*[:#]?[ \t]*([A-Z0-9][A-Z0-9\-]{2,19})/i', $text, $match) === 1) {
                return ['value' => strtoupper($match[1]), 'confidence' => 90.00];
            }
        }

        return null;
    }

    /**
     * "4.5 hours" near "detention" (85), else any hour count
     * in the text (60).
     */
    protected function detentionHours(string $text): ?array
    {
        if (preg_match('/detention[^.\n]{0,120}?(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)\b/i', $text, $match) === 1) {
            return ['value' => $match[1], 'confidence' => 85.00];
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)\b/i', $text, $match) === 1) {
            return ['value' => $match[1], 'confidence' => 60.00];
        }

        return null;
    }

    /**
     * A currency amount on the same line after a label.
     */
    protected function amountAfterLabel(string $text, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').'\b[^$\n]{0,40}?'.self::AMOUNT_PATTERN.'/i', $text, $match) === 1) {
            return $this->normalizeAmount($match[1]);
        }

        return null;
    }

    /**
     * The first currency amount within 120 characters after
     * a keyword.
     */
    protected function amountNearKeyword(string $text, string $keyword): ?string
    {
        $position = stripos($text, $keyword);

        if ($position === false) {
            return null;
        }

        $window = substr($text, $position, 120);

        if (preg_match('/'.self::AMOUNT_PATTERN.'/', $window, $match) === 1) {
            return $this->normalizeAmount($match[1]);
        }

        return null;
    }

    /**
     * The first currency amount anywhere in the text.
     */
    protected function firstAmount(string $text): ?string
    {
        if (preg_match('/'.self::AMOUNT_PATTERN.'/', $text, $match) === 1) {
            return $this->normalizeAmount($match[1]);
        }

        return null;
    }

    /**
     * A date on the same line after a label.
     */
    protected function dateAfterLabel(string $text, string $label): ?string
    {
        $position = stripos($text, $label);

        if ($position === false) {
            return null;
        }

        $line = substr($text, $position, 120);
        $line = strtok($line, "\n");

        return $this->parseDate($line);
    }

    /**
     * The first date within 120 characters after a keyword.
     */
    protected function dateNearKeyword(string $text, string $keyword): ?string
    {
        $position = stripos($text, $keyword);

        if ($position === false) {
            return null;
        }

        return $this->parseDate(substr($text, $position, 120));
    }

    /**
     * The first date anywhere in the text.
     */
    protected function firstDate(string $text): ?string
    {
        return $this->parseDate($text);
    }

    /**
     * Parse the first date in a string and normalize it to
     * ISO (YYYY-MM-DD). ISO dates, US dates, and month-name
     * dates are recognized. Returns null when no date is
     * found or the date is not a real calendar date.
     */
    protected function parseDate(string $text): ?string
    {
        if (preg_match(self::ISO_DATE_PATTERN, $text, $match) === 1) {
            return $this->validDate((int) $match[1], (int) $match[2], (int) $match[3]);
        }

        if (preg_match(self::US_DATE_PATTERN, $text, $match) === 1) {
            return $this->validDate((int) $match[3], (int) $match[1], (int) $match[2]);
        }

        if (preg_match(self::MONTH_DATE_PATTERN, $text, $match) === 1) {
            $months = [
                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
                'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
                'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            ];
            $month = $months[strtolower(substr($match[1], 0, 3))] ?? null;

            if ($month !== null) {
                return $this->validDate((int) $match[3], $month, (int) $match[2]);
            }
        }

        return null;
    }

    /**
     * An ISO date only when the day exists in that month.
     */
    protected function validDate(int $year, int $month, int $day): ?string
    {
        if ($year < 1900 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Normalize a currency amount to two decimals.
     */
    protected function normalizeAmount(string $amount): string
    {
        return number_format((float) str_replace(',', '', $amount), 2, '.', '');
    }
}
