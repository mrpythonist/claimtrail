<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's channel choice for one notification event type in
 * one organization.
 *
 * No row means the defaults: in-app on, email on. NotificationService
 * reads these rows before notifying anyone, so a member who turns a
 * channel off for an event type never hears about it on that channel.
 */
class NotificationPreference extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'event_type',
        'in_app',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => NotificationType::class,
            'in_app' => 'boolean',
            'email' => 'boolean',
        ];
    }

    /**
     * The member this preference belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
