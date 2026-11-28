<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A service-level objective for one organization (v2.0.3).
 *
 * key is availability, error_rate, or latency_p95. Rows are
 * seeded from config/enterprise.php on first read; an owner
 * may change the target, the window, or disable an SLO. The
 * target is an objective, not a guarantee: the measured value
 * is computed from this instance's own recorded data (see
 * SloService) and carries its sample size, or reports
 * insufficient data when there is not enough history yet.
 */
class Slo extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KEY_AVAILABILITY = 'availability';

    public const KEY_ERROR_RATE = 'error_rate';

    public const KEY_LATENCY_P95 = 'latency_p95';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [
            self::KEY_AVAILABILITY,
            self::KEY_ERROR_RATE,
            self::KEY_LATENCY_P95,
        ];
    }

    protected $fillable = [
        'organization_id',
        'key',
        'name',
        'kind',
        'target_value',
        'target_unit',
        'window_days',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'window_days' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
