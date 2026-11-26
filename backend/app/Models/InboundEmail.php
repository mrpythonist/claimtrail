<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inbound email received for an organization (v1.3.0).
 *
 * Statuses: received (stored, not yet processed), processed
 * (extraction ran, kept only as a transient step), matched
 * (linked to exactly one shipment), unmatched (zero or several
 * shipments matched, awaiting manual matching), failed
 * (processing threw), dismissed (a member reviewed it and set
 * it aside with a reason). Email bodies are never written to
 * the audit log; only the status change and the matched
 * shipment are.
 */
class InboundEmail extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'organization_id',
        'inbound_email_address_id',
        'from',
        'to',
        'subject',
        'text_body',
        'html_body',
        'message_id',
        'status',
        'matched_shipment_id',
        'extracted_references',
        'match_reason',
        'match_confidence',
        'dismissed_reason',
        'processing_notes',
    ];

    protected function casts(): array
    {
        return [
            'extracted_references' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSED,
            self::STATUS_MATCHED,
            self::STATUS_UNMATCHED,
            self::STATUS_FAILED,
            self::STATUS_DISMISSED,
        ];
    }

    /**
     * The address the email was sent to.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(InboundEmailAddress::class, 'inbound_email_address_id');
    }

    /**
     * The shipment this email was matched to, if any.
     */
    public function matchedShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'matched_shipment_id');
    }

    /**
     * Attachments decoded from this email.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(InboundEmailAttachment::class);
    }
}
