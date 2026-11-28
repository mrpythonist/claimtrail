<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AlertingService;
use Illuminate\Console\Command;

/**
 * Evaluate alert rules (v2.0.3).
 *
 * Runs every enabled alert rule for one organization
 * (--organization) or every organization, opens or updates
 * alerts on breach, and notifies the enterprise managers when
 * a new alert opens. Exit code 0 even when alerts fire: a
 * breach is a finding, not a command failure. Scheduled hourly.
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature = 'alerts:evaluate {--organization= : UUID of the organization to evaluate (default: all)}';

    protected $description = 'Evaluate alert rules and open alerts on breach';

    public function handle(AlertingService $alerting): int
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
            $organizations = Organization::query()->orderBy('name')->get();
        }

        $breaches = 0;
        $evaluated = 0;

        foreach ($organizations as $organization) {
            foreach ($alerting->evaluateOrganization($organization) as $result) {
                $evaluated++;

                if ($result['breached']) {
                    $breaches++;
                    $this->line(sprintf(
                        '  [%s] %s breached: observed %s',
                        $organization->name,
                        $result['metric'],
                        $result['observed_value'] ?? 'unmeasurable',
                    ));
                }
            }
        }

        $this->info(sprintf('Evaluated %d rule(s), %d breach(es).', $evaluated, $breaches));

        return self::SUCCESS;
    }
}
