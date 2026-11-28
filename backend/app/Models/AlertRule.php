<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monitoring alert rule (v2.0.3).
 *
 * metric is one of the config/enterprise.php alert metrics,
 * measured from local data only (queue tables, failed jobs,
 * the local error log, the evidence disk, backup history).
 * operator is gt or gte; the rule breaches when the observed
 * value crosses the threshold in that direction. window_minutes
 * is the lookback for windowed metrics (error rate); point
 * metrics (queue depth, failed jobs, disk usage, backup age)
 * read the current value. enabled=false pauses evaluation
 * without deleting the rule.
 */
class AlertRule extends Model
{
    use BelongsToOrganization, HasUuids;

    public const METRIC_QUEUE_DEPTH = 'queue_depth';

    public const METRIC_FAILED_JOBS = 'failed_jobs';

    public const METRIC_ERROR_RATE = 'error_rate';

    public const METRIC_DISK_USAGE_PERCENT = 'disk_usage_percent';

    public const METRIC_BACKUP_AGE_HOURS = 'backup_age_hours';

    public const OPERATOR_GT = 'gt';

    public const OPERATOR_GTE = 'gte';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * @return array<int, string>
     */
    public static function metrics(): array
    {
        return [
            self::METRIC_QUEUE_DEPTH,
            self::METRIC_FAILED_JOBS,
            self::METRIC_ERROR_RATE,
            self::METRIC_DISK_USAGE_PERCENT,
            self::METRIC_BACKUP_AGE_HOURS,
        ];
    }

    protected $fillable = [
        'organization_id',
        'name',
        'metric',
        'operator',
        'threshold',
        'window_minutes',
        'enabled',
        'severity',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:4',
            'window_minutes' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(EnterpriseAlert::class, 'alert_rule_id');
    }
}
