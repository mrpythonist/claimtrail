<?php

namespace Tests\Unit;

use App\DocumentClassification;
use App\Services\DocumentClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Rule-based document classification (v2.0.1).
 *
 * Keyword coverage per type, a 0.25 threshold, "other" for a
 * document of an unknown type, "unclassified" for content
 * with no document signal at all. Nothing is forced into a
 * type.
 */
class DocumentClassifierTest extends TestCase
{
    protected DocumentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new DocumentClassifier();
    }

    public function test_bill_of_lading_wins_on_keywords(): void
    {
        $result = $this->classifier->classify("BILL OF LADING\nShipper: Acme\nConsignee: Beta\n");

        $this->assertSame(DocumentClassification::BillOfLading, $result['classification']);
        $this->assertGreaterThanOrEqual(25.0, $result['confidence']);
    }

    public function test_detention_notice_wins_on_keywords(): void
    {
        $result = $this->classifier->classify("DETENTION NOTICE\nDetention charge for 4 hours waiting time.\n");

        $this->assertSame(DocumentClassification::DetentionNotice, $result['classification']);
    }

    public function test_invoice_wins_on_keywords(): void
    {
        $result = $this->classifier->classify("INVOICE\nInvoice Number: INV-1\nAmount Due: \$500.00\nPayment Terms: Net 30\n");

        $this->assertSame(DocumentClassification::Invoice, $result['classification']);
    }

    public function test_rate_confirmation_wins_on_keywords(): void
    {
        $result = $this->classifier->classify("RATE CONFIRMATION\nAgreed rate: \$2,000.00\nLinehaul: \$1,800.00\n");

        $this->assertSame(DocumentClassification::RateConfirmation, $result['classification']);
    }

    public function test_proof_of_delivery_wins_on_keywords(): void
    {
        $result = $this->classifier->classify("PROOF OF DELIVERY\nDelivered on 2026-11-22\nReceived by: J. Smith\n");

        $this->assertSame(DocumentClassification::ProofOfDelivery, $result['classification']);
    }

    public function test_gibberish_is_unclassified(): void
    {
        $result = $this->classifier->classify("hello world\n");

        $this->assertSame(DocumentClassification::Unclassified, $result['classification']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_date_and_amount_without_a_type_is_other(): void
    {
        $result = $this->classifier->classify("Meeting notes\nDate: 2026-11-25\nTotal: \$100.00\n");

        $this->assertSame(DocumentClassification::Other, $result['classification']);
        $this->assertSame(50.0, $result['confidence']);
    }

    public function test_filename_hint_breaks_a_close_call(): void
    {
        // Shipper + consignee score 2/12; the "bol" filename
        // hint adds 0.10, which lifts the score over the 0.25
        // threshold. The text alone stays below it.
        $withoutHint = $this->classifier->classify("Shipper: Acme\nConsignee: Beta\n");
        $withHint = $this->classifier->classify("Shipper: Acme\nConsignee: Beta\n", 'bol-scan.txt');

        $this->assertSame(DocumentClassification::Unclassified, $withoutHint['classification']);
        $this->assertSame(DocumentClassification::BillOfLading, $withHint['classification']);
    }

    public function test_short_token_needs_a_word_boundary(): void
    {
        // "symbol" contains "bol" but must not count as a
        // bill of lading keyword.
        $result = $this->classifier->classify("The symbol on the crate was faded.\n");

        $this->assertSame(DocumentClassification::Unclassified, $result['classification']);
    }
}
