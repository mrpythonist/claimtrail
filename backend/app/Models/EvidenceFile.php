<?php

namespace App\Models;

use App\EvidenceDocumentCategory;
use App\EvidenceVerificationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceFile extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Default that mirrors the database column default, so a freshly
     * created model reads its verification status before any refresh.
     */
    protected $attributes = [
        'verification_status' => 'pending',
    ];

    protected $fillable = [
        'organization_id',
        'shipment_id',
        'shipment_event_id',
        'document_category',
        'original_filename',
        'storage_key',
        'disk',
        'mime_type',
        'file_size',
        'uploaded_by',
        'description',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'document_category' => EvidenceDocumentCategory::class,
            'verification_status' => EvidenceVerificationStatus::class,
        ];
    }

    /**
     * The shipment this evidence supports.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The event this evidence supports, if it is tied to one.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ShipmentEvent::class, 'shipment_event_id');
    }

    /**
     * The user who uploaded this file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
