<?php

namespace App\Services;

use App\DocumentClassification;
use App\DocumentExtractionStatus;
use App\DocumentFieldVerificationStatus;
use App\Models\DocumentExtraction;
use App\Models\DocumentExtractionField;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use App\NotificationType;
use App\Permission;
use App\Support\StorageKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * AI document processing (v2.0.1).
 *
 * One evidence file in, one extraction out. The local OCR
 * provider reads the text a document already carries, the
 * rule-based classifier names its type, the field extractor
 * pulls structured fields with a confidence each, and the
 * existing shipment reference extractor (v1.3.0) matches the
 * reference against the organization's shipments. Only an
 * exact reference match auto-links a shipment; anything
 * weaker goes to the human verification queue.
 *
 * Nothing here is real OCR and nothing calls an outside
 * service. A scanned image has no text layer for the local
 * provider, so its extraction fails with a plain reason.
 * Duplicates are flagged, never deleted. A human-verified
 * value is what downstream features use; extraction never
 * silently overwrites a verified value.
 */
class DocumentProcessingService
{
    /**
     * A field below this confidence needs a human.
     */
    public const LOW_CONFIDENCE_THRESHOLD = 70.00;

    public function __construct(
        protected OcrProvider $provider,
        protected DocumentClassifier $classifier,
        protected DocumentFieldExtractor $fieldExtractor,
        protected ShipmentReferenceExtractor $referenceExtractor,
        protected NotificationService $notifications,
    ) {}

    /**
     * Process one evidence file. Idempotent: an evidence file
     * that already has an extraction returns it instead of
     * processing twice.
     */
    public function process(EvidenceFile $evidence, User $actor): DocumentExtraction
    {
        $existing = DocumentExtraction::where('organization_id', $evidence->organization_id)
            ->where('evidence_file_id', $evidence->getKey())
            ->first();

        if ($existing !== null) {
            return $existing->load('fields');
        }

        $organization = $evidence->organization;

        $contents = $this->readFile($evidence);

        if ($contents === null) {
            return $this->recordFailed($evidence, 'The stored file could not be read.');
        }

        $hash = hash('sha256', $contents);

        if ($evidence->checksum !== null && $evidence->checksum !== '' && ! hash_equals($evidence->checksum, $hash)) {
            return $this->recordFailed($evidence, 'The stored file checksum does not match the recorded upload checksum.');
        }

        $ocr = $this->provider->extractText($contents, (string) $evidence->mime_type, (string) $evidence->original_filename);

        if (! $ocr->hasTextLayer || trim($ocr->text) === '') {
            return $this->recordFailed(
                $evidence,
                $ocr->note ?? 'No text could be extracted from this document.',
                $hash,
            );
        }

        $text = $ocr->text;
        $classified = $this->classifier->classify($text, (string) $evidence->original_filename);
        $fields = $this->fieldExtractor->extract($text, $classified['classification']);

        $candidates = $this->referenceExtractor->extract([$text]);
        $matched = $this->referenceExtractor->match($organization, $candidates, $text);

        $matchMethod = 'none';
        $matchedShipmentId = null;
        $matchConfidence = null;

        if ($matched['matches'] !== []) {
            $matchMethod = 'exact';
            $matchedShipmentId = $matched['matches'][0]['shipment_id'];
            $matchConfidence = 100.00;
        } elseif ($matched['candidates'] !== []) {
            $matchMethod = 'candidate';
        }

        if ($candidates !== []) {
            $fields[] = [
                'name' => 'reference',
                'label' => 'Shipment reference',
                'value' => $candidates[0]['value'],
                'confidence' => $matchMethod === 'exact' ? 95.00 : 60.00,
            ];
        }

        $isDuplicate = false;
        $duplicateOfId = null;
        $earliestSameHash = DocumentExtraction::where('organization_id', $organization->getKey())
            ->where('content_sha256', $hash)
            ->orderBy('created_at')
            ->first();

        if ($earliestSameHash !== null) {
            $isDuplicate = true;
            $duplicateOfId = $earliestSameHash->getKey();
        }

        $needsVerification = $classified['classification'] === DocumentClassification::Unclassified
            || $matchMethod === 'candidate'
            || $isDuplicate
            || collect($fields)->contains(fn (array $field) => $field['confidence'] < self::LOW_CONFIDENCE_THRESHOLD);

        $extraction = DB::transaction(function () use (
            $evidence, $organization, $hash, $text, $classified, $fields,
            $matchMethod, $matchedShipmentId, $matchConfidence, $matched,
            $isDuplicate, $duplicateOfId, $needsVerification,
        ) {
            $extraction = DocumentExtraction::create([
                'organization_id' => $organization->getKey(),
                'evidence_file_id' => $evidence->getKey(),
                'original_filename' => $evidence->original_filename,
                'mime_type' => $evidence->mime_type,
                'file_size' => $evidence->file_size,
                'content_sha256' => $hash,
                'provider' => $this->provider->name(),
                'classification' => $classified['classification'],
                'classification_confidence' => $classified['confidence'],
                'status' => $needsVerification
                    ? DocumentExtractionStatus::NeedsVerification
                    : DocumentExtractionStatus::Completed,
                'matched_shipment_id' => $matchedShipmentId,
                'match_confidence' => $matchConfidence,
                'match_method' => $matchMethod,
                'match_candidates' => $matched['candidates'],
                'is_duplicate' => $isDuplicate,
                'duplicate_of_id' => $duplicateOfId,
                'extracted_text' => $text,
                'processed_at' => now(),
            ]);

            foreach ($fields as $field) {
                DocumentExtractionField::create([
                    'organization_id' => $organization->getKey(),
                    'extraction_id' => $extraction->getKey(),
                    'field_name' => $field['name'],
                    'field_label' => $field['label'],
                    'field_value' => $field['value'],
                    'confidence' => $field['confidence'],
                    'verification_status' => DocumentFieldVerificationStatus::Pending,
                ]);
            }

            return $extraction;
        });

        $extraction->load('fields');
        $this->flagNearDuplicate($extraction, $fields);

        if ($extraction->status === DocumentExtractionStatus::NeedsVerification) {
            $this->notifyNeedsVerification($extraction, $actor);
        }

        return $extraction->fresh('fields');
    }

