<?php

namespace App\Console\Commands;

use App\Models\TmsIntegration;
use Illuminate\Console\Command;

/**
 * List TMS integrations across organizations (v1.3.2).
 *
 * The operator works the integration fleet with this
 * command: the list is newest first, with provider,
 * status, last sync, and last error. Filter by provider
 * with --provider=mock (or trimble-tmw), by status with
 * --status=disabled, or by organization with --organization
 * (UUID or slug).
 */
class ListTmsIntegrationsCommand extends Command
{
    protected $signature = 'tms:list {--provider= : Only show integrations for this provider} {--status=all : Only show integrations with this status (or "all")} {--organization= : UUID or slug of one organization} {--limit=20 : How many integrations to show}';

    protected $description = 'List TMS integrations across organizations';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $query = TmsIntegration::withoutGlobalScope('organization')
            ->with(['organization'])
            ->latest();

        if ($this->option('provider') !== null) {
            $query->where('provider', (string) $this->option('provider'));
        }

        if ($status !== 'all') {
            if (! in_array($status, [TmsIntegration::STATUS_ACTIVE, TmsIntegration::STATUS_DISABLED], true)) {
                $this->error(sprintf(
                    'Unknown status "%s". Use one of: %s, or all.',
                    $status,
                    implode(', ', [TmsIntegration::STATUS_ACTIVE, TmsIntegration::STATUS_DISABLED]),
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

        $integrations = $query->limit($limit)->get();

        if ($integrations->isEmpty()) {
            $this->info('No TMS integrations found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Organization', 'Name', 'Provider', 'Status', 'Last sync', 'Last error'],
            $integrations->map(fn (TmsIntegration $integration) => [
                substr((string) $integration->getKey(), 0, 8),
                $integration->organization?->slug ?? '-',
                $integration->name,
                $integration->provider,
                $integration->status,
                $integration->last_sync_at?->toDateTimeString() ?? '-',
                $integration->last_error !== null ? mb_substr($integration->last_error, 0, 60) : '-',
            ])->all()
        );

        return self::SUCCESS;
    }
}
