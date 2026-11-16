<?php

namespace App\Models;

use App\EvidenceDocumentCategory;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceRequirement extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'is_required' => true,
    ];

    protected $fillable = [
        'organization_id',
        'event_category_id',
        'document_category',
        'is_required',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_category' => EvidenceDocumentCategory::class,
            'is_required' => 'boolean',
        ];
    }

    /**
     * The event category this requirement applies to, or null when it
     * applies to every event type.
     */
    public function eventCategory(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * The user who created this requirement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