    /**
     * Decide on one extracted field. "verified" keeps the
     * extracted value, "corrected" needs the right value,
     * "rejected" marks the value unusable with no replacement.
     * A field that already has a decision can be decided
     * again — the latest human decision stands — but the
     * extracted value itself is never edited.
     */
    public function verifyField(
        DocumentExtractionField $field,
        User $user,
        string $decision,
        ?string $correctedValue = null,
        ?string $note = null,
    ): DocumentExtractionField {
        if (! in_array($decision, ['verified', 'corrected', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Decision must be verified, corrected, or rejected.',
            ]);
        }

        if ($decision === 'corrected' && ($correctedValue === null || trim($correctedValue) === '')) {
            throw ValidationException::withMessages([
                'corrected_value' => 'A corrected value is required when the decision is corrected.',
            ]);
        }

        $field->update([
            'verified_value' => match ($decision) {
                'verified' => $field->field_value,
                'corrected' => trim((string) $correctedValue),
                default => null,
            },
            'verification_status' => $decision,
            'verified_by' => $user->getKey(),
            'verified_at' => now(),
            'verification_note' => $note,
        ]);

        return $field->fresh();
    }

    /**
     * Mark a whole extraction verified. Every field must have
     * a decision, the shipment match must be resolved, and the
     * classification must be known — a human may name the
     * classification here when the classifier could not.
     */
    public function verifyExtraction(
        DocumentExtraction $extraction,
        User $user,
        ?string $classification = null,
    ): DocumentExtraction {
        if ($extraction->status === DocumentExtractionStatus::Verified) {
            return $extraction;
        }

        if ($extraction->status === DocumentExtractionStatus::Rejected) {
            throw ValidationException::withMessages([
                'extraction' => 'A rejected extraction cannot be verified.',
            ]);
        }

        if ($extraction->status === DocumentExtractionStatus::Failed) {
            throw ValidationException::withMessages([
                'extraction' => 'A failed extraction cannot be verified: no text was extracted.',
            ]);
        }

        if ($classification !== null) {
            if (! in_array($classification, DocumentClassification::verifiableValues(), true)) {
                throw ValidationException::withMessages([
                    'classification' => 'Classification must be a known document type.',
                ]);
            }

            $extraction->classification = DocumentClassification::from($classification);
        }

        if ($extraction->classification === DocumentClassification::Unclassified) {
            throw ValidationException::withMessages([
                'classification' => 'Set the document type before verifying: the classifier could not name this document.',
            ]);
        }

        if (! $extraction->fieldsResolved()) {
            throw ValidationException::withMessages([
                'extraction' => 'Every extracted field needs a decision before the extraction can be verified.',
            ]);
        }

        if ($extraction->matchUnresolved()) {
            throw ValidationException::withMessages([
                'extraction' => 'Link a shipment or dismiss the reference match before verifying.',
            ]);
        }

        $extraction->update([
            'status' => DocumentExtractionStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $user->getKey(),
        ]);

        return $extraction->fresh('fields');
    }

