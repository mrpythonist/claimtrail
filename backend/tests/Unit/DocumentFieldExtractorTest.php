<?php

namespace Tests\Unit;

use App\DocumentClassification;
use App\Services\DocumentFieldExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Structured field extraction (v2.0.1).
 *
 * Labelled values score 95, keyword-proximity matches 80,
 * bare patterns 60. Amounts normalize to two decimals and
 * dates to ISO. A field that is not found is omitted, never
 * guessed.
 */
class DocumentFieldExtractorTest extends TestCase
{
    protected DocumentFieldExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new DocumentFieldExtractor();
    }

    protected function field(array $fields, string $name): ?array
    {
        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        return null;
    }

    public function test_invoice_fields_with_labelled_values(): void
    {
        $fields = $this->extractor->extract(<<<'TEXT'
            INVOICE
            Invoice Number: INV-5501
            Invoice Date: 2026-11-25
            Amount Due: $900.00
            Broker: Acme Logistics LLC
            Due Date: 2026-12-10
            TEXT, DocumentClassification::Invoice);

        $this->assertSame('INV-5501', $this->field($fields, 'invoice_number')['value']);
        $this->assertSame(90.0, $this->field($fields, 'invoice_number')['confidence']);
        $this->assertSame('2026-11-25', $this->field($fields, 'invoice_date')['value']);
        $this->assertSame(95.0, $this->field($fields, 'invoice_date')['confidence']);
        $this->assertSame('900.00', $this->field($fields, 'amount_due')['value']);
        $this->assertSame('2026-12-10', $this->field($fields, 'due_date')['value']);
        $this->assertSame('Acme Logistics LLC', $this->field($fields, 'broker_name')['value']);
    }

    public function test_us_dates_normalize_to_iso(): void
    {
        $fields = $this->extractor->extract(<<<'TEXT'
            INVOICE
            Invoice Date: 11/25/2026
            Due Date: December 10, 2026
            TEXT, DocumentClassification::Invoice);

        $this->assertSame('2026-11-25', $this->field($fields, 'invoice_date')['value']);
        $this->assertSame('2026-12-10', $this->field($fields, 'due_date')['value']);
    }

    public function test_amounts_normalize_to_two_decimals(): void
    {
        $fields = $this->extractor->extract(<<<'TEXT'
            RATE CONFIRMATION
            Agreed Rate: $2,000
            Linehaul: $1,800.50
            TEXT, DocumentClassification::RateConfirmation);

        $this->assertSame('2000.00', $this->field($fields, 'agreed_rate')['value']);
        $this->assertSame('1800.50', $this->field($fields, 'linehaul_amount')['value']);
    }

    public function test_ungrouped_four_digit_amounts_are_not_truncated(): void
    {
        $fields = $this->extractor->extract(<<<'TEXT'
            RATE CONFIRMATION
            Agreed Rate: $1800.00
            Linehaul: $2300.00
            Fuel Surcharge: $12500
            TEXT, DocumentClassification::RateConfirmation);

        // A plain digit run used to match only its first
        // three digits ($1800.00 became 180.00).
        $this->assertSame('1800.00', $this->field($fields, 'agreed_rate')['value']);
        $this->assertSame('2300.00', $this->field($fields, 'linehaul_amount')['value']);
        $this->assertSame('12500.00', $this->field($fields, 'fuel_surcharge')['value']);
    }

    public function test_detention_hours_near_the_keyword(): void
    {
        $fields = $this->extractor->extract(<<<'TEXT'
            DETENTION NOTICE
            Detention Charge
            4.5 hours waiting time
            Total: $450.00
            TEXT, DocumentClassification::DetentionNotice);

        $this->assertSame('4.5', $this->field($fields, 'detention_hours')['value']);
        $this->assertSame('450.00', $this->field($fields, 'detention_amount')['value']);
    }

    public function test_missing_fields_are_omitted_not_guessed(): void
    {
        $fields = $this->extractor->extract("BILL OF LADING\n", DocumentClassification::BillOfLading);

        $this->assertSame([], $fields);
    }

    public function test_bare_amount_is_low_confidence(): void
    {
        $fields = $this->extractor->extract("Some notes\n\$123.45\n", DocumentClassification::Other);

        $this->assertSame('123.45', $this->field($fields, 'amount_total')['value']);
        $this->assertSame(60.0, $this->field($fields, 'amount_total')['confidence']);
    }

    public function test_impossible_dates_are_not_extracted(): void
    {
        $fields = $this->extractor->extract("INVOICE\nInvoice Date: 2026-02-30\n", DocumentClassification::Invoice);

        $this->assertNull($this->field($fields, 'invoice_date'));
    }
}
