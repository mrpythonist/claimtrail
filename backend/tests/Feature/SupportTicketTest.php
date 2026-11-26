<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\OrganizationRole;
use Tests\TestCase;

/**
 * Customer support tickets (v1.2.3).
 *
 * Any member may open a ticket and reply on it. Owner and admin
 * triage, change statuses, and write internal notes that only
 * they see. A staff reply moves an open or resolved ticket to in
 * progress; a customer reply on a resolved ticket reopens it; a
 * closed ticket takes no more messages. Ticket bodies and
 * messages are never written to the audit log.
 */
class SupportTicketTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_any_member_can_open_a_ticket(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'The claim page shows the wrong total',
                'category' => 'technical',
                'priority' => 'high',
                'message' => 'The total on claim CLM-2026-0001 does not match the events.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subject', 'The claim page shows the wrong total')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.requester.id', $user->getKey())
            ->assertJsonPath('data.messages.0.body', 'The total on claim CLM-2026-0001 does not match the events.');

        $this->assertDatabaseHas('support_tickets', [
            'organization_id' => $organization->getKey(),
            'subject' => 'The claim page shows the wrong total',
            'status' => 'open',
        ]);
    }

    public function test_ticket_creation_requires_subject_and_message(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/support/tickets', ['subject' => 'No message'])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'Bad category',
                'category' => 'not-a-category',
                'message' => 'Hello',
            ])
            ->assertUnprocessable();
    }

    public function test_staff_reply_moves_an_open_ticket_to_in_progress(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Billing question',
            'category' => 'billing',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        // The admin replies, as a different user.
        [$admin] = $this->actingAsMemberOf(OrganizationRole::Admin, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/messages", [
                'body' => 'Looking into it now.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ticket.status', 'in_progress');

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $requester->getKey(),
            'type' => 'support.ticket_reply',
        ]);
    }

    public function test_customer_reply_on_a_resolved_ticket_reopens_it(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Still broken',
            'category' => 'technical',
            'priority' => 'normal',
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/messages", [
                'body' => 'It happened again after the fix.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ticket.status', 'open');

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_internal_notes_are_only_visible_to_owner_and_admin(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Internal note test',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAsMemberOf(OrganizationRole::Admin, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/messages", [
                'body' => 'Check the billing log before replying.',
                'internal' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Check the billing log before replying.');

        // The admin sees the internal note.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/support/tickets/{$ticket->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.internal', true);

        // The requester does not.
        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/support/tickets/{$ticket->getKey()}")
            ->assertOk()
            ->assertJsonCount(0, 'data.messages');
    }

    public function test_internal_note_needs_manage_support(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'No internal notes for viewers',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/messages", [
                'body' => 'Trying to write an internal note.',
                'internal' => true,
            ])
            ->assertForbidden();
    }

    public function test_status_change_needs_manage_support_and_stamps_timestamps(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Status change test',
            'category' => 'account',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        // A viewer cannot change the status.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/status", [
                'status' => 'resolved',
            ])
            ->assertForbidden();

        $this->actingAsMemberOf(OrganizationRole::Admin, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/status", [
                'status' => 'resolved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh->resolved_at);
        $this->assertNull($fresh->closed_at);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/status", [
                'status' => 'closed',
            ])
            ->assertOk();

        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh->closed_at);
        // Closing keeps resolved_at when the ticket was resolved first.
        $this->assertNotNull($fresh->resolved_at);

        // Reopening clears both.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/status", [
                'status' => 'open',
            ])
            ->assertOk();

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->closed_at);
    }

    public function test_closed_ticket_takes_no_more_messages(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Closed',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/messages", [
                'body' => 'One more thing.',
            ])
            ->assertUnprocessable();
    }

    public function test_tickets_are_scoped_to_the_organization(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $ticket = SupportTicket::create([
            'organization_id' => $organization->getKey(),
            'requester_id' => $requester->getKey(),
            'subject' => 'Private ticket',
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $other = $this->createOrganization();
        $this->actingAsMemberOf(OrganizationRole::Owner, $other);

        $this->withHeaders($this->orgHeaders($other))
            ->getJson("/api/v1/support/tickets/{$ticket->getKey()}")
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/support/tickets')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_ticket_bodies_are_not_written_to_the_audit_log(): void
    {
        [$requester, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'Secret in the body',
                'category' => 'billing',
                'message' => 'My card number is 4242 and my password is hunter2.',
            ])
            ->assertCreated();

        // Ticket creation writes one audit row (subject and
        // category only), but the message text must not appear in
        // the audit log anywhere.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'support.ticket_created',
        ]);
        $this->assertSame(0, \App\Models\AuditLog::query()
            ->where('organization_id', $organization->getKey())
            ->where(function ($query) {
                $query->where('after', 'like', '%hunter2%')
                    ->orWhere('before', 'like', '%hunter2%');
            })
            ->count());
    }
}
