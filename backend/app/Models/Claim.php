<?php

namespace App\Models;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Claim extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Defaults that mirror the database column defaults, so a freshly
     * created model reads its status and currency before any refresh.
     */
    protected $attributes = [
        'currency' => 'USD',
        'status' => 'potential',
    ];

    protected $fillable = [
        'organization_id',
        'claim_reference',
        'shipment_id',
        'shipment_event_id',
        'claim_category',
        'description',
        'requested_amount',
        'currency',
        'accepted_amount',
        'recovered_amount',
        'internal_notes',
        'external_broker_reference',
        'submission_deadline_at',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'paid_at',
        'rejection_reason',
        'payment_reference',
        'status',
        'custom_field_values',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'claim_category' => ClaimCategory::class,
            'requested_amount' => 'decimal:2',
            'accepted_amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
            'custom_field_values' => 'array',
            'submission_deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
            'status' => ClaimStatus::class,
        ];
    }

    /**
     * The shipment this claim is about.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The shipment event behind this claim, if it is tied to one.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ShipmentEvent::class, 'shipment_event_id');
    }

    /**
     * The user who created this claim.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this claim.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Evidence files attached to this claim.
     */
    public function evidenceFiles(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceFile::class, 'claim_evidence')
            ->withPivot(['attached_by'])
            ->withTimestamps();
    }

    /**
     * Append-only status history, oldest first.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(ClaimStatusHistory::class)->orderBy('created_at');
    }

    /**
     * Reviewer notes on this claim, oldest first.
     */
    public function reviewNotes(): HasMany
    {
        return $this->hasMany(ClaimReviewNote::class)->orderBy('created_at');
    }

    /**
     * Readiness snapshots for this claim.
     */
    public function requirementSnapshots(): HasMany
    {
        return $this->hasMany(ClaimRequirement::class);
    }

    /**
     * All deadlines for this claim, including superseded ones.
     */
    public function deadlines(): HasMany
    {
        return $this->hasMany(ClaimDeadline::class)->orderByDesc('created_at');
    }

    /**
     * The active deadline, if one is set.
     */
    public function activeDeadline(): HasOne
    {
        return $this->hasOne(ClaimDeadline::class)->whereNull('superseded_at');
    }

    /**
     * Generated claim package documents, newest first.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class)->orderByDesc('created_at');
    }

    /**
     * The newest ready claim package, if one exists.
     */
    public function latestReadyDocument(): HasOne
    {
        return $this->hasOne(GeneratedDocument::class)
            ->where('status', 'ready')
            ->latestOfMany();
    }

    /**
     * Whether the claim is closed. Closed is terminal: nothing about
     * a closed claim changes except reading it.
     */
    public function isClosed(): bool
    {
        return $this->status === ClaimStatus::Closed;
    }

    /**
     * Whether the claim is paid. A paid claim keeps its financial
     * record immutable; only closing it is still allowed.
     */
    public function isPaid(): bool
    {
        return $this->status === ClaimStatus::Paid;
    }

    /**
     * Whether field edits (amount, category, shipment link) are still
     * allowed. Once a claim is submitted, its substance is frozen;
     * only notes and the external broker reference stay editable.
     */
    public function allowsFieldEdits(): bool
    {
        return $this->status->isPreSubmission();
    }
}
