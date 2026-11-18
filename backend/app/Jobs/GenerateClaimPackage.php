<?php

namespace App\Jobs;

use App\GeneratedDocumentStatus;
use App\Jobs\Concerns\HasStandardRetries;
use App\Jobs\Concerns\IsIdempotent;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ClaimPackageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders a claim package PDF and stores it on the private disk.
 *
 * The job works from the generated_documents row created by the
 * controller, so a retry never creates a second document record.
 * It is also idempotent: a redelivery after a completed run is
 * skipped, so a retry can never render or store a second PDF for
 * the same document. On failure the row is marked failed with the
 * error message; the user regenerates from the claim page.
 */
class GenerateClaimPackage implements ShouldQueue
{
    use HasStandardRetries, IsIdempotent, Queueable;

    public function __construct(
        protected string $documentId,
    ) {}

    /**
     * One key per document: the PDF for a document is rendered once.
     */
    public function idempotencyKey(): string
    {
        return 'claim-package:'.$this->documentId;
    }

    /**
     * Execute the job.
     *
     * The audit service is optional so the job can be run directly
     * with only the package service (as the package tests do); the
     * container injects both when the job is dispatched normally.
     * The document lookup happens before the idempotency claim, so
     * a run for a deleted document leaves no execution row behind.
     */
    public function handle(ClaimPackageService $packages, ?AuditService $audit = null): void
    {
        $audit ??= app(AuditService::class);
        $document = GeneratedDocument::query()
            ->withoutGlobalScope('organization')
            ->find($this->documentId);

        if ($document === null) {
            Log::warning('Claim package job ran for a missing document.', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        $this->runIdempotently(function () use ($packages, $audit, $document): void {
            $this->render($packages, $audit, $document);
        });
    }

    /**
     * Handle a job failure after all retries.
     *
     * The document row was already marked failed by the failing
     * attempt; this records the final failure so it is visible in
     * the error log with the full attempt history behind it.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Claim package generation failed after retries.', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Render the PDF and store it. Runs at most once per document
     * thanks to the idempotency claim in handle().
     */
    protected function render(ClaimPackageService $packages, AuditService $audit, GeneratedDocument $document): void
    {
        try {
            $claim = $document->claim;
            $disk = config('evidence.disk');
            $key = 'packages/'.$document->organization_id.'/'.$claim->getKey().'/'.Str::uuid().'.pdf';

            $bytes = $packages->renderPdf($claim, $document->includes_internal_notes);

            Storage::disk($disk)->put($key, $bytes);

            $document->update([
                'storage_key' => $key,
                'disk' => $disk,
                'file_size' => strlen($bytes),
                'status' => GeneratedDocumentStatus::Ready,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            // The actor is the member who requested the package, not
            // the queue worker: the job runs without a signed-in
            // user, so the actor is taken from the document's
            // generated_by. The storage key is never written to
            // the audit row.
            $generator = $document->generated_by !== null
                ? User::find($document->generated_by)
                : null;

            $audit->recordFor(
                $document->fresh(),
                'claim_package.generated',
                $generator,
                after: [
                    'claim_id' => (string) $claim->getKey(),
                    'status' => GeneratedDocumentStatus::Ready->value,
                    'file_size' => strlen($bytes),
                    'includes_internal_notes' => $document->includes_internal_notes,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Claim package generation failed.', [
                'document_id' => $document->getKey(),
                'claim_id' => $document->claim_id,
                'error' => $e->getMessage(),
            ]);

            $document->update([
                'status' => GeneratedDocumentStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
