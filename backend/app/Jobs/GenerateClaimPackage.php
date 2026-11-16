<?php

namespace App\Jobs;

use App\GeneratedDocumentStatus;
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
 * On failure the row is marked failed with the error message; the
 * user regenerates from the claim page.
 */
class GenerateClaimPackage implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job is moved to the failed table.
     */
    public int $tries = 3;

    public function __construct(
        protected string $documentId,
    ) {}

    /**
     * Execute the job.
     *
     * The audit service is optional so the job can be run directly
     * with only the package service (as the package tests do); the
     * container injects both when the job is dispatched normally.
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
