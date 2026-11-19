<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable named set of evidence document requirements.
 *
 * A template is a named checklist an organization can apply to a
 * claim: applying it creates a ClaimRequirement row for each
 * document category in the template that the claim does not already
 * have a requirement row for.
 */
class EvidenceTemplate extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The template items, in display order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(EvidenceTemplateItem::class)->orderBy('sort_order');
    }
}