    /**
     * Reject an extraction as not usable, with the reason
     * recorded. A verified extraction cannot be rejected.
     */
    public function rejectExtraction(DocumentExtraction $extraction, User $user, string $reason): DocumentExtraction
    {
        if ($extraction->status === DocumentExtractionStatus::Verified) {
            throw ValidationException::withMessages([
                'extraction' => 'A verified extraction cannot be rejected.',
            ]);
        }

        $extraction->update([
            'status' => DocumentExtractionStatus::Rejected,
            'failure_reason' => $reason,
        ]);

        return $extraction->fresh('fields');
    }

    /**
     * Link a shipment to an extraction by hand, after a
     * human judged the candidate. The link is recorded as
     * manual, with full confidence: a person decided it, not
     * a pattern.
     */
    public function linkShipment(DocumentExtraction $extraction, User $user, Shipment $shipment): DocumentExtraction
    {
        $extraction->update([
            'matched_shipment_id' => $shipment->getKey(),
            'match_confidence' => 100.00,
            'match_method' => 'manual',
            'match_dismissed' => false,
        ]);

        return $extraction->fresh('fields');
    }

    /**
     * Dismiss the reference match: a human decided this
     * document belongs to no shipment in the organization.
     */
    public function dismissMatch(DocumentExtraction $extraction, User $user): DocumentExtraction
    {
        $extraction->update([
            'match_dismissed' => true,
        ]);

        return $extraction->fresh('fields');
    }

    /**
     * Extraction accuracy, measured only: agreement between
     * extracted values and human decisions. A field counts
     * as agreed when the human verified it as-is; corrected
     * and rejected fields count as disagreed. Fields with no
     * decision yet are excluded. Every rate is null when
     * nothing has been decided — never a claimed benchmark.
     *
     * @return array<string, mixed>
     */
    public function accuracy(Organization $organization): array
    {
        $fields = DocumentExtractionField::where('organization_id', $organization->getKey())
            ->where('verification_status', '!=', DocumentFieldVerificationStatus::Pending->value)
            ->with('extraction:id,classification')
            ->get();

        $overall = $this->agreement($fields);

        $byField = $fields->groupBy('field_name')
            ->map(fn ($group) => $this->agreement($group))
            ->sortKeys()
            ->toArray();

        $byClassification = $fields->groupBy(fn (DocumentExtractionField $field) => $field->extraction?->classification?->value ?? 'unclassified')
            ->map(fn ($group) => $this->agreement($group))
            ->sortKeys()
            ->toArray();

        return [
            'organization_id' => (string) $organization->getKey(),
            'overall' => $overall,
            'by_field' => $byField,
            'by_classification' => $byClassification,
            'counts' => [
                'total_extractions' => DocumentExtraction::where('organization_id', $organization->getKey())->count(),
                'needs_verification' => DocumentExtraction::where('organization_id', $organization->getKey())
                    ->where('status', DocumentExtractionStatus::NeedsVerification->value)
                    ->count(),
                'verified_extractions' => DocumentExtraction::where('organization_id', $organization->getKey())
                    ->where('status', DocumentExtractionStatus::Verified->value)
                    ->count(),
                'duplicates' => DocumentExtraction::where('organization_id', $organization->getKey())
                    ->where('is_duplicate', true)
                    ->count(),
                'decided_fields' => $fields->count(),
            ],
        ];
    }

