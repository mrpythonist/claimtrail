<?php

namespace App\Models;

use App\DeadlineStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimDeadline extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Default that mirrors the database column default, so a freshly
     * created model reads its status before any refresh.
     */
    protected $attributes = [
        'status' => 'upcoming',
    ];

    protected $fillable = [
        'organization_id',
        'claim_id',
        'deadline_at',
        'timezone',
        'source_reference',
        'reminder_days_before',
        'status',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'datetime',
            'reminder_days_before' => 'array',
            'status' => DeadlineStatus::class,
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * The claim this deadline belongs to.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * Whether this row is the active deadline for its claim.
     */
    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * The reminder window in days: the largest configured reminder,
     * or 7 days when no reminders are set. The due-soon window uses
     * this value.
     */
    public function reminderWindowDays(): int
    {
        $days = $this->reminder_days_before ?? [];

        if (empty($days)) {
            return 7;
        }

        return max(array_map('intval', $days));
    }

    /**
     * Scope to active (non-superseded) deadlines only.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('superseded_at');
    }
}
