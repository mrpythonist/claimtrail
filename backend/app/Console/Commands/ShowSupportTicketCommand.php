<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use Illuminate\Console\Command;

/**
 * Show one support ticket with its messages (v1.2.3).
 *
 * The operator's way to read a ticket: subject, category,
 * priority, status, requester, and the full conversation
 * including internal notes. Replying stays in the app or
 * through the API; this command only reads.
 */
class ShowSupportTicketCommand extends Command
{
    protected $signature = 'support:show {ticket : UUID of the support ticket}';

    protected $description = 'Show a support ticket with its messages';

    public function handle(): int
    {
        $ticket = SupportTicket::withoutGlobalScope('organization')
            ->with(['organization', 'requester', 'messages.user'])
            ->whereKey((string) $this->argument('ticket'))
            ->first();

        if ($ticket === null) {
            $this->error('Support ticket not found: '.$this->argument('ticket'));

            return self::FAILURE;
        }

        $this->info($ticket->subject);
        $this->line('Organization: '.($ticket->organization?->name ?? '-'));
        $this->line('Requester: '.($ticket->requester !== null
            ? $ticket->requester->name.' <'.$ticket->requester->email.'>'
            : '-'));
        $this->line('Category: '.$ticket->category.' | Priority: '.$ticket->priority.' | Status: '.$ticket->status);
        $this->line('Created: '.$ticket->created_at);
        $this->newLine();

        foreach ($ticket->messages as $message) {
            $author = $message->user !== null ? $message->user->name : 'system';
            $internal = $message->internal ? ' [internal note]' : '';

            $this->line($message->created_at.' — '.$author.$internal);
            $this->line($message->body);
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
