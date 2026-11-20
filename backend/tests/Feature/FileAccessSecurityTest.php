<?php

namespace Tests\Feature;

use App\GeneratedDocumentStatus;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * File access security review (v1.1.3).
 *
 * Evidence and claim package downloads must be authenticated,
 * tenant-scoped, and policy-checked; storage keys read back from
 * the database must not be able to escape their prefix (path
 * traversal); uploads must reject disallowed types by content,
 * not just by extension. The code review findings are in
 * docs/SECURITY.md.
 */
class FileAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_download_returns_401(): void
    {
        // No Sanctum::actingAs here: the request must arrive with
        // no token at all, so the auth middleware answers 401
        // before any organization or file check runs.
        $organization = $this->createOrganization();
        $user = User::factory()->create();
        $this->addMember($organization, $user, OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $evidence = $this->createEvidence($organization, $shipment);

        $this->getJson('/api/v1/evidence/'.$evidence->getKey().'/download')->assertUnauthorized();
        $this->getJson('/api/v1/evidence/'.$evidence->getKey().'/preview')->assertUnauthorized();
    }

    public function test_cross_tenant_download_returns_404(): void
    {
        [$userA, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Carriers Inc.']);
        $shipmentB = $this->createShipment($orgB);
        $evidenceB = $this->createEvidence($orgB, $shipmentB);

        Storage::fake('private');
        Storage::disk('private')->put($evidenceB->storage_key, 'fake-pdf-bytes');

        $this->withHeaders($this->orgHeaders($orgA))
            ->getJson('/api/v1/evidence/'.$evidenceB->getKey().'/download')
            ->assertNotFound();
    }

    public function test_a_storage_key_that_escapes_its_prefix_is_refused(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        Storage::fake('private');

        // A traversal key must be refused even though the file it
        // points at exists on the disk.
        Storage::disk('private')->put('secret.txt', 'do-not-serve-this');
        $traversal = $this->createEvidence($organization, $shipment, [
            'storage_key' => 'evidence/../../secret.txt',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$traversal->getKey().'/download')
            ->assertNotFound();
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$traversal->getKey().'/preview')
            ->assertNotFound();

        // A key from another prefix is refused the same way.
        $wrongPrefix = $this->createEvidence($organization, $shipment, [
            'storage_key' => 'packages/'.$organization->getKey().'/'.$shipment->getKey().'/x.pdf',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$wrongPrefix->getKey().'/download')
            ->assertNotFound();

        // And a traversal key on a generated document.
        $claim = $this->createClaim($organization, $shipment);
        $document = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'storage_key' => 'packages/../../secret.txt',
            'disk' => 'private',
            'file_size' => 10,
            'status' => GeneratedDocumentStatus::Ready,
            'generated_at' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/documents/'.$document->getKey().'/download')
            ->assertNotFound();
    }

    public function test_upload_rejects_a_disallowed_type_by_content(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        Storage::fake('private');

        // An executable renamed to .pdf: the mimes rule checks
        // content, so the extension alone does not pass.
        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/evidence', [
            'shipment_id' => $shipment->getKey(),
            'document_category' => 'bill_of_lading',
            'file' => UploadedFile::fake()->create('malware.pdf', 10, 'application/x-msdownload'),
        ])->assertUnprocessable();

        // A plain executable, no disguise.
        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/evidence', [
            'shipment_id' => $shipment->getKey(),
            'document_category' => 'bill_of_lading',
            'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ])->assertUnprocessable();

        $this->assertSame(0, $organization->evidenceFiles()->count());
    }

    public function test_a_viewer_with_view_evidence_can_download(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);
        $evidence = $this->createEvidence($organization, $shipment);

        Storage::fake('private');
        Storage::disk('private')->put($evidence->storage_key, 'fake-pdf-bytes');

        // Viewers hold view-evidence, so both work.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$evidence->getKey())
            ->assertOk();
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence/'.$evidence->getKey().'/download')
            ->assertOk();
    }
}
