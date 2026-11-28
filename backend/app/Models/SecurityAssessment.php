<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated enterprise security self-assessment (v2.0.3).
 *
 * This is a SELF-ASSESSMENT, not a penetration test and not an
 * external audit: it runs the existing security checks plus new
 * configuration checks against this instance's own data, and
 * records the standing unverified items (live Postgres, live
 * Redis, Docker, browser E2E, real mailer, pen test) as open
 * findings. findings is the full check list with per-check
 * status, label, detail, and category.
 */
class SecurityAssessment extends Model
{
    use BelongsToOrganization, HasUuids;

    public const OVERALL_PASS = 'pass';

    public const OVERALL_WARN = 'warn';

    public const OVERALL_FAIL = 'fail';

    protected $fillable = [
        'organization_id',
        'overall',
        'open_findings',
        'total_checks',
        'passed',
        'warnings',
        'failures',
        'findings',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'open_findings' => 'integer',
            'total_checks' => 'integer',
            'passed' => 'integer',
            'warnings' => 'integer',
            'failures' => 'integer',
            'findings' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
