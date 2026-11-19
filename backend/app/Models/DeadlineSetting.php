<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization default for how soon after an event a claim must
 * be submitted.
 *
 * Scope precedence when resolving a setting for a claim is: a
 * claim_category match, then an event_category match, then the
 * global setting. A global setting has a null scope_value.
 */
class DeadlineSetting extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Scope types a deadline setting may use.
     *
     * @var array<int, string>
     */
    public const SCOPES = ['global', 'claim_category', 'event_category'];

    protected $fillable = [
        'organization_id',
        'scope_type',
        'scope_value',
        'submit_within_days',
        'reminder_days_before',
    ];

    protected function casts(): array
    {
        return [
            'submit_within_days' => 'integer',
            'reminder_days_before' => 'array',
        ];
    }

    /**
     * Whether this setting applies to every claim.
     */
    public function isGlobal(): bool
    {
        return $this->scope_type === 'global';
    }
}
