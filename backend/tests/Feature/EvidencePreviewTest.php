<?php

namespace Tests\Feature;

use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidencePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function pdfFile(string $name = 'file.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4 fake pdf content');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    protected function imageFile(string $name = 'photo.jpg'): UploadedFile
    {
        // A minimal 1x1 JPEG: the upload validator checks the real
        // content type, so the bytes must be a genuine JPEG.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AvwH/2Q==');
        $path = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    protected function textFile(string $name = 'notes.txt'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'plain text notes');

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    protected function uploadEvidence($organization, $shipment, UploadedFile $file, string $category = 'proof_of_delivery'): string
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => $category,
                'file' => $file,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_preview_serves_a_pdf_inline_with_the_right_content_type(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $evidenceId = $this->uploadEvidence($organization, $shipment, $this->pdfFile('pod.pdf'));

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/evidence/'.$evidenceId.'/preview');

        $preview->assertOk();
        $this->assertStringContainsString('inline; filename=pod.pdf', $preview->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $preview->headers->get('Content-Type'));
        $this->assertStringContainsString('%PDF-1.4', $preview->streamedContent());

        // The resource flags the file as previewable.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$evidenceId)
            ->assertOk()
            ->assertJsonPath('data.previewable', true);
    }

    public function test_preview_serves_an_image_inline(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $evidenceId = $this->uploadEvidence($organization, $shipment, $this->imageFile('photo.jpg'), 'driver_photo');

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/evidence/'.$evidenceId.'/preview');

        $preview->assertOk();
        $this->assertStringContainsString('inline; filename=photo.jpg', $preview->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $preview->headers->get('Content-Type'));
    }

    public function test_preview_rejects_a_non_previewable_type_with_a_fallback_hint(): void
    {
        Storage::fake('private');
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $evidenceId = $this->uploadEvidence($organization, $shipment, $this->textFile('notes.txt'), 'other');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$evidenceId.'/preview')
            ->assertUnprocessable()
            ->assertJsonPath('previewable', false)
            ->assertJsonPath('mime_type', 'text/plain');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$evidenceId)
            ->assertOk()
            ->assertJsonPath('data.previewable', false);
    }

    public function test_preview_is_tenant_scoped_and_requires_a_viewer(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $evidenceId = $this->uploadEvidence($organization, $shipment, $this->pdfFile('pod.pdf'));

        // A viewer can preview but cannot upload.
        [$viewer] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/evidence/'.$evidenceId.'/preview')
            ->assertOk();

        // Another organization cannot preview it at all.
        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($orgB, $viewer, OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($orgB))
            ->get('/api/v1/evidence/'.$evidenceId.'/preview')
            ->assertNotFound();
    }
}
