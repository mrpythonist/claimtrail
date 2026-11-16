<?php

namespace App\Models;

use App\GeneratedDocumentStatus;
use App\GeneratedDocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocument extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $attributes = [
        'document_type' => 'claim_package',
        'includes_internal_notes' => false,
        'status' => 'queued',
    ];

    protected $fillable = [
        'organization_id',
        'claim_id',
        'document_type',
        'storage_key',
        'disk',
        'file_size',
        'generated_by',
        'includes_internal_notes',
        'status',
        'error_message',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => GeneratedDocumentType::class,
            'includes_internal_notes' => 'boolean',
            'status' => GeneratedDocumentStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    /**
     * The claim this document was generated for.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The user who requested the generation.
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Whether the PDF is ready to download.
     */
    public function isReady(): bool
    {
        return $this->status === GeneratedDocumentStatus::Ready
            && $this->storage_key !== null;
    }
}
