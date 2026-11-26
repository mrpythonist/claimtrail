<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer support tickets (v1.2.3).
 *
 * Any member of an organization may open a ticket and reply on
 * it. Triage, status changes, and internal notes are for the
 * organization's owner and admins (manage-support). The operator
 * works tickets across organizations with `support:list` /
 * `support:show`; there is no cross-organization UI.
 *
 * Replies are delivered as in-app notifications (and email when
 * the member has it on and a mailer is configured; the default
 * mailer is the log driver, so on a fresh install the in-app
 * notification is the delivery). Ticket bodies and messages are
 * never written to the audit log: they may contain customer data
 * the audit trail should not duplicate. Status changes are
 * audit rows without the message text.
 */
class SupportTicketService
{
    public function __construct(
        protected NotificationService $notifications,
        protected AuditService $audit,
    ) {}

    /**
     * Open a ticket with its first message.
     */
    public function create(
        Organization $organization,
        User $requester,
        string $subject,
        string $category,
        string $message,
        string $priority = SupportTicket::PRIORITY_NORMAL,
    ): SupportTicket {
        $ticket = DB::transaction(function () use ($organization, $requester, $subject, $category, $message, $priority) {
            $ticket = SupportTicket::create([
                'organization_id' => $organization->getKey(),
                'requester_id' => $requester->getKey(),
                'subject' => $subject,
                'category' => $category,
                'priority' => $priority,
                'status' => SupportTicket::STATUS_OPEN,
            ]);

            $ticket->messages()->create([
                'user_id' => $requester->getKey(),
                'body' => $message,
                'internal' => false,
            ]);

            return $ticket;
        });

        $this->audit->record(
            $organization,
            'support.ticket_created',
            $ticket,
            $requester,
            null,
            ['subject' => $subject, 'category' => $category],
        );

        $this->notifications->notify(
            $organization,
            $this->notifications->membersWithPermission($organization, Permission::ManageSupport, $requester),
            NotificationType::SupportTicketReply,
            "New support ticket: {$subject}",
            "{$requester->name} opened a {$category} ticket.",
            ['support_ticket_id' => $ticket->getKey()],
        );

        return $ticket->fresh();
    }

    /**
     * Add a message to a ticket.
     *
     * Staff replies (owner/admin) move an open or resolved ticket
     * to in progress. A customer reply to a resolved ticket
     * reopens it to open; a customer reply to a ticket waiting on
     * them moves it to in progress. Internal notes never change
     * the status and never notify the requester.
     *
     * @throws ValidationException when the ticket is closed.
     */
    public function reply(
        Organization $organization,
        SupportTicket $ticket,
        User $actor,
        string $body,
        bool $internal = false,
    ): SupportTicketMessage {
        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'ticket' => 'A closed ticket takes no more messages. Open a new ticket instead.',
            ]);
        }

        $isStaff = $actor->hasPermissionIn($organization, Permission::ManageSupport);

        $message = DB::transaction(function () use ($ticket, $actor, $body, $internal, $isStaff) {
            $message = $ticket->messages()->create([
                'user_id' => $actor->getKey(),
                'body' => $body,
                'internal' => $internal,
            ]);

            if (! $internal) {
                $newStatus = $this->statusAfterReply($ticket, $isStaff);

                $update = ['status' => $newStatus];

                // A reply that moves a ticket out of resolved clears
                // the resolved timestamp: the ticket is not resolved
                // any more, whether the customer reopened it or a
                // staff member picked it back up.
                if ($newStatus !== SupportTicket::STATUS_RESOLVED
                    && $ticket->status === SupportTicket::STATUS_RESOLVED
                ) {
                    $update['resolved_at'] = null;
                }

                $ticket->update($update);
            }

            return $message;
        });

        if (! $internal) {
            if ($isStaff) {
                $requester = $ticket->requester;

                if ($requester !== null && $requester->getKey() !== $actor->getKey()) {
                    $this->notifications->notify(
                        $organization,
                        [$requester],
                        NotificationType::SupportTicketReply,
                        "Reply on your ticket: {$ticket->subject}",
                        "{$actor->name} replied to your support ticket.",
                        ['support_ticket_id' => $ticket->getKey()],
                    );
                }
            } else {
                $this->notifications->notify(
                    $organization,
                    $this->notifications->membersWithPermission($organization, Permission::ManageSupport, $actor),
                    NotificationType::SupportTicketReply,
                    "Reply on ticket: {$ticket->subject}",
                    "{$actor->name} replied to a support ticket.",
                    ['support_ticket_id' => $ticket->getKey()],
                );
            }
        }

        return $message->fresh();
    }

    /**
     * Change a ticket's status. Owner and admin only (enforced
     * in the controller). Closing stamps closed_at; resolving
     * stamps resolved_at; reopening clears both.
     *
     * @throws ValidationException for an unknown status.
     */
    public function setStatus(
        Organization $organization,
        SupportTicket $ticket,
        string $status,
        ?User $actor = null,
    ): SupportTicket {
        if (! in_array($status, SupportTicket::statuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Unknown ticket status.',
            ]);
        }

        $before = $ticket->status;

        // Resolving stamps resolved_at; closing stamps closed_at
        // and keeps resolved_at when the ticket was resolved
        // first; reopening clears both; moving back to work
        // clears resolved_at because the ticket is not resolved
        // any more.
        $ticket->update([
            'status' => $status,
            'resolved_at' => match ($status) {
                SupportTicket::STATUS_RESOLVED => $ticket->resolved_at ?? now(),
                SupportTicket::STATUS_CLOSED => $ticket->resolved_at,
                default => null,
            },
            'closed_at' => $status === SupportTicket::STATUS_CLOSED ? now() : null,
        ]);

        $this->audit->record(
            $organization,
            'support.ticket_status_changed',
            $ticket,
            $actor,
            ['status' => $before],
            ['status' => $status],
        );

        return $ticket->fresh();
    }

    /**
     * The status a public reply leaves the ticket in.
     */
    protected function statusAfterReply(SupportTicket $ticket, bool $isStaff): string
    {
        if ($isStaff) {
            return in_array($ticket->status, [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_RESOLVED], true)
                ? SupportTicket::STATUS_IN_PROGRESS
                : $ticket->status;
        }

        return match ($ticket->status) {
            SupportTicket::STATUS_RESOLVED => SupportTicket::STATUS_OPEN,
            SupportTicket::STATUS_WAITING_ON_CUSTOMER => SupportTicket::STATUS_IN_PROGRESS,
            default => $ticket->status,
        };
    }

    /**
     * One ticket as the API returns it. Internal notes are
     * included only when the viewer may manage support.
     *
     * @return array<string, mixed>
     */
    public function payload(SupportTicket $ticket, bool $includeInternal): array
    {
        $ticket->loadMissing(['requester', 'messages.user']);

        $messages = $ticket->messages
            ->filter(fn (SupportTicketMessage $message) => $includeInternal || ! $message->internal)
            ->values();

        return [
            'id' => $ticket->getKey(),
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'requester' => $ticket->requester !== null ? [
                'id' => $ticket->requester->getKey(),
                'name' => $ticket->requester->name,
                'email' => $ticket->requester->email,
            ] : null,
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at->toIso8601String(),
            'updated_at' => $ticket->updated_at->toIso8601String(),
            'messages' => $messages->map(fn (SupportTicketMessage $message) => [
                'id' => $message->getKey(),
                'user' => $message->user !== null ? [
                    'id' => $message->user->getKey(),
                    'name' => $message->user->name,
                ] : null,
                'body' => $message->body,
                'internal' => $message->internal,
                'created_at' => $message->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
