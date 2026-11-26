<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasStandardRetries;
use App\Jobs\Concerns\IsIdempotent;
use App\Models\InboundEmail;
use App\Services\InboundEmailProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one stored inbound email through reference
 * extraction and shipment matching (v1.3.0).
 *
 * The webhook processes synchronously after storing the row;
 * this job is the queued wrapper for reprocessing an email
 * (for example after a failed run, or when an operator wants
 * the match re-evaluated). It is idempotent: a redelivery
 * after a completed run is skipped, and a failed run releases
 * its key so a retry can claim it again. Only emails still in
 * received or failed status are processed; an email that was
 * already matched, unmatched, or dismissed by hand is left
 * alone.
 */
class ProcessInboundEmail implements ShouldQueue
{
    use HasStandardRetries, IsIdempotent, Queueable;

    public function __construct(
        protected string $emailId,
    ) {}

    /**
     * One key per email: an email is processed once.
     */
    public function idempotencyKey(): string
    {
        return 'inbound-email:'.$this->emailId;
    }

    /**
     * Execute the job.
     */
    public function handle(InboundEmailProcessor $processor): void
    {
        $email = InboundEmail::query()
            ->withoutGlobalScope('organization')
            ->find($this->emailId);

        if ($email === null) {
            Log::warning('Inbound email job ran for a missing email.', [
                'email_id' => $this->emailId,
            ]);

            return;
        }

        if (! in_array($email->status, [InboundEmail::STATUS_RECEIVED, InboundEmail::STATUS_FAILED], true)) {
            return;
        }

        $this->runIdempotently(function () use ($processor, $email): void {
            $processor->process($email);
        });
    }

    /**
     * Handle a job failure after all retries.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Inbound email processing failed after retries.', [
            'email_id' => $this->emailId,
            'error' => $exception->getMessage(),
        ]);
    }
}
