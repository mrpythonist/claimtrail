<?php

namespace App\Models;

use App\AuditActorType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only audit record.
 *
 * Audit rows are written by AuditService and never changed: the
 * model cancels update and delete attempts, the table has no
 * updated_at column, and there are no update or delete API routes.
 * The before/after payloads hold only changed scalar fields; they
 * never contain passwords, tokens, file contents, or storage keys.
 */
class AuditLog extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * No updated_at column exists; created_at is set explicitly by
     * AuditService, so Eloquent timestamps stay off.
     */
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'actor_type',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'correlation_id',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: an update or delete of an audit row is
        // always a bug, so cancel it instead of letting it happen.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /**
     * The user who performed the action, if it was a user.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Scope to one entity type (Shipment, Claim, ...).
     */
    public function scopeOfEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Scope to one action (shipment.created, claim.status_changed, ...).
     */
    public function scopeOfAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
