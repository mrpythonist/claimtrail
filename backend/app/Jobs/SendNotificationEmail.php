<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one notification email and stamps the notification row.
 *
 * The payload travels with the job (not the models), so a retry
 * always works from the original text. On success the in-app row,
 * when one exists, gets its email_sent_at stamp. On final failure
 * the error is logged with enough context to find the notification;
 * the in-app row is unaffected, so the member still sees it there.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job is moved to the failed table.
     */
    public int $tries = 3;

    /**
     * Backoff between attempts, in seconds.
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @param  array<string, mixed>  $data  entity links from the notification
     */
    public function __construct(
        protected int $userId,
        protected string $organizationId,
        protected string $type,
        protected string $title,
        protected string $body,
        protected array $data = [],
        protected ?string $notificationId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        $organization = Organization::find($this->organizationId);

        if ($user === null || $organization === null) {
            Log::warning('Notification email skipped: user or organization no longer exists.', [
                'user_id' => $this->userId,
                'organization_id' => $this->organizationId,
                'notification_id' => $this->notificationId,
            ]);

            return;
        }

        Mail::to($user)->send(new NotificationMail(
            $this->title,
            $this->body,
            $organization->name,
            $this->actionUrl(),
        ));

        if ($this->notificationId !== null) {
            Notification::query()
                ->withoutGlobalScope('organization')
                ->whereKey($this->notificationId)
                ->update(['email_sent_at' => now()]);
        }
    }

    /**
     * Handle a job failure after all retries.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Notification email failed after retries.', [
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'notification_id' => $this->notificationId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Deep link into the app for the notification, when the payload
     * names an entity the frontend has a page for.
     */
    protected function actionUrl(): ?string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if (! empty($this->data['claim_id'])) {
            return $frontend.'/claims/'.$this->data['claim_id'];
        }

        if (! empty($this->data['shipment_id'])) {
            return $frontend.'/shipments/'.$this->data['shipment_id'];
        }

        if ($this->type === 'deadline.upcoming' || $this->type === 'deadline.overdue') {
            return $frontend.'/deadlines';
        }

        if ($this->type === 'organization.invitation') {
            return $frontend.'/settings/members';
        }

        return $frontend.'/notifications';
    }
}
