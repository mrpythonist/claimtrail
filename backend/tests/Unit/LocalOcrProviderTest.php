<?php

namespace Tests\Unit;

use App\Services\LocalOcrProvider;
use PHPUnit\Framework\TestCase;

/**
 * The local OCR provider (v2.0.1) — the mock provider.
 *
 * It reads text a document already carries: plain text
 * verbatim and the text layer of a PDF. It cannot OCR
 * scanned images, and it says so instead of inventing text.
 */
class LocalOcrProviderTest extends TestCase
{
    protected LocalOcrProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new LocalOcrProvider();
    }

    public function test_name_is_local(): void
    {
        $this->assertSame('local', $this->provider->name());
    }

    public function test_plain_text_is_returned_verbatim(): void
    {
        $result = $this->provider->extractText("BILL OF LADING\nShipper: Acme\n", 'text/plain', 'bol.txt');

        $this->assertSame("BILL OF LADING\nShipper: Acme", $result->text);
        $this->assertTrue($result->hasTextLayer);
        $this->assertNull($result->note);
    }

    public function test_pdf_text_layer_is_extracted(): void
    {
        $pdf = "%PDF-1.4\n"
            ."4 0 obj << /Length 100 >> stream\n"
            ."BT /F1 12 Tf 72 720 Td (BILL OF LADING) Tj ET\n"
            ."BT /F1 12 Tf 72 700 Td (Shipper: Acme Logistics LLC) Tj ET\n"
            ."endstream endobj\n";

        $result = $this->provider->extractText($pdf, 'application/pdf', 'bol.pdf');

        $this->assertTrue($result->hasTextLayer);
        $this->assertStringContainsString('BILL OF LADING', $result->text);
        $this->assertStringContainsString('Shipper: Acme Logistics LLC', $result->text);
    }

    public function test_pdf_without_a_text_layer_reports_it(): void
    {
        $pdf = "%PDF-1.4\n4 0 obj << /Length 10 >> stream\n\x01\x02\x03\x04\nendstream endobj\n";

        $result = $this->provider->extractText($pdf, 'application/pdf', 'scan.pdf');

        $this->assertFalse($result->hasTextLayer);
        $this->assertSame('', $result->text);
        $this->assertStringContainsString('No text layer', $result->note);
    }

    public function test_image_reports_it_cannot_ocr(): void
    {
        $result = $this->provider->extractText("\xFF\xD8\xFF", 'image/jpeg', 'scan.jpg');

        $this->assertFalse($result->hasTextLayer);
        $this->assertSame('', $result->text);
        $this->assertStringContainsString('cannot OCR', $result->note);
    }

    public function test_compressed_pdf_stream_is_decompressed(): void
    {
        $stream = "BT /F1 12 Tf 72 720 Td (DETENTION NOTICE) Tj ET\n";
        $pdf = "%PDF-1.4\n4 0 obj << /Length 100 /Filter /FlateDecode >> stream\n".gzcompress($stream)."endstream endobj\n";

        $result = $this->provider->extractText($pdf, 'application/pdf', 'detention.pdf');

        $this->assertTrue($result->hasTextLayer);
        $this->assertStringContainsString('DETENTION NOTICE', $result->text);
    }
}
