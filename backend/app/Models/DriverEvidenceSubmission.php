<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One evidence file a driver submitted through a driver
 * evidence link (v1.3.3).
 *
 * Ties the link to the stored evidence file, so the
 * organization can see who submitted what, for which
 * shipment or claim, and when — without the driver ever
 * having an account.
 */
class DriverEvidenceSubmission extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'driver_evidence_link_id',
        'shipment_id',
        'claim_id',
        'evidence_file_id',
        'driver_name',
        'notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * The link the submission came through.
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(DriverEvidenceLink::class, 'driver_evidence_link_id');
    }

    /**
     * The shipment the evidence supports, if any.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The claim the evidence supports, if any.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The stored evidence file.
     */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class);
    }
}
