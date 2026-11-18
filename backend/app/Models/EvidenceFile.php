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
        'checksum',
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
     * MIME types the preview endpoint serves inline. Everything
     * else (spreadsheets, documents, text) downloads instead: the
     * browser has no reliable inline viewer for them.
     */
    public const PREVIEWABLE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Whether this file can be previewed inline in the browser.
     */
    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, self::PREVIEWABLE_MIME_TYPES, true);
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
