<?php

namespace App\Services;

use App\Models\InboundEmail;
use App\Models\Organization;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Processes one stored inbound email (v1.3.0).
 *
 * Steps, in order: store the validated attachments on the
 * private disk, extract shipment references from the subject,
 * body, and attachment filenames, match them against the
 * organization's shipments, and auto-match when exactly one
 * shipment matches. Zero or several matches leave the email
 * unmatched for manual matching. The webhook calls process()
 * synchronously after storing the row; ProcessInboundEmail is
 * the queued wrapper for reprocessing.
 *
 * Attachment validation uses the same limits as evidence
 * uploads (config/evidence.php): max 20480 KB and the same
 * extension allowlist, checked against the real content with
 * the MIME type, not just the filename. An attachment that
 * fails validation is skipped and named in processing_notes;
 * the email itself is still stored and processed.
 */
class InboundEmailProcessor
{
    public function __construct(
        protected ShipmentReferenceExtractor $extractor,
        protected EmailIntakeService $intake,
        protected AuditService $audit,
    ) {}

    /**
     * Validate one decoded attachment against the evidence
     * limits. Returns null when it passes, or a plain-language
     * reason when it does not.
     */
    public function validateAttachment(string $filename, string $mimeType, string $bytes): ?string
    {
        $maxBytes = ((int) config('evidence.max_size_kb')) * 1024;

        if (strlen($bytes) > $maxBytes) {
            return "Attachment {$filename} exceeds the ".config('evidence.max_size_kb')." KB limit.";
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, config('evidence.allowed_extensions'), true)) {
            return "Attachment {$filename} has an extension that is not allowed.";
        }

        $allowedMimes = MimeTypes::getDefault()->getMimeTypes($extension);

        if ($allowedMimes !== [] && ! in_array(strtolower($mimeType), array_map('strtolower', $allowedMimes), true)) {
            return "Attachment {$filename} has MIME type {$mimeType}, which does not match its extension.";
        }

        return null;
    }

    /**
     * Decode and store the attachments of an email that passed
     * webhook validation. Returns the filenames that were
     * skipped, with reasons, for processing_notes.
     *
     * @param  array<int, array{filename: string, mime_type: string, content_base64: string}>  $attachments
     * @return array<int, string>
     */
    public function storeAttachments(InboundEmail $email, array $attachments): array
    {
        $skipped = [];

        foreach ($attachments as $attachment) {
            $bytes = base64_decode($attachment['content_base64'], true);

            if ($bytes === false) {
                $skipped[] = "Attachment {$attachment['filename']} is not valid base64 and was skipped.";

                continue;
            }

            $reason = $this->validateAttachment($attachment['filename'], $attachment['mime_type'], $bytes);

            if ($reason !== null) {
                $skipped[] = $reason;

                continue;
            }

            $extension = strtolower(pathinfo($attachment['filename'], PATHINFO_EXTENSION));
            // Stored under the evidence/ prefix so a file later
            // linked as evidence passes the StorageKey guard on
            // download and preview (v1.1.3): only evidence/ keys
            // are servable, and the email-intake/ segment keeps
            // these objects apart from direct evidence uploads.
            $key = 'evidence/'.$email->organization_id.'/email-intake/'.$email->getKey().'/'.Str::uuid().'.'.$extension;

            Storage::disk(config('evidence.disk'))->put($key, $bytes);

            $email->attachments()->create([
                'filename' => $attachment['filename'],
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => strlen($bytes),
                'storage_path' => $key,
                'checksum' => hash('sha256', $bytes),
            ]);
        }

        return $skipped;
    }

    /**
     * Run reference extraction and matching for a stored email.
     * Never throws for a match outcome: zero or several matches
     * are recorded as unmatched. A genuine exception marks the
     * email failed with the error in processing_notes.
     */
    public function process(InboundEmail $email): InboundEmail
    {
        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->whereKey($email->organization_id)
            ->firstOrFail();

        try {
            return DB::transaction(function () use ($email, $organization) {
                $email->loadMissing('attachments');

                $filenames = $email->attachments->pluck('filename')->all();
                $haystack = $this->extractor->haystack($email->subject, $email->text_body, $filenames);
                $candidates = $this->extractor->extract([$email->subject, $email->text_body, ...$filenames]);
                $result = $this->extractor->match($organization, $candidates, $haystack);

                $notes = $email->processing_notes;

                if (! empty($result['matches'])) {
                    $matched = $result['matches'];
                    $references = array_map(fn ($match) => $match['reference'].' ('.$match['field'].')', $matched);
                    $notes = $this->appendNote($notes, 'References found: '.implode(', ', $references).'.');
                }

                $distinct = $result['distinct_shipment_ids'];

                if (count($distinct) === 1) {
                    $shipment = Shipment::forOrganization($organization)
                        ->whereKey($distinct[0])
                        ->firstOrFail();

                    $email->update([
                        'extracted_references' => $result['candidates'],
                        'status' => InboundEmail::STATUS_MATCHED,
                        'matched_shipment_id' => $shipment->getKey(),
                        'match_reason' => 'exact reference match',
                        'match_confidence' => 'high',
                        'processing_notes' => $notes,
                    ]);

                    $this->intake->linkAttachmentsAsEvidence($email->fresh(), $shipment);

                    $this->audit->record(
                        $organization,
                        'email_intake.auto_matched',
                        $email->fresh(),
                        null,
                        ['status' => InboundEmail::STATUS_RECEIVED],
                        [
                            'status' => InboundEmail::STATUS_MATCHED,
                            'matched_shipment_id' => (string) $shipment->getKey(),
                            'match_reason' => 'exact reference match',
                        ],
                    );

                    return $email->fresh();
                }

                $reason = count($distinct) === 0
                    ? 'no shipment reference found'
                    : 'multiple shipments match the extracted references';

                $email->update([
                    'extracted_references' => $result['candidates'],
                    'status' => InboundEmail::STATUS_UNMATCHED,
                    'match_reason' => $reason,
                    'match_confidence' => count($distinct) === 0 ? 'none' : 'low',
                    'processing_notes' => $notes,
                ]);

                $this->audit->record(
                    $organization,
                    'email_intake.unmatched',
                    $email->fresh(),
                    null,
                    ['status' => InboundEmail::STATUS_RECEIVED],
                    ['status' => InboundEmail::STATUS_UNMATCHED, 'match_reason' => $reason],
                );

                return $email->fresh();
            });
        } catch (\Throwable $e) {
            Log::error('Inbound email processing failed.', [
                'email_id' => $email->getKey(),
                'error' => $e->getMessage(),
            ]);

            $email->update([
                'status' => InboundEmail::STATUS_FAILED,
                'processing_notes' => $this->appendNote(
                    $email->processing_notes,
                    'Processing failed: '.$e->getMessage(),
                ),
            ]);

            $this->audit->record(
                $organization,
                'email_intake.failed',
                $email->fresh(),
                null,
                ['status' => InboundEmail::STATUS_RECEIVED],
                ['status' => InboundEmail::STATUS_FAILED],
            );

            return $email->fresh();
        }
    }

    /**
     * Append one line to processing notes, keeping earlier lines.
     */
    protected function appendNote(?string $notes, string $line): string
    {
        return $notes !== null && $notes !== '' ? $notes."\n".$line : $line;
    }
}
