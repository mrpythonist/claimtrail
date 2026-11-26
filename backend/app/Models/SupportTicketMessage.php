<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a support ticket (v1.2.3).
 *
 * A message is public by default: every member of the ticket's
 * organization may read it. An internal note is visible only to
 * the organization's owner and admins, and is how support staff
 * record context for each other. A message with no user is a
 * system note (for example a status change recorded by the app).
 */
class SupportTicketMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'body',
        'internal',
    ];

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
        ];
    }

    /**
     * The ticket this message belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * The member who wrote the message, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
