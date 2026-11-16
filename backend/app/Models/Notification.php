<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification for one member of one organization.
 *
 * Rows are created by NotificationService, never directly by
 * controllers. A row with a dedupe key is unique per organization
 * and user, so a repeated job run cannot notify twice about the
 * same event. The data payload holds entity links (claim_id and
 * friends) so the frontend can link into the underlying record.
 */
class Notification extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'dedupe_key',
        'read_at',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    /**
     * The member this notification is for.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the notification is still unread.
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Mark the notification read.
     */
    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Scope to unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to one recipient.
     */
    public function scopeForUser($query, User|int $user)
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where('user_id', $id);
    }
}
