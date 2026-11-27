<?php

namespace App\Models;

use App\DocumentFieldVerificationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field extracted from a document (v2.0.1).
 *
 * The extracted value and its confidence are recorded as the
 * extractor left them and are never edited in place. A human
 * decision lives on verified_value with its own status, user,
 * and time, so the accuracy monitor can compare what was
 * extracted with what a human decided.
 */
class DocumentExtractionField extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'extraction_id',
        'field_name',
        'field_label',
        'field_value',
        'confidence',
        'verified_value',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'verification_status' => DocumentFieldVerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The extraction this field belongs to.
     */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(DocumentExtraction::class, 'extraction_id');
    }

    /**
     * The user who decided on this field, if any.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Whether the human agreed with the extracted value:
     * verified means the value stands as extracted. A
     * corrected or rejected field disagreed. Pending fields
     * have not been decided yet and are excluded from
     * accuracy counts.
     */
    public function agreed(): ?bool
    {
        return match ($this->verification_status) {
            DocumentFieldVerificationStatus::Verified => true,
            DocumentFieldVerificationStatus::Corrected,
            DocumentFieldVerificationStatus::Rejected => false,
            default => null,
        };
    }
}
