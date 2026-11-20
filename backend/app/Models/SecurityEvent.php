<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One security-relevant event (v1.1.3).
 *
 * Failed logins and authorization denials are recorded here
 * rather than in the tenant audit trail: a failed login has no
 * organization yet, and a denial is about the request, not about
 * a business entity. Rows are append-only in practice (there are
 * no update endpoints); retention purging is the only deletion
 * path. The metadata never holds a password, a token, or a
 * storage key.
 */
class SecurityEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    public const TYPE_LOGIN_FAILED = 'login.failed';

    public const TYPE_AUTHORIZATION_DENIED = 'authorization.denied';

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * The user the event concerns, when one is known.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
