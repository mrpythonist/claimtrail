<?php

namespace Tests\Feature;

use App\Jobs\GenerateClaimPackage;
use App\Jobs\SendNotificationEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class JobRetriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_package_job_uses_the_standard_retry_profile(): void
    {
        $job = new GenerateClaimPackage('doc-1');

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertTrue($job->retryUntil()->isAfter(now()->addHours(23)));
        $this->assertTrue($job->retryUntil()->isBefore(now()->addHours(25)));
    }

    public function test_notification_email_job_keeps_its_longer_backoff(): void
    {
        $job = new SendNotificationEmail(1, 'org-1', 'type', 'Title', 'Body');

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_claim_package_failed_hook_logs_the_final_failure(): void
    {
        Log::spy();

        (new GenerateClaimPackage('doc-9'))->failed(new \RuntimeException('renderer down'));

        Log::shouldHaveReceived('error', [
            'Claim package generation failed after retries.',
            \Mockery::on(fn (array $context) => $context['document_id'] === 'doc-9'
                && $context['error'] === 'renderer down'),
        ]);
    }

    public function test_notification_email_failed_hook_logs_the_final_failure(): void
    {
        Log::spy();

        $job = new SendNotificationEmail(7, 'org-1', 'claim.submitted', 'Title', 'Body', [], 'notif-1');
        $job->failed(new \RuntimeException('SMTP refused'));

        Log::shouldHaveReceived('error', [
            'Notification email failed after retries.',
            \Mockery::on(fn (array $context) => $context['user_id'] === 7
                && $context['notification_id'] === 'notif-1'
                && $context['error'] === 'SMTP refused'),
        ]);
    }
}
