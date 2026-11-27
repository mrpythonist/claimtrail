<?php

namespace Tests\Feature;

use App\Mail\DriverEvidenceLinkMail;
use App\Models\CommunicationChannel;
use App\Models\DriverEvidenceLink;
use App\Models\EvidenceFile;
use App\Models\Notification;
use App\NotificationType;
use App\OrganizationRole;
use App\Services\CommunicationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Communication integrations (v1.3.3).
 *
 * Slack, Teams, and mock channels behind a provider
 * adapter, with delivery history; driver evidence
 * submission links that are signed, scoped, expiring,
 * and single-use by default; and email workflows for
 * the link email and the notification emails. No live
 * delivery exists anywhere: the mock records locally
 * and Slack and Teams are schema-only, so every send is
 * logged, never sent, and the delivery history says so.
 */
class CommunicationIntegrationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Mail::fake();
    }

    protected function createMockChannel($organization, array $overrides = []): array
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', array_merge([
                'provider' => 'mock',
                'name' => 'Mock Channel',
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    protected function createShipmentLink($organization, $shipment, array $overrides = []): array
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links', array_merge([
                'shipment_id' => (string) $shipment->getKey(),
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    public function test_providers_catalogue_lists_mock_slack_and_teams_as_not_live(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $providers = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/comms/providers')
            ->assertOk()
            ->json('data');

        $this->assertSame(['mock', 'slack', 'teams'], array_column($providers, 'name'));

        foreach ($providers as $provider) {
            $this->assertFalse($provider['live_available']);
        }

        $slack = collect($providers)->firstWhere('name', 'slack');
        $this->assertTrue($slack['webhook_url_required']);
        $this->assertStringContainsString('hooks.slack.com', $slack['webhook_url_hint']);
    }

    public function test_mock_channel_can_be_created_and_webhook_url_is_never_returned(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $channel = $this->createMockChannel($organization);

        $this->assertSame('mock', $channel['provider']);
        $this->assertSame('active', $channel['status']);
        $this->assertFalse($channel['has_webhook_url']);
        $this->assertArrayNotHasKey('webhook_url', $channel);

        $stored = CommunicationChannel::first();
        $this->assertNull($stored->webhook_url);
    }

    public function test_slack_channel_needs_a_well_formed_webhook_url(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'slack',
                'name' => 'Slack',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'slack',
                'name' => 'Slack',
                'webhook_url' => 'https://example.com/not-slack',
            ])
            ->assertUnprocessable();

        $channel = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'slack',
                'name' => 'Slack',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($channel['has_webhook_url']);
        $this->assertArrayNotHasKey('webhook_url', $channel);
    }

    public function test_teams_channel_needs_a_well_formed_webhook_url(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'teams',
                'name' => 'Teams',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'teams',
                'name' => 'Teams',
                'webhook_url' => 'https://outlook.office.com/webhook/abc/IncomingWebhook/def/123',
            ])
            ->assertCreated();
    }

    public function test_unknown_provider_is_refused(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'discord',
                'name' => 'Discord',
            ])
            ->assertUnprocessable();
    }

    public function test_viewer_cannot_create_a_channel(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels', [
                'provider' => 'mock',
                'name' => 'Mock Channel',
            ])
            ->assertForbidden();
    }

    public function test_disabled_channel_test_is_refused_and_records_nothing(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $channel = $this->createMockChannel($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/comms/channels/'.$channel['id'], ['status' => 'disabled'])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels/'.$channel['id'].'/test')
            ->assertUnprocessable()
            ->assertJsonPath('errors.channel.0', 'Channel "Mock Channel" is disabled.');

        // The refused test recorded no delivery and left
        // last_tested_at alone.
        $this->assertDatabaseCount('communication_deliveries', 0);
        $this->assertDatabaseHas('communication_channels', [
            'id' => $channel['id'],
            'last_tested_at' => null,
        ]);
    }

    public function test_channel_test_records_a_logged_delivery_and_notifies(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);

        $channel = $this->createMockChannel($organization);

        $result = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels/'.$channel['id'].'/test')
            ->assertOk()
            ->json('data');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['live_available']);
        $this->assertNotNull($result['delivery_id']);

        $deliveries = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/comms/deliveries')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $deliveries);
        $this->assertSame($result['delivery_id'], $deliveries[0]['id']);
        $this->assertSame('test', $deliveries[0]['event']);
        $this->assertSame('logged', $deliveries[0]['status']);
        $this->assertStringContainsString('no live delivery', $deliveries[0]['detail']);
        $this->assertTrue($deliveries[0]['payload']['mock']);

        // The dispatcher (not the actor) got the tested notification.
        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $dispatcher->getKey(),
            'type' => NotificationType::CommunicationChannelTested->value,
        ]);
        // The actor is excluded from their own notification.
        $this->assertDatabaseMissing('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'type' => NotificationType::CommunicationChannelTested->value,
        ]);
    }

    public function test_slack_test_with_a_good_url_is_well_formed_but_not_live(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $channel = $this->createMockChannel($organization, [
            'provider' => 'slack',
            'name' => 'Slack',
            'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
        ]);

        $result = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/channels/'.$channel['id'].'/test')
            ->assertOk()
            ->json('data');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['live_available']);
        $this->assertStringContainsString('no Slack workspace', $result['message']);

        $delivery = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/comms/deliveries')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('logged', $delivery['status']);
        $this->assertSame('section', $delivery['payload']['blocks'][0]['type']);
    }

    public function test_channel_send_goes_only_to_channels_wanting_the_event(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $wants = $this->createMockChannel($organization, ['name' => 'Wants It']);
        $this->createMockChannel($organization, [
            'name' => 'Does Not Want It',
            'settings' => ['driver_link_used' => false],
        ]);

        /** @var CommunicationService $comms */
        $comms = app(CommunicationService::class);
        $deliveries = $comms->sendEvent($organization, 'driver_link_used', 'Evidence received', 'A driver submitted evidence.', [], $owner);

        $this->assertCount(1, $deliveries);
        $this->assertSame($wants['id'], $deliveries[0]['communication_channel_id']);
        $this->assertSame('logged', $deliveries[0]['status']);
    }

    public function test_disabled_channel_never_receives_a_message(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $channel = $this->createMockChannel($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/comms/channels/'.$channel['id'], ['status' => 'disabled'])
            ->assertOk();

        /** @var CommunicationService $comms */
        $comms = app(CommunicationService::class);
        $deliveries = $comms->sendEvent($organization, 'claim_created', 'Claim created', 'A claim was created.', [], $owner);

        $this->assertCount(0, $deliveries);
    }

    public function test_driver_link_creation_returns_the_token_once_and_never_stores_it(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $link = $this->createShipmentLink($organization, $shipment, [
            'driver_name' => 'Sam Driver',
            'driver_email' => 'sam@example.com',
        ]);

        $this->assertNotEmpty($link['token']);
        $this->assertNotEmpty($link['submit_url']);
        $this->assertSame('usable', $link['state']);
        $this->assertSame(1, $link['uses_left']);
        $this->assertStringContainsString($link['token'], $link['submit_url']);

        $stored = DriverEvidenceLink::first();
        $this->assertSame(hash('sha256', $link['token']), $stored->token_hash);
        $this->assertNotSame($link['token'], $stored->token_hash);

        // The token is only in the creation response: a later read has none.
        $read = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/comms/driver-links/'.$link['id'])
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('token', $read);
        $this->assertArrayNotHasKey('submit_url', $read);

        Mail::assertQueued(DriverEvidenceLinkMail::class, function ($mail) {
            return $mail->hasTo('sam@example.com');
        });
    }

    public function test_driver_link_needs_a_shipment_or_a_claim(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links', [])
            ->assertUnprocessable();
    }

    public function test_driver_link_for_another_organizations_shipment_is_refused(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        [$otherUser, $otherOrganization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $otherShipment = $this->createShipment($otherOrganization);

        // Re-authenticate as the first org's owner.
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links', [
                'shipment_id' => (string) $otherShipment->getKey(),
            ])
            ->assertUnprocessable();
    }

    public function test_claim_only_link_accepts_a_submission_filed_against_the_claims_shipment(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $link = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links', [
                'claim_id' => $claim->getKey(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNull($link['shipment_id']);
        $this->assertSame($claim->claim_reference, $link['claim_reference']);

        $submission = $this->post('/api/v1/driver-evidence/'.$link['token'].'/submit', [
            'driver_name' => 'Sam Driver',
            'document_category' => 'proof_of_delivery',
            'file' => UploadedFile::fake()->createWithContent('pod.pdf', "%PDF-1.4\n".str_repeat('0', 1024)),
        ])
            ->assertCreated()
            ->json('data');

        // The evidence is filed against the claim's
        // shipment, and the submission keeps the claim.
        $this->assertSame($shipment->getKey(), $submission['shipment_id']);
        $this->assertSame($claim->getKey(), $submission['claim_id']);

        $evidence = EvidenceFile::find($submission['evidence_file_id']);
        $this->assertNotNull($evidence);
        $this->assertSame($shipment->getKey(), $evidence->shipment_id);
    }

    public function test_driver_can_submit_evidence_through_a_link_and_it_is_single_use(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $link = $this->createShipmentLink($organization, $shipment);

        // A fake file with real content: UploadedFile::fake()->create
        // makes an empty temp file with a faked size, and the
        // service verifies the stored size against the upload,
        // so the file needs bytes on disk.
        $file = UploadedFile::fake()->createWithContent('pod.pdf', "%PDF-1.4\n".str_repeat('0', 1024));

        $submission = $this->post('/api/v1/driver-evidence/'.$link['token'].'/submit', [
            'driver_name' => 'Sam Driver',
            'notes' => 'Delivered at the dock.',
            'document_category' => 'proof_of_delivery',
            'file' => $file,
        ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Sam Driver', $submission['driver_name']);
        $this->assertNotNull($submission['evidence_file_id']);

        $evidence = EvidenceFile::find($submission['evidence_file_id']);
        $this->assertNotNull($evidence);
        $this->assertNull($evidence->uploaded_by);
        $this->assertSame('pod.pdf', $evidence->original_filename);
        $this->assertStringStartsWith('driver-evidence/', $evidence->storage_key);
        Storage::disk('private')->assertExists($evidence->storage_key);

        // The link is spent: a second submission is refused.
        $second = $this->post('/api/v1/driver-evidence/'.$link['token'].'/submit', [
            'document_category' => 'proof_of_delivery',
            'file' => UploadedFile::fake()->createWithContent('pod2.pdf', "%PDF-1.4\n".str_repeat('0', 1024)),
        ]);
        $second->assertNotFound();

        // And the public page agrees.
        $this->getJson('/api/v1/driver-evidence/'.$link['token'])->assertNotFound();

        // The dispatcher was notified the link was used.
        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $dispatcher->getKey(),
            'type' => NotificationType::DriverLinkUsed->value,
        ]);
    }

    public function test_driver_link_page_shows_only_what_the_link_covers(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, ['internal_reference' => 'CT-DRIVER-1']);

        $link = $this->createShipmentLink($organization, $shipment);

        $public = $this->getJson('/api/v1/driver-evidence/'.$link['token'])
            ->assertOk()
            ->json('data');

        $this->assertSame('CT-DRIVER-1', $public['shipment_reference']);
        $this->assertSame($organization->name, $public['organization_name']);
        $this->assertArrayNotHasKey('organization_id', $public);
        $this->assertArrayNotHasKey('shipment_id', $public);
    }

    public function test_unknown_driver_token_is_404(): void
    {
        $this->getJson('/api/v1/driver-evidence/'.str_repeat('a', 48))->assertNotFound();
        $this->post('/api/v1/driver-evidence/'.str_repeat('a', 48).'/submit', [
            'document_category' => 'proof_of_delivery',
            'file' => UploadedFile::fake()->createWithContent('pod.pdf', "%PDF-1.4\n".str_repeat('0', 1024)),
        ])->assertNotFound();
    }

    public function test_revoked_driver_link_stops_working_at_once(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $link = $this->createShipmentLink($organization, $shipment);

        $revoked = $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/comms/driver-links/'.$link['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame('revoked', $revoked['state']);

        $this->getJson('/api/v1/driver-evidence/'.$link['token'])->assertNotFound();
    }

    public function test_expired_driver_link_is_refused_and_expire_command_notifies(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $link = $this->createShipmentLink($organization, $shipment, ['ttl_hours' => 1]);

        // Move the expiry into the past.
        DriverEvidenceLink::whereKey($link['id'])->update(['expires_at' => now()->subHour()]);

        $this->getJson('/api/v1/driver-evidence/'.$link['token'])->assertNotFound();

        $this->artisan('comms:expire-links')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $dispatcher->getKey(),
            'type' => NotificationType::DriverLinkExpired->value,
        ]);

        // A second run does not notify again.
        $before = Notification::where('type', NotificationType::DriverLinkExpired->value)->count();
        $this->artisan('comms:expire-links')->assertExitCode(0);
        $this->assertSame($before, Notification::where('type', NotificationType::DriverLinkExpired->value)->count());
    }

    public function test_resend_rotates_the_token_and_the_old_url_stops_working(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $link = $this->createShipmentLink($organization, $shipment, [
            'driver_email' => 'sam@example.com',
        ]);
        $oldToken = $link['token'];

        $resent = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links/'.$link['id'].'/resend')
            ->assertOk()
            ->json('data');

        $this->assertNotSame($oldToken, $resent['token']);
        $this->assertNotEmpty($resent['token']);

        $this->getJson('/api/v1/driver-evidence/'.$oldToken)->assertNotFound();
        $this->getJson('/api/v1/driver-evidence/'.$resent['token'])->assertOk();

        Mail::assertQueued(DriverEvidenceLinkMail::class, 2);
    }

    public function test_driver_link_email_failure_is_caught_and_notified_not_fatal(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $dispatcher = \App\Models\User::factory()->create();
        $this->addMember($organization, $dispatcher, OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        // No queue: notification emails never run, so the only
        // live mail call is the link email itself.
        \Illuminate\Support\Facades\Queue::fake();
        Mail::shouldReceive('to')->andThrow(new \Exception('SMTP down'));

        $link = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/comms/driver-links', [
                'shipment_id' => (string) $shipment->getKey(),
                'driver_email' => 'sam@example.com',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('usable', $link['state']);

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $dispatcher->getKey(),
            'type' => NotificationType::CommunicationEmailFailed->value,
        ]);
    }

    public function test_cross_tenant_channel_read_is_404(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        [$otherUser, $otherOrganization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $channel = $this->createMockChannel($otherOrganization);

        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/comms/channels/'.$channel['id'])
            ->assertNotFound();
    }

    public function test_comms_list_command_lists_channels(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createMockChannel($organization);

        $this->artisan('comms:list')
            ->expectsOutputToContain('Mock Channel')
            ->assertExitCode(0);
    }

    public function test_comms_test_command_tests_a_channel(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $channel = $this->createMockChannel($organization);

        $this->artisan('comms:test', ['channel' => $channel['id']])
            ->assertExitCode(0);

        $this->assertDatabaseHas('communication_deliveries', [
            'communication_channel_id' => $channel['id'],
            'event' => 'test',
            'status' => 'logged',
        ]);
    }
}
