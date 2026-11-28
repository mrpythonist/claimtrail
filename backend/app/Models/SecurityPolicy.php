<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's security policy (v2.0.3).
 *
 * One row per organization; until a row exists the config
 * defaults in config/enterprise.php apply. Only the password
 * rules are enforced by code (on password change): allowed IP
 * ranges are check-only and two-factor is recorded policy only,
 * because no second factor exists yet. See
 * SecurityPolicyService and docs/ENTERPRISE-READINESS.md.
 */
class SecurityPolicy extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'password_min_length',
        'password_require_numbers',
        'password_require_symbols',
        'session_timeout_minutes',
        'max_concurrent_sessions',
        'allowed_ip_ranges',
        'require_two_factor',
    ];

    protected function casts(): array
    {
        return [
            'password_min_length' => 'integer',
            'password_require_numbers' => 'boolean',
            'password_require_symbols' => 'boolean',
            'session_timeout_minutes' => 'integer',
            'max_concurrent_sessions' => 'integer',
            'allowed_ip_ranges' => 'array',
            'require_two_factor' => 'boolean',
        ];
    }
}
