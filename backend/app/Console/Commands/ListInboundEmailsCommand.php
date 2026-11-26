<?php

namespace App\Console\Commands;

use App\Models\InboundEmail;
use Illuminate\Console\Command;

/**
 * List inbound emails across organizations (v1.3.0).
 *
 * The operator works the intake queue with this command: the
 * list is the processing history, newest first. Filter by
 * status with --status=matched (the default is all), or by
 * organization with --organization (UUID or slug).
 */
class ListInboundEmailsCommand extends Command
{
    protected $signature = 'email-intake:list {--status=all : Only show emails with this status (or "all")} {--organization= : UUID or slug of one organization} {--limit=20 : How many emails to show}';

    protected $description = 'List inbound emails across organizations';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $query = InboundEmail::withoutGlobalScope('organization')
            ->with(['organization', 'matchedShipment'])
            ->latest();

        if ($status !== 'all') {
            if (! in_array($status, InboundEmail::statuses(), true)) {
                $this->error(sprintf(
                    'Unknown status "%s". Use one of: %s, or all.',
                    $status,
                    implode(', ', InboundEmail::statuses()),
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

        $emails = $query->limit($limit)->get();

        if ($emails->isEmpty()) {
            $this->info('No inbound emails found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Received', 'ID', 'Organization', 'From', 'Subject', 'Status', 'Matched shipment'],
            $emails->map(fn (InboundEmail $email) => [
                $email->created_at?->format('Y-m-d H:i') ?? '-',
                substr((string) $email->getKey(), 0, 8),
                $email->organization?->name ?? '-',
                mb_strimwidth($email->from, 0, 30, '...'),
                mb_strimwidth($email->subject ?? '-', 0, 40, '...'),
                $email->status,
                $email->matchedShipment?->internal_reference ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
