<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AgencyService;
use Illuminate\Console\Command;

/**
 * Print earned commission for an agency organization (v2.1.0).
 *
 * Commission is computed from the linked carriers' recorded
 * ClaimPayment rows only. This prints the calculated figure;
 * nothing here pays a partner out.
 */
class AgencyCommissionsCommand extends Command
{
    protected $signature = 'agency:commissions {--organization= : UUID of the agency organization (default: all agency organizations)} {--date-from= : Only payments on or after this date (Y-m-d)} {--date-to= : Only payments on or before this date (Y-m-d)}';

    protected $description = 'Show earned commission computed from recorded payments for agency organizations';

    public function handle(AgencyService $agency): int
    {
        $organizationId = $this->option('organization');

        if ($organizationId !== null) {
            $organization = Organization::query()->whereKey($organizationId)->first();

            if ($organization === null) {
                $this->error('Organization not found: '.$organizationId);

                return self::FAILURE;
            }

            $organizations = [$organization];
        } else {
            $organizationIds = \App\Models\Subscription::query()
                ->where('plan', 'agency')
                ->pluck('organization_id')
                ->all();

            $organizations = Organization::query()
                ->whereIn('id', $organizationIds)
                ->orderBy('name')
                ->get();
        }

        $dateFrom = $this->option('date-from');
        $dateTo = $this->option('date-to');

        foreach ($organizations as $organization) {
            // The command runs as the organization itself, so the
            // admin bypass applies: every active link is included.
            $summary = $agency->commissionsForReport($organization, $dateFrom, $dateTo);

            $this->info(sprintf('[%s] %d carriers.', $organization->name, count($summary['carriers'])));

            foreach ($summary['carriers'] as $row) {
                foreach ($row['by_currency'] as $bucket) {
                    $this->line(sprintf(
                        '  %s: %s %s recorded, %s %s earned at %s%%.',
                        $row['carrier']['name'],
                        $bucket['currency'],
                        $bucket['payments'],
                        $bucket['currency'],
                        $bucket['earned'],
                        $row['rate_percent'],
                    ));
                }
            }

            foreach ($summary['totals_by_currency'] as $total) {
                $this->info(sprintf(
                    '  Total: %s %s recorded, %s %s earned.',
                    $total['currency'],
                    $total['payments'],
                    $total['currency'],
                    $total['earned'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
