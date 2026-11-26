<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund of money already paid on an invoice (v1.2.3).
 *
 * Full or partial, with a required reason, recorded against the
 * provider (mock here, Stripe later) and in the audit trail. The
 * refund policy itself is not decided in code: until Aqib
 * publishes a written policy, refunds are issued case by case by
 * the organization's billing managers, and the reason is the
 * record of why. See docs/BILLING.md, "Refunds and
 * cancellations".
 *
 * Statuses: pending (provider call made, record not yet
 * confirmed), succeeded, failed. A failed refund changes
 * nothing on the invoice.
 */
class Refund extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'requested_by',
        'amount_cents',
        'reason',
        'status',
        'provider_refund_id',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    /**
     * The invoice this refund returns money on.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The member who requested the refund, if still present.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
