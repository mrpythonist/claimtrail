<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One TMS sync execution (v1.3.2).
 *
 * kind is shipments, status, appointments, documents, or
 * full (all four). Statuses: pending (created, never run),
 * running (a sync is in flight), completed (the provider
 * answered and the records were applied), failed (the
 * provider could not be reached, credentials were invalid,
 * or applying a record failed). summary carries the
 * per-outcome counts; error_message the failure reason. A
 * run over a schema-only provider fails with the live
 * unavailable message: the run is the honest record that
 * no live sync happened.
 */
class TmsSyncRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KIND_SHIPMENTS = 'shipments';

    public const KIND_STATUS = 'status';

    public const KIND_APPOINTMENTS = 'appointments';

    public const KIND_DOCUMENTS = 'documents';

    public const KIND_FULL = 'full';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'tms_integration_id',
        'kind',
        'status',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Every sync kind.
     *
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_SHIPMENTS,
            self::KIND_STATUS,
            self::KIND_APPOINTMENTS,
            self::KIND_DOCUMENTS,
            self::KIND_FULL,
        ];
    }

    /**
     * The integration this run belongs to.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(TmsIntegration::class, 'tms_integration_id');
    }

    /**
     * The user who started this run (null for scheduler runs).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
