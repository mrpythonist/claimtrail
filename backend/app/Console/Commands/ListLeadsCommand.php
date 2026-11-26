<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * List marketing leads (v1.2.2).
 *
 * Leads have no admin UI yet: the operator reviews them with this
 * command. Newest first; filter by status with --status=new (the
 * default), --status=contacted, and so on. Marking a lead
 * contacted, qualified, converted, or closed is a database update
 * for now; a UI for it belongs to a later version.
 */
class ListLeadsCommand extends Command
{
    protected $signature = 'leads:list {--status=new : Only show leads with this status (or "all")} {--limit=20 : How many leads to show}';

    protected $description = 'List leads captured by the marketing site';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $query = Lead::query()->latest();

        if ($status !== 'all') {
            if (! in_array($status, Lead::statuses(), true)) {
                $this->error(sprintf(
                    'Unknown status "%s". Use one of: %s, or all.',
                    $status,
                    implode(', ', Lead::statuses()),
                ));

                return self::FAILURE;
            }

            $query->where('status', $status);
        }

        $leads = $query->limit($limit)->get();

        if ($leads->isEmpty()) {
            $this->info('No leads found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Created', 'Name', 'Email', 'Company', 'Fleet', 'Source', 'Status'],
            $leads->map(fn (Lead $lead) => [
                $lead->created_at?->format('Y-m-d H:i') ?? '-',
                $lead->name,
                $lead->email,
                $lead->company ?? '-',
                $lead->fleet_size ?? '-',
                $lead->source,
                $lead->status,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
