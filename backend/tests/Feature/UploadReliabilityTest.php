<?php

namespace Tests\Feature;

use App\Models\EvidenceFile;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function pdfFile(string $name = 'bill-of-lading.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    protected function uploadPdf($organization, $shipment): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'bill_of_lading',
                'file' => $this->pdfFile(),
            ]);
    }

    public function test_upload_stores_the_sha256_checksum_of_the_uploaded_file(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $response = $this->uploadPdf($organization, $shipment);

        $response->assertCreated();
        $expected = hash('sha256', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        $response->assertJsonPath('data.checksum', $expected);
        $this->assertSame($expected, EvidenceFile::first()->checksum);
    }

    public function test_download_serves_the_file_when_the_checksum_matches(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $this->uploadPdf($organization, $shipment);
        $evidence = EvidenceFile::firstOrFail();

        $this->withHeaders($this->orgHeaders($organization))
            ->get("/api/v1/evidence/{$evidence->getKey()}/download")
            ->assertOk();
    }

    public function test_download_fails_when_the_stored_file_no_longer_matches_its_checksum(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $this->uploadPdf($organization, $shipment);
        $evidence = EvidenceFile::firstOrFail();

        Storage::disk('private')->put($evidence->storage_key, 'tampered bytes, not a pdf');

        $this->withHeaders($this->orgHeaders($organization))
            ->get("/api/v1/evidence/{$evidence->getKey()}/download")
            ->assertServerError();
    }

    public function test_download_skips_verification_for_pre_checksum_uploads(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $evidence = $this->createEvidence($organization, $shipment, ['checksum' => null]);
        Storage::disk('private')->put($evidence->storage_key, 'any bytes at all');

        $this->withHeaders($this->orgHeaders($organization))
            ->get("/api/v1/evidence/{$evidence->getKey()}/download")
            ->assertOk();
    }

    public function test_orphan_cleanup_deletes_unreferenced_objects_but_keeps_referenced_ones(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $this->uploadPdf($organization, $shipment);
        $referenced = EvidenceFile::firstOrFail()->storage_key;

        Storage::disk('private')->put('evidence/orphan.pdf', 'orphaned');
        $orphanPath = Storage::disk('private')->path('evidence/orphan.pdf');
        touch($orphanPath, now()->subHours(2)->getTimestamp());

        // A fresh orphan (younger than one hour) is left alone:
        // its upload may still be in flight.
        Storage::disk('private')->put('evidence/fresh-orphan.pdf', 'fresh');

        $this->artisan('claimtrail:cleanup-orphan-evidence')
            ->expectsOutput('1 orphaned evidence object(s) deleted.')
            ->assertExitCode(0);

        Storage::disk('private')->assertMissing('evidence/orphan.pdf');
        Storage::disk('private')->assertExists($referenced);
        Storage::disk('private')->assertExists('evidence/fresh-orphan.pdf');
    }

    public function test_orphan_cleanup_dry_run_deletes_nothing(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('evidence/orphan.pdf', 'orphaned');
        $orphanPath = Storage::disk('private')->path('evidence/orphan.pdf');
        touch($orphanPath, now()->subHours(2)->getTimestamp());

        $this->artisan('claimtrail:cleanup-orphan-evidence', ['--dry-run' => true])
            ->expectsOutput('Would delete: evidence/orphan.pdf')
            ->assertExitCode(0);

        Storage::disk('private')->assertExists('evidence/orphan.pdf');
    }
}
