<?php

namespace App\Console\Commands;

use App\Services\SecurityCheckService;
use Illuminate\Console\Command;

/**
 * Report production-readiness problems.
 *
 * Prints every check as [PASS], [WARN], or [FAIL] and exits 1
 * when any check fails, 0 otherwise. Warnings do not fail the
 * run. The same checks back GET /security/check, so the command
 * line and the dashboard agree.
 */
class SecurityCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:check {--json : Output the results as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check production-readiness configuration (debug, keys, HTTPS, cookies, backups)';

    /**
     * Execute the console command.
     */
    public function handle(SecurityCheckService $checks): int
    {
        $result = $checks->run();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $checks->hasFailures($result) ? self::FAILURE : self::SUCCESS;
        }

        foreach ($result['checks'] as $check) {
            $tag = match ($check['status']) {
                'pass' => 'PASS',
                'warn' => 'WARN',
                'fail' => 'FAIL',
                default => strtoupper($check['status']),
            };

            $this->line('['.$tag.'] '.$check['label'].': '.$check['detail']);
        }

        $this->line('');
        $this->line('Overall: '.strtoupper($result['overall']));

        return $checks->hasFailures($result) ? self::FAILURE : self::SUCCESS;
    }
}
