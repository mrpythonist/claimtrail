<?php

namespace Tests\Feature;

use App\Jobs\GenerateClaimPackage;
use App\Jobs\SendNotificationEmail;
use App\Mail\NotificationMail;
use App\Models\GeneratedDocument;
use App\Models\JobExecution;
use App\Models\Notification;
use App\OrganizationRole;
use App\Services\ClaimPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdempotentJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_notification_email_is_sent_only_once_across_redeliveries(): void
    {
        Mail::fake();
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $notification = Notification::create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->id,
            'type' => 'claim.submitted',
            'title' => 'Claim submitted',
            'body' => 'Your claim was submitted.',
        ]);

        $job = new SendNotificationEmail(
            $user->id,
            (string) $organization->getKey(),
            'claim.submitted',
            'Claim submitted',
            'Your claim was submitted.',
            [],
            (string) $notification->getKey(),
        );

        $job->handle();
        (new SendNotificationEmail(
            $user->id,
            (string) $organization->getKey(),
            'claim.submitted',
            'Claim submitted',
            'Your claim was submitted.',
            [],
            (string) $notification->getKey(),
        ))->handle();

        Mail::assertSent(NotificationMail::class, 1);
        $this->assertNotNull($notification->fresh()->email_sent_at);
        $this->assertSame(1, JobExecution::count());
        $this->assertSame('completed', JobExecution::first()->status);
    }

    public function test_a_claim_package_is_rendered_only_once_across_redeliveries(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CLM-2026-0042',
        ]);

        $document = GeneratedDocument::create([
            'organization_id' => $organization->getKey(),
            'claim_id' => $claim->getKey(),
            'status' => 'queued',
        ]);

        $service = app(ClaimPackageService::class);
        (new GenerateClaimPackage($document->getKey()))->handle($service);
        $firstKey = $document->fresh()->storage_key;

        (new GenerateClaimPackage($document->getKey()))->handle($service);

        $this->assertSame($firstKey, $document->fresh()->storage_key);
        $this->assertSame(1, count(Storage::disk('private')->allFiles('packages')));
        $this->assertSame(1, JobExecution::count());
    }

    public function test_a_failed_run_releases_its_key_so_a_retry_can_claim_it(): void
    {
        $this->assertTrue(JobExecution::claim('key-1', 'App\\Jobs\\SomeJob'));
        JobExecution::release('key-1');

        $this->assertTrue(JobExecution::claim('key-1', 'App\\Jobs\\SomeJob'));
    }

    public function test_a_completed_key_cannot_be_claimed_again(): void
    {
        $this->assertTrue(JobExecution::claim('key-2', 'App\\Jobs\\SomeJob'));
        JobExecution::complete('key-2');

        $this->assertFalse(JobExecution::claim('key-2', 'App\\Jobs\\SomeJob'));
    }

    public function test_a_running_key_cannot_be_claimed_by_a_second_worker(): void
    {
        $this->assertTrue(JobExecution::claim('key-3', 'App\\Jobs\\SomeJob'));

        $this->assertFalse(JobExecution::claim('key-3', 'App\\Jobs\\SomeJob'));
    }

    public function test_a_stale_running_key_can_be_reclaimed(): void
    {
        JobExecution::create([
            'key' => 'key-4',
            'job' => 'App\\Jobs\\SomeJob',
            'status' => 'running',
        ]);

        // Eloquent manages updated_at on create, so backdate the
        // row with the query builder to simulate an abandoned run.
        DB::table('job_executions')->where('key', 'key-4')->update([
            'updated_at' => now()->subHours(2),
        ]);

        $this->assertTrue(JobExecution::claim('key-4', 'App\\Jobs\\SomeJob'));
    }

    public function test_ad_hoc_notification_keys_derive_from_the_payload(): void
    {
        $jobA = new SendNotificationEmail(1, 'org-1', 'type', 'Title', 'Body');
        $jobB = new SendNotificationEmail(1, 'org-1', 'type', 'Title', 'Body');
        $jobC = new SendNotificationEmail(1, 'org-1', 'type', 'Other title', 'Body');

        $this->assertSame($jobA->idempotencyKey(), $jobB->idempotencyKey());
        $this->assertNotSame($jobA->idempotencyKey(), $jobC->idempotencyKey());
    }
}
