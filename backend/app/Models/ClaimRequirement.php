<?php

namespace App\Models;

use App\EvidenceDocumentCategory;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimRequirement extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'is_required' => true,
        'is_fulfilled' => false,
    ];

    protected $fillable = [
        'organization_id',
        'claim_id',
        'document_category',
        'is_required',
        'is_fulfilled',
        'evidence_file_id',
        'snapshot_at',
        'overridden_by',
        'override_reason',
        'overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'document_category' => EvidenceDocumentCategory::class,
            'is_required' => 'boolean',
            'is_fulfilled' => 'boolean',
            'snapshot_at' => 'datetime',
            'overridden_at' => 'datetime',
        ];
    }

    /**
     * The claim this snapshot row belongs to.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The evidence file that fulfils this requirement, if one does.
     */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class, 'evidence_file_id');
    }

    /**
     * The user who overrode this requirement, if it was overridden.
     */
    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * Whether this requirement was overridden with an explanation.
     */
    public function isOverridden(): bool
    {
        return $this->overridden_by !== null;
    }
}
