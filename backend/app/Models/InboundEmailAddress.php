<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One organization-specific inbound email address (v1.3.0).
 *
 * The full address is {local_part}@{configured domain}. The
 * local part is globally unique, so two organizations can never
 * share an address. Regenerating an address replaces its local
 * part; the old address stops working. Deactivating keeps the
 * row for history but mail to it is no longer accepted.
 */
class InboundEmailAddress extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'local_part',
        'label',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The full address, using the configured intake domain.
     */
    public function fullAddress(): string
    {
        return $this->local_part.'@'.config('email_intake.domain');
    }

    /**
     * Emails received on this address.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(InboundEmail::class);
    }
}
