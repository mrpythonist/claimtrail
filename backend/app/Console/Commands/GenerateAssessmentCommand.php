<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\SecurityAssessmentService;
use Illuminate\Console\Command;

/**
 * Generate an enterprise security self-assessment (v2.0.3).
 *
 * Runs the production-readiness checks plus the configuration
 * checks and stores the report. This is a self-assessment, not
 * a penetration test: the standing unverified items are
 * recorded as open findings, so the overall result is never
 * "pass" while any of them stand.
 */
class GenerateAssessmentCommand extends Command
{
    protected $signature = 'enterprise:assess {--organization= : UUID of the organization to assess (default: all)}';

    protected $description = 'Generate an enterprise security self-assessment';

    public function handle(SecurityAssessmentService $assessments): int
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

        foreach ($organizations as $organization) {
            $assessment = $assessments->generate($organization);

            $this->info(sprintf(
                '[%s] Assessment: %s (%d passed, %d warnings, %d failures, %d open findings).',
                $organization->name,
                $assessment->overall,
                $assessment->passed,
                $assessment->warnings,
                $assessment->failures,
                $assessment->open_findings,
            ));
        }

        return self::SUCCESS;
    }
}
