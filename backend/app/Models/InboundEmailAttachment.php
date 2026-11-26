<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment decoded from an inbound email (v1.3.0).
 *
 * The file bytes live on the private disk under storage_path.
 * When the email is matched to a shipment, an EvidenceFile row
 * is created for the attachment and linked here, so the same
 * stored object is never duplicated.
 */
class InboundEmailAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'inbound_email_id',
        'filename',
        'mime_type',
        'size_bytes',
        'storage_path',
        'checksum',
        'evidence_file_id',
    ];

    /**
     * The email this attachment arrived with.
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class, 'inbound_email_id');
    }

    /**
     * The evidence row created from this attachment, if the
     * email was matched to a shipment.
     */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(EvidenceFile::class, 'evidence_file_id');
    }
}
