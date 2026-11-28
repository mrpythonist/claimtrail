<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One webhook delivery attempt (v2.0.2).
 *
 * The job performs a single attempt through the dispatcher
 * and, when the attempt fails and retries remain,
 * dispatches the next attempt with the next retry delay.
 * The retry budget is bounded (config
 * public_api.webhook_max_attempts, 5 by default): once the
 * budget is spent the delivery is recorded failed and the
 * job does not re-queue. A delivery that already succeeded
 * or failed is a no-op, so a duplicate dispatch can never
 * double-deliver.
 *
 * The next attempt is a fresh dispatch, not
 * $this->release(): on the sync driver release is a no-op
 * that silently drops the retry (SyncJob::release only
 * marks the job released), so a sync queue would never
 * retry. A fresh dispatch runs the attempt immediately on
 * the sync driver and honors the delay on a real queue.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public WebhookDelivery $delivery) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = $this->delivery->fresh();

        if ($delivery === null) {
            return;
        }

        if ($delivery->status !== WebhookDelivery::STATUS_PENDING) {
            return;
        }

        $delivered = $dispatcher->deliver($delivery);

        if ($delivered) {
            return;
        }

        $delivery->refresh();

        if ($delivery->status !== WebhookDelivery::STATUS_PENDING) {
            return;
        }

        $delays = config('public_api.webhook_retry_delays', [60, 300, 900, 1800]);
        $delay = (int) ($delays[$delivery->attempts - 1] ?? end($delays));

        self::dispatch($delivery)->delay(now()->addSeconds($delay));
    }
}
