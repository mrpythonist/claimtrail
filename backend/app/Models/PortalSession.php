<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mock billing portal session.
 *
 * Created by POST /billing/portal; the returned token opens the
 * portal view (GET /billing/portal/{token}) for 30 minutes. Only
 * the SHA-256 hash of the token is stored, so a database read
 * alone does not open anyone's portal.
 */
class PortalSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * The organization the portal session belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether the session is still valid.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && now()->lt($this->expires_at);
    }
}
