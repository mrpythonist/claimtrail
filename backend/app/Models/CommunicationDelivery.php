<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message attempt through a communication channel
 * (v1.3.3).
 *
 * status is logged (recorded locally, no live delivery —
 * every provider in v1.3.3), sent (a live delivery,
 * never in v1.3.3), or failed. payload holds the
 * would-be vendor payload, so the history shows exactly
 * what would have been sent. detail carries the plain
 * explanation of what happened.
 */
class CommunicationDelivery extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_LOGGED = 'logged';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'communication_channel_id',
        'event',
        'title',
        'body',
        'status',
        'detail',
        'payload',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * The channel this delivery went through.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class, 'communication_channel_id');
    }
}
