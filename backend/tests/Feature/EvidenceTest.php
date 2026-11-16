<?php

namespace Tests\Feature;

use App\Models\EvidenceFile;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real minimal PDF: the %PDF header is what the MIME guesser
     * recognizes, so a file of random bytes named .pdf would not do.
     */
    protected function pdfFile(string $name = 'bill-of-lading.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_a_pdf_can_be_uploaded_for_a_shipment(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'bill_of_lading',
                'description' => 'Signed BOL from the shipper.',
                'file' => $this->pdfFile(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_filename', 'bill-of-lading.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.document_category_label', 'Bill of Lading');

        $this->assertArrayNotHasKey('storage_key', $response->json('data'));
        $this->assertArrayNotHasKey('disk', $response->json('data'));

        $evidence = EvidenceFile::firstOrFail();
        Storage::disk('private')->assertExists($evidence->storage_key);
        $this->assertSame($user->id, $evidence->uploaded_by);
    }

    public function test_a_renamed_executable_is_rejected_by_content(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $path = tempnam(sys_get_temp_dir(), 'exe');
        file_put_contents($path, random_bytes(256));
        $file = new UploadedFile($path, 'innocent.pdf', 'application/pdf', null, true);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, EvidenceFile::count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => UploadedFile::fake()->create('huge.pdf', 21000),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_to_another_organizations_shipment_is_rejected(): void
    {
        Storage::fake('private');
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $shipmentB = $this->createShipment($orgB);

        $this->withHeaders($this->orgHeaders($orgA))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipmentB->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipment_id']);
    }

    public function test_upload_with_an_event_from_another_shipment_is_rejected(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $other = $this->createShipment($organization);
        $event = $this->createEvent($other);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'shipment_event_id' => $event->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The event does not belong to this shipment.');
    }

    public function test_evidence_cannot_be_added_to_an_archived_shipment(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Archived]);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Evidence cannot be added to an archived shipment.');
    }

    public function test_download_returns_the_file_and_cross_org_access_is_denied(): void
    {
        Storage::fake('private');
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($orgA);

        $evidenceId = $this->withHeaders($this->orgHeaders($orgA))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'proof_of_delivery',
                'file' => $this->pdfFile('pod.pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        $download = $this->withHeaders($this->orgHeaders($orgA))
            ->get('/api/v1/evidence/'.$evidenceId.'/download');

        $download->assertOk();
        $this->assertStringContainsString(
            'attachment; filename=pod.pdf',
            $download->headers->get('Content-Disposition')
        );
        $this->assertStringContainsString('%PDF-1.4', $download->streamedContent());

        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($orgB, $user, OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($orgB))
            ->get('/api/v1/evidence/'.$evidenceId.'/download')
            ->assertNotFound();
    }

    public function test_preview_serves_the_file_inline(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $evidenceId = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'rate_confirmation',
                'file' => $this->pdfFile('ratecon.pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        // A viewer cannot upload, but can preview: switch accounts.
        [$viewer] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/evidence/'.$evidenceId.'/download?preview=1');

        $preview->assertOk();
        $this->assertStringContainsString(
            'inline; filename=ratecon.pdf',
            $preview->headers->get('Content-Disposition')
        );
    }

    public function test_verification_status_is_permission_gated(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $evidenceId = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertCreated()
            ->json('data.id');

        [$dispatcher] = $this->actingAsMemberOf(OrganizationRole::Dispatcher, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/evidence/'.$evidenceId.'/verification', [
                'verification_status' => 'verified',
            ])
            ->assertForbidden();

        [$billing] = $this->actingAsMemberOf(OrganizationRole::BillingStaff, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/evidence/'.$evidenceId.'/verification', [
                'verification_status' => 'verified',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_deleting_evidence_removes_the_row_and_the_file(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $evidenceId = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertCreated()
            ->json('data.id');

        $key = EvidenceFile::findOrFail($evidenceId)->storage_key;
        Storage::disk('private')->assertExists($key);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/evidence/'.$evidenceId)
            ->assertOk()
            ->assertJsonPath('message', 'Evidence deleted.');

        $this->assertDatabaseMissing('evidence_files', ['id' => $evidenceId]);
        Storage::disk('private')->assertMissing($key);
    }

    public function test_a_dispatcher_cannot_delete_evidence(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $evidenceId = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertCreated()
            ->json('data.id');

        [$dispatcher] = $this->actingAsMemberOf(OrganizationRole::Dispatcher, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/evidence/'.$evidenceId)
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_upload_evidence(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertForbidden();
    }

    public function test_library_filters_by_category_and_verification_status(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'bill_of_lading',
                'file' => $this->pdfFile('bol.pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile('receipt.pdf'),
            ])
            ->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/evidence/'.$first.'/verification', [
                'verification_status' => 'verified',
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence?document_category=receipt')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence?verification_status=verified')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence?shipment_id='.$shipment->getKey())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_download_of_a_missing_file_returns_404(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $evidenceId = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'receipt',
                'file' => $this->pdfFile(),
            ])
            ->assertCreated()
            ->json('data.id');

        $evidence = EvidenceFile::findOrFail($evidenceId);
        Storage::disk('private')->delete($evidence->storage_key);

        $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/evidence/'.$evidenceId.'/download')
            ->assertNotFound();
    }
}
