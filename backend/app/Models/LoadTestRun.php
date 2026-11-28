<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One bounded local synthetic load run (v2.0.3).
 *
 * target is health (the public health endpoint) or shipments
 * (the authenticated shipment list, run with the caller's own
 * credentials). Requests are dispatched through the HTTP kernel
 * in-process, so no external service is ever touched and the
 * run measures this instance only. requests is capped by
 * config/enterprise.php load_test.max_requests.
 */
class LoadTestRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const TARGET_HEALTH = 'health';

    public const TARGET_SHIPMENTS = 'shipments';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public static function targets(): array
    {
        return [self::TARGET_HEALTH, self::TARGET_SHIPMENTS];
    }

    protected $fillable = [
        'organization_id',
        'name',
        'target',
        'requests',
        'status',
        'total_requests',
        'success_count',
        'error_count',
        'avg_ms',
        'p95_ms',
        'max_ms',
        'requests_per_second',
        'detail',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'requests' => 'integer',
            'total_requests' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
            'avg_ms' => 'decimal:2',
            'p95_ms' => 'decimal:2',
            'max_ms' => 'decimal:2',
            'requests_per_second' => 'decimal:2',
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