    /**
     * Present one extraction for the API, with its fields and
     * the shipment it matched, if any.
     *
     * @return array<string, mixed>
     */
    public function presentExtraction(DocumentExtraction $extraction): array
    {
        $extraction->loadMissing(['fields', 'matchedShipment', 'duplicateOf']);

        return [
            'id' => (string) $extraction->getKey(),
            'evidence_file_id' => $extraction->evidence_file_id !== null ? (string) $extraction->evidence_file_id : null,
            'original_filename' => $extraction->original_filename,
            'mime_type' => $extraction->mime_type,
            'file_size' => $extraction->file_size,
            'content_sha256' => $extraction->content_sha256,
            'provider' => $extraction->provider,
            'classification' => $extraction->classification?->value,
            'classification_label' => $extraction->classification?->label(),
            'classification_confidence' => $extraction->classification_confidence !== null
                ? (float) $extraction->classification_confidence
                : null,
            'status' => $extraction->status->value,
            'status_label' => $extraction->status->label(),
            'failure_reason' => $extraction->failure_reason,
            'matched_shipment_id' => $extraction->matched_shipment_id !== null ? (string) $extraction->matched_shipment_id : null,
            'matched_shipment_reference' => $extraction->matchedShipment?->internal_reference
                ?? $extraction->matchedShipment?->external_load_reference,
            'match_confidence' => $extraction->match_confidence !== null ? (float) $extraction->match_confidence : null,
            'match_method' => $extraction->match_method,
            'match_candidates' => $extraction->match_candidates ?? [],
            'match_dismissed' => (bool) $extraction->match_dismissed,
            'is_duplicate' => (bool) $extraction->is_duplicate,
            'duplicate_of_id' => $extraction->duplicate_of_id !== null ? (string) $extraction->duplicate_of_id : null,
            'duplicate_of_filename' => $extraction->duplicateOf?->original_filename,
            'near_duplicate_of_id' => $extraction->near_duplicate_of_id !== null ? (string) $extraction->near_duplicate_of_id : null,
            'near_duplicate_reason' => $extraction->near_duplicate_reason,
            'extracted_text' => $extraction->extracted_text,
            'processed_at' => $extraction->processed_at?->toJSON(),
            'verified_at' => $extraction->verified_at?->toJSON(),
            'verified_by' => $extraction->verified_by,
            'created_at' => $extraction->created_at?->toJSON(),
            'updated_at' => $extraction->updated_at?->toJSON(),
            'fields' => $extraction->fields->map(fn (DocumentExtractionField $field) => $this->presentField($field))->all(),
        ];
    }

    /**
     * Present one extracted field for the API.
     *
     * @return array<string, mixed>
     */
    public function presentField(DocumentExtractionField $field): array
    {
        return [
            'id' => (string) $field->getKey(),
            'extraction_id' => (string) $field->extraction_id,
            'field_name' => $field->field_name,
            'field_label' => $field->field_label,
            'field_value' => $field->field_value,
            'confidence' => (float) $field->confidence,
            'verified_value' => $field->verified_value,
            'verification_status' => $field->verification_status->value,
            'verification_status_label' => $field->verification_status->label(),
            'verified_by' => $field->verified_by,
            'verified_at' => $field->verified_at?->toJSON(),
            'verification_note' => $field->verification_note,
        ];
    }

    /**
     * Read the stored file, or null when the key is unsafe
     * or the object is missing.
     */
    protected function readFile(EvidenceFile $evidence): ?string
    {
        if (! StorageKey::isSafe((string) $evidence->storage_key, 'evidence')) {
            return null;
        }

        $disk = Storage::disk((string) $evidence->disk);

        if (! $disk->exists((string) $evidence->storage_key)) {
            return null;
        }

        return $disk->get((string) $evidence->storage_key);
    }

    /**
     * Record an extraction that could not be processed.
     */
    protected function recordFailed(EvidenceFile $evidence, string $reason, ?string $hash = null): DocumentExtraction
    {
        return DocumentExtraction::create([
            'organization_id' => $evidence->organization_id,
            'evidence_file_id' => $evidence->getKey(),
            'original_filename' => $evidence->original_filename,
            'mime_type' => $evidence->mime_type,
            'file_size' => $evidence->file_size,
            'content_sha256' => $hash,
            'provider' => $this->provider->name(),
            'status' => DocumentExtractionStatus::Failed,
            'failure_reason' => $reason,
            'processed_at' => now(),
        ]);
    }

