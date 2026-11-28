<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monitoring alert: one recorded breach of an alert rule
 * (v2.0.3).
 *
 * status is active, acknowledged, or resolved. Only one active
 * alert is kept per rule: a repeat breach while one is active
 * updates the observed value and message instead of opening a
 * second row, so the list stays a list of open problems, not a
 * log of every evaluation. The alert_rule_id is nullable so a
 * deleted rule keeps its history.
 */
class EnterpriseAlert extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'alerts';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'organization_id',
        'alert_rule_id',
        'metric',
        'observed_value',
        'threshold',
        'severity',
        'status',
        'message',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_value' => 'decimal:4',
            'threshold' => 'decimal:4',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED], true);
    }
}
