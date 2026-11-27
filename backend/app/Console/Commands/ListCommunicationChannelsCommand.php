<?php

namespace App\Console\Commands;

use App\Models\CommunicationChannel;
use Illuminate\Console\Command;

/**
 * List communication channels across organizations (v1.3.3).
 *
 * The operator works the channel fleet with this command:
 * the list is newest first, with provider, status, last
 * test, and last error. Filter by provider with
 * --provider=slack (or teams, mock), by status with
 * --status=disabled, or by organization with
 * --organization (UUID or slug).
 */
class ListCommunicationChannelsCommand extends Command
{
    protected $signature = 'comms:list {--provider= : Only show channels for this provider} {--status=all : Only show channels with this status (or "all")} {--organization= : UUID or slug of one organization} {--limit=20 : How many channels to show}';

    protected $description = 'List communication channels across organizations';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $query = CommunicationChannel::withoutGlobalScope('organization')
            ->with(['organization'])
            ->latest();

        if ($this->option('provider') !== null) {
            $query->where('provider', (string) $this->option('provider'));
        }

        if ($status !== 'all') {
            if (! in_array($status, [CommunicationChannel::STATUS_ACTIVE, CommunicationChannel::STATUS_DISABLED], true)) {
                $this->error(sprintf(
                    'Unknown status "%s". Use one of: %s, or all.',
                    $status,
                    implode(', ', [CommunicationChannel::STATUS_ACTIVE, CommunicationChannel::STATUS_DISABLED]),
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

        $channels = $query->limit($limit)->get();

        if ($channels->isEmpty()) {
            $this->info('No communication channels found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Organization', 'Name', 'Provider', 'Status', 'Last test', 'Last error'],
            $channels->map(fn (CommunicationChannel $channel) => [
                substr((string) $channel->getKey(), 0, 8),
                $channel->organization?->slug ?? '-',
                $channel->name,
                $channel->provider,
                $channel->status,
                $channel->last_tested_at?->toDateTimeString() ?? '-',
                $channel->last_error !== null ? mb_substr($channel->last_error, 0, 60) : '-',
            ])->all()
        );

        return self::SUCCESS;
    }
}
