<?php

namespace Tests\Feature;

use App\Jobs\GenerateClaimPackage;
use App\Models\GeneratedDocument;
use App\OrganizationRole;
use App\Services\ClaimPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_html_contains_the_claim_reference_and_amounts(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CLM-2026-0042',
            'requested_amount' => 250.00,
            'internal_notes' => 'Do not share this note.',
        ]);
        $evidence = $this->createEvidence($organization, $shipment, [
            'original_filename' => 'rate-confirmation.pdf',
        ]);
        $claim->evidenceFiles()->attach($evidence->getKey());

        $html = app(ClaimPackageService::class)->renderHtml($claim->fresh());

        $this->assertStringContainsString('CLM-2026-0042', $html);
        $this->assertStringContainsString('250.00', $html);
        $this->assertStringContainsString('rate-confirmation.pdf', $html);
        $this->assertStringContainsString('not a guarantee', $html);
        $this->assertStringNotContainsString('Do not share this note.', $html);
    }

    public function test_internal_notes_are_included_only_when_asked(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'internal_notes' => 'Broker is slow to respond.',
        ]);

        $html = app(ClaimPackageService::class)->renderHtml($claim->fresh(), true);

        $this->assertStringContainsString('Broker is slow to respond.', $html);
    }

    public function test_generating_a_package_dispatches_the_job(): void
    {
        Queue::fake();
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $this->subscribe($organization, 'starter');
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [
                'include_notes' => false,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.includes_internal_notes', false);

        $this->assertArrayNotHasKey('storage_key', $response->json('data'));
        $this->assertArrayNotHasKey('disk', $response->json('data'));

        Queue::assertPushed(GenerateClaimPackage::class, 1);
    }

    public function test_the_job_renders_a_pdf_to_the_fake_disk(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CLM-2026-0042',
        ]);

        $document = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'status' => 'queued',
        ]);

        (new GenerateClaimPackage($document->getKey()))->handle(app(ClaimPackageService::class));

        $document->refresh();

        $this->assertSame('ready', $document->status->value);
        $this->assertNotNull($document->storage_key);
        $this->assertNotNull($document->generated_at);
        Storage::disk('private')->assertExists($document->storage_key);
        $this->assertStringStartsWith(
            '%PDF',
            Storage::disk('private')->get($document->storage_key)
        );
    }

    public function test_package_generation_runs_end_to_end_on_the_sync_queue(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertAccepted();

        $document = GeneratedDocument::firstOrFail();

        $this->assertSame('ready', $document->status->value);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/package")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->withHeaders($this->orgHeaders($organization))
            ->get("/api/v1/documents/{$document->getKey()}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_viewer_cannot_generate_a_package(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertForbidden();
    }

    public function test_download_of_a_queued_package_returns_conflict(): void
    {
        Queue::fake();
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $documentId = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertAccepted()
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->get("/api/v1/documents/{$documentId}/download")
            ->assertConflict();
    }

    public function test_a_user_cannot_download_another_organizations_package(): void
    {
        Storage::fake('private');
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organizationA, 'starter');
        $shipment = $this->createShipment($organizationA);
        $claim = $this->createClaim($organizationA, $shipment);

        $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertAccepted();

        $document = GeneratedDocument::firstOrFail();
        $this->assertSame('ready', $document->status->value);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organizationB, 'starter');

        $this->withHeaders($this->orgHeaders($organizationB))
            ->get("/api/v1/documents/{$document->getKey()}/download")
            ->assertNotFound();
    }
}
