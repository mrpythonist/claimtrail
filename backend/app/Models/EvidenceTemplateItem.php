<?php

namespace App\Models;

use App\EvidenceDocumentCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document requirement inside an evidence template.
 */
class EvidenceTemplateItem extends Model
{
    use HasUuids;

    protected $attributes = [
        'is_required' => true,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'evidence_template_id',
        'document_category',
        'label',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'document_category' => EvidenceDocumentCategory::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The template this item belongs to.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EvidenceTemplate::class, 'evidence_template_id');
    }
}