    /**
     * Near-duplicate check: the same shipment reference, the
     * same amount, and the same date as an earlier extraction
     * in this organization. The same file bytes are an exact
     * duplicate instead and are caught earlier. A near
     * duplicate is flagged with the reason, never deleted,
     * and it needs verification.
     *
     * @param  array<int, array{name: string, label: string, value: string, confidence: float}>  $fields
     */
    protected function flagNearDuplicate(DocumentExtraction $extraction, array $fields): void
    {
        $byName = collect($fields)->keyBy('name');

        $reference = $byName->get('reference')['value'] ?? null;
        $date = $byName->get('document_date')['value']
            ?? $byName->get('detention_date')['value']
            ?? $byName->get('invoice_date')['value']
            ?? $byName->get('delivered_date')['value']
            ?? $byName->get('delivery_date')['value']
            ?? null;
        $amount = $byName->get('amount_total')['value']
            ?? $byName->get('amount_due')['value']
            ?? $byName->get('detention_amount')['value']
            ?? $byName->get('agreed_rate')['value']
            ?? null;

        if ($reference === null || $date === null || $amount === null) {
            return;
        }

        $earlier = DocumentExtraction::where('organization_id', $extraction->organization_id)
            ->where('id', '!=', $extraction->getKey())
            ->where('created_at', '<=', $extraction->created_at)
            ->orderBy('created_at')
            ->get()
            ->first(function (DocumentExtraction $other) use ($reference, $date, $amount) {
                $otherFields = $other->fields()->get()->keyBy('field_name');

                $otherReference = $otherFields->get('reference')?->field_value;
                $otherDate = $otherFields->get('document_date')?->field_value
                    ?? $otherFields->get('detention_date')?->field_value
                    ?? $otherFields->get('invoice_date')?->field_value
                    ?? $otherFields->get('delivered_date')?->field_value
                    ?? $otherFields->get('delivery_date')?->field_value;
                $otherAmount = $otherFields->get('amount_total')?->field_value
                    ?? $otherFields->get('amount_due')?->field_value
                    ?? $otherFields->get('detention_amount')?->field_value
                    ?? $otherFields->get('agreed_rate')?->field_value;

                return $otherReference === $reference
                    && $otherDate === $date
                    && $otherAmount === $amount;
            });

        if ($earlier === null) {
            return;
        }

        $extraction->update([
            'near_duplicate_of_id' => $earlier->getKey(),
            'near_duplicate_reason' => 'Same shipment reference, amount, and date as '.$earlier->original_filename.'.',
            'status' => DocumentExtractionStatus::NeedsVerification,
        ]);
    }

    /**
     * Tell the members who can process documents that one
     * needs a human. Deduplicated per extraction so a
     * re-process never spams the queue twice.
     */
    protected function notifyNeedsVerification(DocumentExtraction $extraction, User $actor): void
    {
        $recipients = $this->notifications->membersWithPermission(
            $extraction->organization,
            Permission::ManageDocumentProcessing,
        );

        $this->notifications->notify(
            $extraction->organization,
            $recipients,
            NotificationType::DocumentNeedsVerification,
            'Document needs verification: '.$extraction->original_filename,
            'The '.($extraction->classification?->label() ?? 'document').' was processed with open questions. Review it in the verification queue.',
            ['document_extraction_id' => (string) $extraction->getKey()],
            'document.needs_verification:'.$extraction->getKey(),
            $actor,
        );
    }

    /**
     * Agreement for a set of decided fields: how many were
     * verified as-is, and the rate. Null when nothing has
     * been decided.
     *
     * @param  \Illuminate\Support\Collection<int, DocumentExtractionField>  $fields
     * @return array{decided: int, agreed: int, accuracy: float|null}
     */
    protected function agreement($fields): array
    {
        $decided = $fields->count();
        $agreed = $fields->filter(fn (DocumentExtractionField $field) => $field->agreed() === true)->count();

        return [
            'decided' => $decided,
            'agreed' => $agreed,
            'accuracy' => $decided > 0 ? round($agreed / $decided * 100, 2) : null,
        ];
    }
}
