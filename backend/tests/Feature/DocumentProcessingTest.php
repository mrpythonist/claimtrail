<?php

namespace Tests\Feature;

use App\DocumentClassification;
use App\Models\DocumentExtraction;
use App\Models\EvidenceFile;
use App\Models\Shipment;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI document processing (v2.0.1).
 *
 * OCR extraction through the local (mock) provider, rule-based
 * classification, structured field extraction with
 * confidences, shipment reference matching, duplicate
 * detection, human verification, and measured accuracy.
 * Nothing here is real OCR: the local provider reads text a
 * document already carries, and scanned images fail with a
 * plain reason.
 */
class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setupOrg(string $plan = 'starter'): array
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        if ($plan !== null) {
            $this->subscribe($organization, $plan);
        }

        return [$user, $organization];
    }

    protected function memberOf($organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->addMember($organization, $user, $role);

        return $user;
    }

    protected function actAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    protected function makeEvidence($organization, Shipment $shipment, string $contents, string $filename, string $mimeType): EvidenceFile
    {
        $key = 'evidence/'.$organization->getKey().'/'.$shipment->getKey().'/'.Str::uuid().'.'.pathinfo($filename, PATHINFO_EXTENSION);

        Storage::disk('private')->put($key, $contents);

        return EvidenceFile::create([
            'organization_id' => $organization->getKey(),
            'shipment_id' => $shipment->getKey(),
            'document_category' => 'other',
            'original_filename' => $filename,
            'storage_key' => $key,
            'disk' => 'private',
            'mime_type' => $mimeType,
            'file_size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => auth()->id(),
        ]);
    }

    protected function processDocument($organization, string $evidenceId): array
    {
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/documents/process', ['evidence_file_id' => $evidenceId]);

        $response->assertCreated();

        return $response->json('data');
    }

    protected function fieldByName(array $extraction, string $name): ?array
    {
        foreach ($extraction['fields'] as $field) {
            if ($field['field_name'] === $name) {
                return $field;
            }
        }

        return null;
    }

    protected function verifyField($organization, string $extractionId, string $fieldId, string $decision, ?string $correctedValue = null): void
    {
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$extractionId}/fields/{$fieldId}/verify", array_filter([
                'decision' => $decision,
                'corrected_value' => $correctedValue,
            ], fn ($value) => $value !== null));

        $response->assertOk();
    }

    public function test_bol_document_is_classified_matched_and_completed(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $evidence = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            BILL OF LADING
            BOL No. PLT-1001
            Shipper: Acme Logistics LLC
            Consignee: Beta Retail Inc
            Pickup Date: 2026-11-20
            Delivery Date: 2026-11-22
            Freight Amount: $1,250.00
            TEXT, 'bol-plt-1001.txt', 'text/plain');

        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame('bill_of_lading', $data['classification']);
        $this->assertSame('completed', $data['status']);
        $this->assertSame('local', $data['provider']);
        $this->assertSame('exact', $data['match_method']);
        $this->assertSame((string) $shipment->getKey(), $data['matched_shipment_id']);
        $this->assertEquals(100.0, $data['match_confidence']);
        $this->assertFalse($data['is_duplicate']);

        $this->assertSame('Acme Logistics LLC', $this->fieldByName($data, 'shipper')['field_value']);
        $this->assertSame('Beta Retail Inc', $this->fieldByName($data, 'consignee')['field_value']);
        $this->assertSame('2026-11-20', $this->fieldByName($data, 'pickup_date')['field_value']);
        $this->assertSame('2026-11-22', $this->fieldByName($data, 'delivery_date')['field_value']);
        $this->assertSame('1250.00', $this->fieldByName($data, 'amount_total')['field_value']);
        $this->assertSame('PLT-1001', $this->fieldByName($data, 'reference')['field_value']);

        foreach ($data['fields'] as $field) {
            $this->assertGreaterThanOrEqual(70.0, $field['confidence']);
            $this->assertSame('pending', $field['verification_status']);
        }
    }

    public function test_detention_notice_needs_verification_then_verifies_by_hand(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $evidence = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            DETENTION NOTICE
            Detention Charge
            Load: CT-7788
            4.5 hours waiting time
            Total: $450.00
            Date: 2026-11-24
            TEXT, 'detention-ct-7788.txt', 'text/plain');

        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame('detention_notice', $data['classification']);
        $this->assertSame('needs_verification', $data['status']);
        $this->assertSame('candidate', $data['match_method']);
        $this->assertNull($data['matched_shipment_id']);
        $this->assertSame('4.5', $this->fieldByName($data, 'detention_hours')['field_value']);
        $this->assertSame('450.00', $this->fieldByName($data, 'detention_amount')['field_value']);
        $this->assertSame('2026-11-24', $this->fieldByName($data, 'detention_date')['field_value']);

        // The whole extraction cannot verify while the match
        // is unresolved.
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", []);
        $response->assertUnprocessable();

        // A human links the shipment and decides every field.
        $link = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/link-shipment", ['shipment_id' => (string) $shipment->getKey()]);
        $link->assertOk();
        $this->assertSame('manual', $link->json('data.match_method'));

        foreach ($data['fields'] as $field) {
            $this->verifyField($organization, $data['id'], $field['id'], 'verified');
        }

        $verify = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", []);
        $verify->assertOk();
        $this->assertSame('verified', $verify->json('data.status'));

        // Accuracy now measures those four agreements.
        $accuracy = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents/accuracy');
        $accuracy->assertOk();
        $this->assertEquals(100.0, $accuracy->json('data.overall.accuracy'));
        $this->assertSame(4, $accuracy->json('data.overall.decided'));
    }

    public function test_duplicate_upload_is_flagged_not_deleted(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $contents = <<<'TEXT'
            BILL OF LADING
            BOL No. PLT-1001
            Shipper: Acme Logistics LLC
            Consignee: Beta Retail Inc
            Pickup Date: 2026-11-20
            Delivery Date: 2026-11-22
            Freight Amount: $1,250.00
            TEXT;

        $first = $this->makeEvidence($organization, $shipment, $contents, 'bol-1.txt', 'text/plain');
        $firstData = $this->processDocument($organization, (string) $first->getKey());
        $this->assertSame('completed', $firstData['status']);

        $second = $this->makeEvidence($organization, $shipment, $contents, 'bol-1-copy.txt', 'text/plain');
        $secondData = $this->processDocument($organization, (string) $second->getKey());

        $this->assertTrue($secondData['is_duplicate']);
        $this->assertSame($firstData['id'], $secondData['duplicate_of_id']);
        $this->assertSame('needs_verification', $secondData['status']);
        $this->assertSame(2, DocumentExtraction::count());
    }

    public function test_processing_is_idempotent_for_one_evidence_file(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $evidence = $this->makeEvidence($organization, $shipment, "INVOICE\nTotal Due: \$100.00\n", 'invoice.txt', 'text/plain');

        $first = $this->processDocument($organization, (string) $evidence->getKey());
        $second = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DocumentExtraction::count());
    }

    public function test_corrected_field_counts_as_disagreement_in_accuracy(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $evidence = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            INVOICE
            Invoice Number: INV-5501
            Invoice Date: 2026-11-25
            Amount Due: $900.00
            Broker: Acme Logistics LLC
            Due Date: 2026-12-10
            TEXT, 'invoice-5501.txt', 'text/plain');

        $data = $this->processDocument($organization, (string) $evidence->getKey());
        $this->assertSame('invoice', $data['classification']);
        $this->assertSame('needs_verification', $data['status']);

        // The amount was read wrong: correct it, verify the
        // rest, dismiss the unmatched reference, then verify.
        $amount = $this->fieldByName($data, 'amount_due');
        $this->verifyField($organization, $data['id'], $amount['id'], 'corrected', '850.00');

        foreach ($data['fields'] as $field) {
            if ($field['id'] === $amount['id']) {
                continue;
            }

            $this->verifyField($organization, $data['id'], $field['id'], 'verified');
        }

        $dismiss = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/dismiss-match", []);
        $dismiss->assertOk();
        $this->assertTrue($dismiss->json('data.match_dismissed'));

        $verify = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", []);
        $verify->assertOk();

        $accuracy = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents/accuracy');
        $accuracy->assertOk();
        // Five of six fields agreed; the corrected amount did not.
        $this->assertSame(6, $accuracy->json('data.overall.decided'));
        $this->assertSame(5, $accuracy->json('data.overall.agreed'));
        $this->assertSame(83.33, $accuracy->json('data.overall.accuracy'));
        $this->assertEquals(0.0, $accuracy->json('data.by_field.amount_due.accuracy'));
        $this->assertEquals(100.0, $accuracy->json('data.by_field.invoice_number.accuracy'));
    }

    public function test_accuracy_is_null_when_nothing_has_been_decided(): void
    {
        [, $organization] = $this->setupOrg();

        $accuracy = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents/accuracy');

        $accuracy->assertOk();
        $this->assertNull($accuracy->json('data.overall.accuracy'));
        $this->assertSame(0, $accuracy->json('data.overall.decided'));
    }

    public function test_scanned_image_fails_with_a_plain_reason(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $evidence = $this->makeEvidence($organization, $shipment, '', 'scan.jpg', 'image/jpeg');

        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame('failed', $data['status']);
        $this->assertStringContainsString('cannot OCR', $data['failure_reason']);
        $this->assertSame([], $data['fields']);
    }

    public function test_pdf_text_layer_is_extracted(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $pdf = "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            ."4 0 obj << /Length 200 >> stream\n"
            ."BT /F1 12 Tf 72 720 Td (BILL OF LADING) Tj ET\n"
            ."BT /F1 12 Tf 72 700 Td (Shipper: Acme Logistics LLC) Tj ET\n"
            ."BT /F1 12 Tf 72 680 Td (BOL No. PLT-1001) Tj ET\n"
            ."endstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";

        $evidence = $this->makeEvidence($organization, $shipment, $pdf, 'bol.pdf', 'application/pdf');

        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame('bill_of_lading', $data['classification']);
        $this->assertSame('completed', $data['status']);
        $this->assertSame('exact', $data['match_method']);
        $this->assertStringContainsString('BILL OF LADING', $data['extracted_text']);
        $this->assertSame('Acme Logistics LLC', $this->fieldByName($data, 'shipper')['field_value']);
    }

    public function test_verification_queue_lists_only_open_documents(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $done = $this->makeEvidence($organization, $shipment, "BILL OF LADING\nBOL No. PLT-1001\nShipper: Acme Logistics LLC\n", 'bol.txt', 'text/plain');
        $this->processDocument($organization, (string) $done->getKey());

        $open = $this->makeEvidence($organization, $shipment, "DETENTION NOTICE\nDetention Charge\nLoad: CT-7788\n", 'detention.txt', 'text/plain');
        $openData = $this->processDocument($organization, (string) $open->getKey());

        $queue = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents/queue');
        $queue->assertOk();

        $ids = array_column($queue->json('data'), 'id');
        $this->assertContains($openData['id'], $ids);
        $this->assertNotContains($this->processDocument($organization, (string) $done->getKey())['id'], $ids);
    }

    public function test_free_plan_is_refused_402_and_viewer_is_refused_403(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $evidence = $this->makeEvidence($organization, $shipment, "BILL OF LADING\n", 'bol.txt', 'text/plain');

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/documents/process', ['evidence_file_id' => (string) $evidence->getKey()]);
        $response->assertStatus(402);

        $this->subscribe($organization, 'starter');

        $viewer = $this->memberOf($organization, OrganizationRole::Viewer);
        $this->actAs($viewer);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/documents/process', ['evidence_file_id' => (string) $evidence->getKey()]);
        $response->assertForbidden();
    }

    public function test_other_organization_cannot_read_an_extraction(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $evidence = $this->makeEvidence($organization, $shipment, "BILL OF LADING\nBOL No. PLT-1001\n", 'bol.txt', 'text/plain');
        $data = $this->processDocument($organization, (string) $evidence->getKey());

        [$otherUser, $otherOrg] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($otherOrg, 'starter');

        $response = $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson("/api/v1/documents/{$data['id']}");
        $response->assertNotFound();

        $list = $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson('/api/v1/documents');
        $list->assertOk();
        $this->assertSame([], $list->json('data'));
    }

    public function test_rejecting_an_extraction_records_the_reason(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $evidence = $this->makeEvidence($organization, $shipment, "DETENTION NOTICE\nDetention Charge\nLoad: CT-7788\n", 'detention.txt', 'text/plain');
        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/reject", ['reason' => 'Wrong document for this load.']);
        $response->assertOk();
        $this->assertSame('rejected', $response->json('data.status'));
        $this->assertSame('Wrong document for this load.', $response->json('data.failure_reason'));

        // A rejected extraction cannot be verified afterwards.
        $verify = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", []);
        $verify->assertUnprocessable();
    }

    public function test_unclassified_document_needs_a_human_named_type_to_verify(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $evidence = $this->makeEvidence($organization, $shipment, "hello world\n", 'note.txt', 'text/plain');
        $data = $this->processDocument($organization, (string) $evidence->getKey());

        $this->assertSame('unclassified', $data['classification']);
        $this->assertSame('needs_verification', $data['status']);

        $verify = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", []);
        $verify->assertUnprocessable();

        $verify = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/documents/{$data['id']}/verify", ['classification' => 'other']);
        $verify->assertOk();
        $this->assertSame('verified', $verify->json('data.status'));
        $this->assertSame('other', $verify->json('data.classification'));
    }

    public function test_reference_with_single_character_segment_extracts_in_full(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'CT-DOC-1']);

        $evidence = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            DETENTION NOTICE
            Load reference: CT-DOC-1
            Detention amount: $450.00
            Detention date: 2026-11-20
            TEXT, 'detention-ct-doc-1.txt', 'text/plain');

        $data = $this->processDocument($organization, (string) $evidence->getKey());

        // A reference ending in a single character used to
        // truncate to CT-DOC; the extracted reference field
        // must carry the full reference.
        $this->assertSame('CT-DOC-1', $this->fieldByName($data, 'reference')['field_value']);
        $this->assertSame('exact', $data['match_method']);
        $this->assertSame((string) $shipment->getKey(), $data['matched_shipment_id']);
    }

    public function test_near_duplicate_is_flagged_and_queued(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $first = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            DETENTION NOTICE
            Load reference: PLT-1001
            Detention amount: $450.00
            Detention date: 2026-11-20
            TEXT, 'detention-1.txt', 'text/plain');
        $firstData = $this->processDocument($organization, (string) $first->getKey());
        $this->assertSame('completed', $firstData['status']);

        // Same reference, amount, and date, but different
        // bytes: a near duplicate, not an exact one.
        $second = $this->makeEvidence($organization, $shipment, <<<'TEXT'
            DETENTION NOTICE
            Load reference: PLT-1001
            Detention amount: $450.00
            Detention date: 2026-11-20
            Second copy filed for the carrier records.
            TEXT, 'detention-1-second-copy.txt', 'text/plain');
        $secondData = $this->processDocument($organization, (string) $second->getKey());

        $this->assertFalse($secondData['is_duplicate']);
        $this->assertSame($firstData['id'], $secondData['near_duplicate_of_id']);
        $this->assertNotNull($secondData['near_duplicate_reason']);
        // A near duplicate goes to the human queue: only a
        // person decides it is the same document twice.
        $this->assertSame('needs_verification', $secondData['status']);
    }

    public function test_duplicate_filter_accepts_true_as_a_query_string(): void
    {
        Storage::fake('private');
        [, $organization] = $this->setupOrg();
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);

        $contents = <<<'TEXT'
            BILL OF LADING
            BOL No. PLT-1001
            Shipper: Acme Logistics LLC
            Consignee: Beta Retail Inc
            Pickup Date: 2026-11-20
            Delivery Date: 2026-11-22
            Freight Amount: $1,250.00
            TEXT;

        $first = $this->makeEvidence($organization, $shipment, $contents, 'bol-1.txt', 'text/plain');
        $firstData = $this->processDocument($organization, (string) $first->getKey());
        $second = $this->makeEvidence($organization, $shipment, $contents, 'bol-1-copy.txt', 'text/plain');
        $secondData = $this->processDocument($organization, (string) $second->getKey());

        // Query strings carry booleans as text; the filter
        // used to refuse "true" with 422.
        $duplicates = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents?is_duplicate=true');
        $duplicates->assertOk();
        $this->assertSame([$secondData['id']], array_column($duplicates->json('data'), 'id'));

        $originals = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents?is_duplicate=false');
        $originals->assertOk();
        $this->assertSame([$firstData['id']], array_column($originals->json('data'), 'id'));
        $this->assertNotContains($secondData['id'], array_column($originals->json('data'), 'id'));
    }
}
