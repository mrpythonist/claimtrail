<?php

namespace App\Models;

use App\DocumentClassification;
use App\DocumentExtractionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One processed document (v2.0.1).
 *
 * An extraction is created from one evidence file. It records
 * what the local provider extracted, what the classifier
 * decided, what shipment (if any) the reference matched, and
 * whether a human still needs to look. Extraction never
 * overwrites anything: the evidence file is untouched, a
 * matched shipment is a link, and human-verified field values
 * live on the field rows.
 */
class DocumentExtraction extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'evidence_file_id',
        'original_filename',
        'mime_type',
        'file_size',
        'content_sha256',
        'provider',
        'classification',
        'classification_confidence',
        'status',
        'failure_reason',
        'matched_shipment_id',
        'match_confidence',
        'match_method',
        'match_candidates',
        'match_dismissed',
        'is_duplicate',
        'duplicate_of_id',
        'near_duplicate_of_id',
        'near_duplicate_reason',
        'extracted_text',
        'processed_at',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'classification' => DocumentClassification::class,
            'classification_confidence' => 'decimal:2',
            'status' => DocumentExtractionStatus::class,
            'match_confidence' => 'decimal:2',
            'match_candidates' => 'array',
            'match_dismissed' => 'boolean',
            'is_duplicate' => 'boolean',
            'processed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The evidence file this extraction was made from.
     */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class);
    }

    /**
     * The shipment the extracted reference matched, if any.
     */
    public function matchedShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'matched_shipment_id');
    }

    /**
     * The earlier extraction this document duplicates, if any.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * The earlier extraction this document nearly duplicates,
     * if any.
     */
    public function nearDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'near_duplicate_of_id');
    }

    /**
     * The extracted fields, in the order they were extracted.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DocumentExtractionField::class, 'extraction_id')
            ->orderBy('id');
    }

    /**
     * The user who verified the whole extraction, if any.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Whether every extracted field has a human decision.
     */
    public function fieldsResolved(): bool
    {
        return ! $this->fields()
            ->where('verification_status', 'pending')
            ->exists();
    }

    /**
     * Whether the shipment match still needs a human: a
     * candidate was found but no exact match, and nobody has
     * linked a shipment or dismissed the match yet.
     */
    public function matchUnresolved(): bool
    {
        return $this->match_method === 'candidate'
            && $this->matched_shipment_id === null
            && ! $this->match_dismissed;
    }
}
