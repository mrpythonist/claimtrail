<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use Illuminate\Console\Command;

/**
 * List support tickets across organizations (v1.2.3).
 *
 * Tickets have no cross-organization UI: the operator works
 * them with this command and `support:show`. Newest first;
 * filter by status with --status=open (the default), --status=all,
 * and so on, or by organization with --organization (UUID or slug).
 */
class ListSupportTicketsCommand extends Command
{
    protected $signature = 'support:list {--status=open : Only show tickets with this status (or "all")} {--organization= : UUID or slug of one organization} {--limit=20 : How many tickets to show}';

    protected $description = 'List support tickets across organizations';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $query = SupportTicket::withoutGlobalScope('organization')
            ->with(['organization', 'requester'])
            ->latest();

        if ($status !== 'all') {
            if (! in_array($status, SupportTicket::statuses(), true)) {
                $this->error(sprintf(
                    'Unknown status "%s". Use one of: %s, or all.',
                    $status,
                    implode(', ', SupportTicket::statuses()),
                ));

                return self::FAILURE;
            }

            $query->where('status', $status);
        }

        if ($this->option('organization') !== null) {
            $identifier = (string) $this->option('organization');

            $query->whereHas('organization', fn ($q) => $q
                ->where('id', $identifier)
                ->orWhere('slug', $identifier));
        }

        $tickets = $query->limit($limit)->get();

        if ($tickets->isEmpty()) {
            $this->info('No support tickets found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Created', 'ID', 'Organization', 'Subject', 'Category', 'Priority', 'Status'],
            $tickets->map(fn (SupportTicket $ticket) => [
                $ticket->created_at?->format('Y-m-d H:i') ?? '-',
                substr((string) $ticket->getKey(), 0, 8),
                $ticket->organization?->name ?? '-',
                mb_strimwidth($ticket->subject, 0, 40, '...'),
                $ticket->category,
                $ticket->priority,
                $ticket->status,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
