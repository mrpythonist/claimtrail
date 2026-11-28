<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Walk a claim to the given status through the API.
     */
    protected function walkClaimTo($claim, string $status, array $extra = []): void
    {
        $path = [
            'under_review' => ['under_review'],
            'ready_to_submit' => ['under_review', 'ready_to_submit'],
            'submitted' => ['under_review', 'ready_to_submit', 'submitted'],
        ][$status] ?? [];

        foreach ($path as $step) {
            $this->withHeaders($this->orgHeaders($claim->organization))
                ->postJson("/api/v1/claims/{$claim->getKey()}/transition", array_merge([
                    'to_status' => $step,
                ], $step === $status ? $extra : []))
                ->assertOk();
        }
    }

    /**
     * An organization with two members: the acting owner and a
     * second member who receives the notifications.
     *
     * @return array{User, \App\Models\Organization, User}
     */
    protected function orgWithTwoMembers(OrganizationRole $secondRole = OrganizationRole::Admin): array
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $second = User::factory()->create();
        $this->addMember($organization, $second, $secondRole);

        return [$owner, $organization, $second];
    }

    public function test_claim_created_notifies_other_members_but_not_the_actor(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'type' => 'claim.created',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->id,
            'type' => 'claim.created',
        ]);
    }

    public function test_transitions_notify_ready_submitted_accepted_and_rejected(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->walkClaimTo($claim, 'ready_to_submit');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.ready_for_review',
        ]);

        $this->walkClaimTo($claim, 'submitted');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.submitted',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.accepted',
        ]);

        // A second claim, rejected.
        $claim2 = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim2, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim2->getKey()}/transition", [
                'to_status' => 'rejected',
                'rejection_reason' => 'Missing rate confirmation.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.rejected',
        ]);
    }

    public function test_payment_recorded_notifies_members(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 250.00,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        $notification = Notification::where('user_id', $admin->id)
            ->where('type', 'payment.recorded')
            ->firstOrFail();

        $this->assertStringContainsString('250.00', $notification->body);
        $this->assertSame($claim->claim_reference, $notification->data['claim_reference']);
    }

    public function test_missing_evidence_notifies_once_per_claim_per_day(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);

        // A detention claim with a requirement the claim cannot meet.
        $organization->evidenceRequirements()->create([
            'event_category_id' => $this->systemCategory('detention')->getKey(),
            'document_category' => 'appointment_confirmation',
            'is_required' => true,
        ]);

        $event = $this->createEvent($shipment, [
            'event_category_id' => $this->systemCategory('detention')->getKey(),
        ]);
        $claim = $this->createClaim($organization, $shipment, [
            'shipment_event_id' => $event->getKey(),
        ]);

        // Into review: missing evidence notifies.
        $this->walkClaimTo($claim, 'under_review');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.evidence_missing',
        ]);

        // Back to evidence required and into review again the same
        // day: the dedupe key means no second notification.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", ['to_status' => 'evidence_required'])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", ['to_status' => 'under_review'])
            ->assertOk();

        $this->assertSame(1, Notification::where('user_id', $admin->id)
            ->where('type', 'claim.evidence_missing')
            ->count());
    }

    public function test_deadline_refresh_twice_creates_one_notification_per_deadline(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->subDay(),
            'timezone' => 'UTC',
            'status' => 'upcoming',
        ]);

        $this->artisan('claimtrail:refresh-deadline-statuses')->assertExitCode(0);
        $this->artisan('claimtrail:refresh-deadline-statuses')->assertExitCode(0);

        $this->assertSame(1, Notification::where('user_id', $admin->id)
            ->where('type', 'deadline.overdue')
            ->count());

        // The owner also gets one (the command has no actor to exclude).
        $this->assertSame(1, Notification::where('user_id', $owner->id)
            ->where('type', 'deadline.overdue')
            ->count());
    }

    public function test_due_soon_deadline_notifies_as_upcoming(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->addDays(3),
            'timezone' => 'UTC',
            'reminder_days_before' => [7],
            'status' => 'upcoming',
        ]);

        $this->artisan('claimtrail:refresh-deadline-statuses')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'deadline.upcoming',
        ]);
    }

    public function test_email_off_means_no_mail_is_queued(): void
    {
        Queue::fake();
        Mail::fake();

        [$owner, $organization, $admin] = $this->orgWithTwoMembers();

        NotificationPreference::create([
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'event_type' => 'claim.created',
            'in_app' => true,
            'email' => false,
        ]);

        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        // In-app row exists, but no email job was queued for the admin.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.created',
        ]);
        Queue::assertNotPushed(SendNotificationEmail::class);
        Mail::assertNothingSent();
    }

    public function test_in_app_off_means_no_row_but_email_still_goes_out(): void
    {
        Queue::fake();

        [$owner, $organization, $admin] = $this->orgWithTwoMembers();

        NotificationPreference::create([
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'event_type' => 'claim.created',
            'in_app' => false,
            'email' => true,
        ]);

        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id,
            'type' => 'claim.created',
        ]);
        Queue::assertPushed(SendNotificationEmail::class, 1);
    }

    public function test_notification_email_job_stamps_email_sent_at(): void
    {
        Mail::fake();

        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        // Sync queue in tests: the job ran inline and sent the mail.
        $notification = Notification::where('user_id', $admin->id)
            ->where('type', 'claim.created')
            ->firstOrFail();

        $this->assertNotNull($notification->email_sent_at);
        Mail::assertSentCount(1);
    }

    public function test_notifications_are_org_scoped_to_the_recipient(): void
    {
        [$owner, $organizationA, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organizationA);

        $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        // The admin is also a member of a second organization, where
        // they have no notifications.
        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->addMember($organizationB, $admin, OrganizationRole::Viewer);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.unread_count', 0);

        $this->withHeaders($this->orgHeaders($organizationA))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_mark_read_and_read_all_and_unread_count(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $notification = Notification::where('user_id', $admin->id)->firstOrFail();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read")
            ->assertOk()
            ->assertJsonPath('data.is_unread', false);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        // A second notification, cleared with read-all.
        Sanctum::actingAs($owner);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertCreated();

        Sanctum::actingAs($admin);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 1);
    }

    public function test_a_member_cannot_mark_another_members_notification_read(): void
    {
        [$owner, $organization, $admin] = $this->orgWithTwoMembers();
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated();

        $notification = Notification::where('user_id', $admin->id)->firstOrFail();

        // The owner tries to mark the admin's notification read.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/notifications/{$notification->getKey()}/read")
            ->assertNotFound();
    }

    public function test_preferences_can_be_listed_and_updated(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonCount(31, 'data')
            ->assertJsonPath('data.0.in_app', true)
            ->assertJsonPath('data.0.email', true);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['event_type' => 'claim.created', 'in_app' => true, 'email' => false],
                    ['event_type' => 'deadline.overdue', 'in_app' => false, 'email' => true],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'organization_id' => $organization->getKey(),
            'event_type' => 'claim.created',
            'in_app' => true,
            'email' => false,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    ['event_type' => 'not.a.type', 'in_app' => true, 'email' => true],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_inviting_an_existing_user_notifies_them_in_app_only(): void
    {
        Queue::fake();
        Mail::fake();

        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'dispatcher',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $invitee->id,
            'type' => 'organization.invitation',
        ]);

        // The invitation email went out, but no notification email job.
        Mail::assertQueuedCount(1);
        Queue::assertNotPushed(SendNotificationEmail::class);
    }

    public function test_inviting_an_unknown_email_creates_no_notification(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'nobody@example.com',
                'role' => 'dispatcher',
            ])
            ->assertCreated();

        $this->assertSame(0, Notification::count());
    }
}
