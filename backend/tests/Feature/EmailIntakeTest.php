<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEmail;
use App\Models\InboundEmail;
use App\Models\InboundEmailAddress;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Email intake (v1.3.0).
 *
 * Organization-specific inbound addresses, the HMAC-signed
 * inbound webhook, attachment extraction under the evidence
 * limits, shipment reference extraction, automatic matching,
 * manual matching, and the processing history. No real email
 * provider is involved: the webhook takes a normalized JSON
 * payload, signed with the shared secret.
 */
class EmailIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'test-intake-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['email_intake.webhook_secret' => $this->secret]);
        Storage::fake('private');
    }

    /**
     * Sign a raw payload the way a provider would.
     */
    protected function sign(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Deliver one normalized inbound email to the webhook.
     */
    protected function deliver(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature ??= $this->sign($body);

        // call() does not merge withHeaders: headers go in the
        // $server argument as HTTP_ vars.
        return $this->call('POST', '/api/v1/email-intake/inbound', [], [], [], [
            'HTTP_X_EMAIL_INTAKE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * A valid payload for the given address, with one PDF
     * attachment by default.
     */
    protected function payload(string $to, array $overrides = []): array
    {
        return array_merge([
            'from' => 'broker@example.com',
            'to' => $to,
            'subject' => 'Detention paperwork for PLT-1001',
            'text_body' => 'Please find the detention proof for load PLT-1001 attached.',
            'message_id' => '<msg-'.uniqid().'@example.com>',
            'attachments' => [
                [
                    'filename' => 'detention-proof.pdf',
                    'mime_type' => 'application/pdf',
                    'content_base64' => base64_encode("%PDF-1.4\nfake pdf bytes"),
                ],
            ],
        ], $overrides);
    }

    public function test_organization_creation_provisions_an_inbound_address(): void
    {
        [$user] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Fresh Haul LLC',
        ]);

        $response->assertCreated();
        $orgId = $response->json('data.id');

        $this->assertDatabaseHas('inbound_email_addresses', [
            'organization_id' => $orgId,
            'active' => true,
        ]);

        $address = InboundEmailAddress::withoutGlobalScope('organization')
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $this->assertStringStartsWith('fresh-haul-llc-', $address->local_part);
        $this->assertSame($address->local_part.'@'.config('email_intake.domain'), $address->fullAddress());
    }

    public function test_address_list_backfills_for_older_organizations(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        // Organizations created directly (not through the API)
        // have no address until the list provisions one.
        $this->assertDatabaseCount('inbound_email_addresses', 0);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/addresses');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.active', true);

        $this->assertDatabaseCount('inbound_email_addresses', 1);
    }

    public function test_address_create_regenerate_and_deactivate(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        // Listing first backfills the default address.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/email-intake/addresses', ['label' => 'Dispatch inbox'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Dispatch inbox');

        // The list backfilled the default address, so two exist.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $id = $created->json('data.id');
        $oldLocalPart = $created->json('data.local_part');

        $regenerated = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/addresses/{$id}/regenerate")
            ->assertOk();
        $this->assertNotSame($oldLocalPart, $regenerated->json('data.local_part'));

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/addresses/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.active', false);

        // The last active address cannot be deactivated.
        $remaining = InboundEmailAddress::forOrganization($organization)
            ->where('active', true)->firstOrFail();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/addresses/{$remaining->getKey()}/deactivate")
            ->assertUnprocessable();
    }

    public function test_viewer_cannot_manage_addresses(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/email-intake/addresses', ['label' => 'Nope'])
            ->assertForbidden();

        // Listing is open to every member.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/addresses')
            ->assertOk();
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $address = $this->addressFor($organization);

        $this->deliver($this->payload($address->fullAddress()), 'sha256=wrong')
            ->assertUnauthorized();

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_webhook_rejects_an_unknown_recipient_without_leaking_addresses(): void
    {
        $this->deliver($this->payload('nobody-here-123456@'.config('email_intake.domain')))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The recipient address could not be accepted.');

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_valid_email_auto_matches_and_stores_attachment_as_evidence(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $response = $this->deliver($this->payload($address->fullAddress()));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.matched_shipment_id', $shipment->getKey())
            ->assertJsonPath('data.duplicate', false);

        $email = InboundEmail::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('matched', $email->status);
        $this->assertSame('exact reference match', $email->match_reason);
        $this->assertSame('high', $email->match_confidence);
        $this->assertSame('PLT-1001', $email->extracted_references[0]['value']);
        $this->assertSame((string) $shipment->getKey(), $email->extracted_references[0]['matched_shipment_id']);

        $this->assertDatabaseHas('inbound_email_attachments', [
            'inbound_email_id' => $email->getKey(),
            'filename' => 'detention-proof.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->assertDatabaseHas('evidence_files', [
            'organization_id' => $organization->getKey(),
            'shipment_id' => $shipment->getKey(),
            'document_category' => 'other',
            'original_filename' => 'detention-proof.pdf',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'email_intake.auto_matched',
        ]);
    }

    public function test_duplicate_message_id_is_not_processed_twice(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $payload = $this->payload($address->fullAddress(), ['message_id' => '<dup-1@example.com>']);

        $this->deliver($payload)->assertCreated();
        $this->deliver($payload)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('evidence_files', 1);
    }

    public function test_email_with_no_reference_is_unmatched_then_manually_matched(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-2002']);
        $address = $this->addressFor($organization);

        $response = $this->deliver($this->payload($address->fullAddress(), [
            'subject' => 'General paperwork',
            'text_body' => 'Hello, please see the attached rate confirmation.',
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'unmatched');
        $emailId = $response->json('data.id');

        $email = InboundEmail::withoutGlobalScope('organization')->findOrFail($emailId);
        $this->assertSame('no shipment reference found', $email->match_reason);

        $matched = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/match", [
                'shipment_id' => $shipment->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.matched_shipment_id', $shipment->getKey());

        $this->assertDatabaseHas('evidence_files', [
            'shipment_id' => $shipment->getKey(),
            'original_filename' => 'detention-proof.pdf',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'email_intake.manually_matched',
        ]);
    }

    public function test_email_matching_two_shipments_stays_unmatched(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'PLT-3001']);
        $this->createShipment($organization, ['internal_reference' => 'PLT-3002']);
        $address = $this->addressFor($organization);

        $response = $this->deliver($this->payload($address->fullAddress(), [
            'subject' => 'Loads PLT-3001 and PLT-3002',
            'text_body' => 'Paperwork for PLT-3001 and PLT-3002.',
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'unmatched');

        $email = InboundEmail::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('multiple shipments match the extracted references', $email->match_reason);
        $this->assertSame('low', $email->match_confidence);
        $this->assertNull($email->matched_shipment_id);
    }

    public function test_external_load_reference_also_matches(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, [
            'internal_reference' => 'CT-INT-1',
            'external_load_reference' => 'EXT-7788',
        ]);
        $address = $this->addressFor($organization);

        $this->deliver($this->payload($address->fullAddress(), [
            'subject' => 'POD for EXT-7788',
            'text_body' => 'Proof of delivery for load EXT-7788.',
            'attachments' => [],
        ]))->assertCreated()->assertJsonPath('data.status', 'matched')
            ->assertJsonPath('data.matched_shipment_id', $shipment->getKey());
    }

    public function test_disallowed_attachment_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $address = $this->addressFor($organization);

        $this->deliver($this->payload($address->fullAddress(), [
            'attachments' => [[
                'filename' => 'run-me.exe',
                'mime_type' => 'application/octet-stream',
                'content_base64' => base64_encode('MZ fake binary'),
            ]],
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_mime_mismatch_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $address = $this->addressFor($organization);

        $this->deliver($this->payload($address->fullAddress(), [
            'attachments' => [[
                'filename' => 'not-a-pdf.pdf',
                'mime_type' => 'image/png',
                'content_base64' => base64_encode('fake png bytes'),
            ]],
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_unmatch_and_dismiss_flow(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $emailId = $this->deliver($this->payload($address->fullAddress()))
            ->assertCreated()->json('data.id');

        // Dismissing a matched email is refused.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/dismiss", ['reason' => 'Spam'])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/unmatch", ['reason' => 'Wrong load'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unmatched')
            ->assertJsonPath('data.match_reason', 'Wrong load');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/dismiss", ['reason' => 'Not a shipment email'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.dismissed_reason', 'Not a shipment email');

        // A dismissed email cannot be matched.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/match", [
                'shipment_id' => $shipment->getKey(),
            ])
            ->assertUnprocessable();
    }

    public function test_email_list_filters_and_tenant_isolation(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $matchedId = $this->deliver($this->payload($address->fullAddress()))->json('data.id');
        $unmatchedId = $this->deliver($this->payload($address->fullAddress(), [
            'subject' => 'No reference here',
            'text_body' => 'Just saying hello.',
            'message_id' => '<other-1@example.com>',
        ]))->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/emails?status=matched')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchedId);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/email-intake/emails?matched=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unmatchedId);

        // A member of another organization sees 404, not the email.
        [$otherUser] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $otherOrg = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($otherOrg, $otherUser, OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson("/api/v1/email-intake/emails/{$matchedId}")
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($otherOrg))
            ->getJson('/api/v1/email-intake/emails')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_viewer_cannot_match_or_dismiss(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $emailId = $this->deliver($this->payload($address->fullAddress(), [
            'subject' => 'No reference here',
            'text_body' => 'Just saying hello.',
        ]))->json('data.id');

        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/match", [
                'shipment_id' => $shipment->getKey(),
            ])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/email-intake/emails/{$emailId}/dismiss", ['reason' => 'Spam'])
            ->assertForbidden();
    }

    public function test_process_job_reprocesses_a_received_email(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $email = InboundEmail::create([
            'organization_id' => $organization->getKey(),
            'inbound_email_address_id' => $address->getKey(),
            'from' => 'broker@example.com',
            'to' => $address->fullAddress(),
            'subject' => 'Detention for PLT-1001',
            'text_body' => 'See PLT-1001.',
            'message_id' => '<job-1@example.com>',
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        (new ProcessInboundEmail($email->getKey()))->handle(app(\App\Services\InboundEmailProcessor::class));

        $this->assertSame('matched', $email->fresh()->status);
        $this->assertSame((string) $shipment->getKey(), (string) $email->fresh()->matched_shipment_id);

        // A second run is skipped: the job only processes received
        // or failed emails.
        (new ProcessInboundEmail($email->getKey()))->handle(app(\App\Services\InboundEmailProcessor::class));
        $this->assertSame('matched', $email->fresh()->status);
    }

    public function test_email_intake_list_command(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'PLT-1001']);
        $address = $this->addressFor($organization);

        $this->deliver($this->payload($address->fullAddress()));

        $this->artisan('email-intake:list', ['--status' => 'matched'])
            ->expectsOutputToContain('PLT-1001')
            ->assertExitCode(0);

        $this->artisan('email-intake:list', ['--status' => 'bogus'])
            ->assertExitCode(1);

        $this->artisan('email-intake:list', [
            '--status' => 'matched',
            '--organization' => $organization->slug,
        ])->assertExitCode(0);
    }

    /**
     * The organization's default address, created on demand.
     */
    protected function addressFor($organization): InboundEmailAddress
    {
        return app(\App\Services\EmailIntakeService::class)->ensureDefaultAddress($organization);
    }
}
