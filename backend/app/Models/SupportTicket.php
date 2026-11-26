<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer support ticket inside one organization (v1.2.3).
 *
 * The requester is a member of the organization. Triage and
 * replies are done by the organization's owner and admins; the
 * operator works tickets across organizations with
 * `php artisan support:list` / `support:show`.
 *
 * Categories: billing, technical, account, feature_request,
 * other. Priorities: low, normal, high, urgent. Statuses: open
 * (new, awaiting triage), in_progress (being worked), waiting_on_
 * customer (a reply is needed from the requester), resolved (the
 * work is done, awaiting confirmation), closed (done).
 *
 * A customer reply to a resolved ticket reopens it to open.
 * Ticket bodies and messages are never written to the audit log:
 * they may contain customer data the audit trail should not
 * duplicate.
 */
class SupportTicket extends Model
{
    use BelongsToOrganization, HasUuids;

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_TECHNICAL = 'technical';

    public const CATEGORY_ACCOUNT = 'account';

    public const CATEGORY_FEATURE_REQUEST = 'feature_request';

    public const CATEGORY_OTHER = 'other';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WAITING_ON_CUSTOMER = 'waiting_on_customer';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'organization_id',
        'requester_id',
        'subject',
        'category',
        'priority',
        'status',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_BILLING,
            self::CATEGORY_TECHNICAL,
            self::CATEGORY_ACCOUNT,
            self::CATEGORY_FEATURE_REQUEST,
            self::CATEGORY_OTHER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_IN_PROGRESS,
            self::STATUS_WAITING_ON_CUSTOMER,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ];
    }

    /**
     * Whether the ticket still needs work.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_OPEN,
            self::STATUS_IN_PROGRESS,
            self::STATUS_WAITING_ON_CUSTOMER,
        ], true);
    }

    /**
     * The member who opened the ticket.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * The conversation on the ticket, oldest first.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /**
     * The public messages: everything every member of the
     * organization may read. Internal notes are excluded.
     */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }
}
