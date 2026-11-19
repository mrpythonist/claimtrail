<?php

namespace App\Observers;

use App\Models\Claim;
use App\Models\ClaimDeadline;
use App\Models\CustomField;
use App\Models\DeadlineSetting;
use App\Models\EventCategory;
use App\Models\EvidenceFile;
use App\Models\EvidenceTemplate;
use App\Models\ExportTemplate;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records audit rows for model lifecycle events.
 *
 * One observer serves every audited model. The action name comes
 * from the model and the event (shipment.created, claim.updated),
 * with semantic names where the change means something specific:
 * an invitation update that sets accepted_at is invitation.accepted,
 * a member status change is member.activated or member.deactivated,
 * and deleting an invitation is invitation.revoked.
 *
 * Only the auditable fields listed below reach the before/after
 * payloads. Claim status changes are not audited here: the
 * transition service writes its own richer row and suspends the
 * observer while it does, so a transition is recorded exactly once.
 */
class AuditObserver
{
    /**
     * Fields that may appear in before/after payloads, per model.
     * Contact details, notes, tokens, and storage keys are never
     * listed here.
     *
     * @var array<class-string, array<int, string>>
     */
    protected const AUDITABLE = [
        Shipment::class => [
            'internal_reference', 'external_load_reference',
            'broker_or_customer_name', 'carrier_id', 'driver_name',
            'pickup_location', 'delivery_location', 'status',
            'base_rate', 'currency',
        ],
        ShipmentEvent::class => [
            'event_category_id', 'event_date', 'status',
            'estimated_amount', 'currency', 'location',
        ],
        EvidenceFile::class => [
            'document_category', 'original_filename',
            'mime_type', 'file_size', 'verification_status',
        ],
        Claim::class => [
            'claim_reference', 'requested_amount', 'accepted_amount',
            'recovered_amount', 'currency', 'claim_category',
            'external_broker_reference',
        ],
        ClaimDeadline::class => [
            'deadline_at', 'timezone', 'status', 'source_reference',
        ],
        CustomField::class => [
            'resource_type', 'field_key', 'label', 'field_type',
            'is_required', 'is_active',
        ],
        EventCategory::class => [
            'slug', 'label', 'is_active', 'default_estimated_amount',
            'default_currency',
        ],
        EvidenceTemplate::class => [
            'name', 'is_active',
        ],
        DeadlineSetting::class => [
            'scope_type', 'scope_value', 'submit_within_days',
        ],
        ExportTemplate::class => [
            'resource_type', 'name', 'is_default',
        ],
        OrganizationMember::class => [
            'user_id', 'role', 'status',
        ],
        OrganizationInvitation::class => [
            'email', 'role', 'accepted_at', 'expires_at',
        ],
    ];

    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * Audit a creation.
     */
    public function created(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        $action = $this->entityName($model).'.created';

        if ($model instanceof OrganizationMember) {
            $action = 'member.added';
        }

        $this->audit->recordFor(
            $model,
            $action,
            after: $this->auditableAttributes($model),
        );
    }

    /**
     * Audit an update, but only when an auditable field changed.
     */
    public function updated(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        $changed = array_intersect(
            array_keys($model->getChanges()),
            $this->auditableFields($model),
        );

        if (empty($changed)) {
            return;
        }

        $before = [];
        $after = [];

        foreach ($changed as $field) {
            $before[$field] = $model->getOriginal($field);
            $after[$field] = $model->getAttribute($field);
        }

        $this->audit->recordFor(
            $model,
            $this->updateAction($model, $changed),
            before: $before,
            after: $after,
        );
    }

    /**
     * Audit a deletion. The model instance still holds its
     * attributes in the deleted event, so the before payload is
     * the record as it was.
     */
    public function deleted(Model $model): void
    {
        if ($this->shouldSkip($model)) {
            return;
        }

        $action = $this->entityName($model).'.deleted';

        if ($model instanceof OrganizationInvitation) {
            $action = 'invitation.revoked';
        }

        if ($model instanceof OrganizationMember) {
            $action = 'member.removed';
        }

        $this->audit->recordFor(
            $model,
            $action,
            before: $this->auditableAttributes($model),
        );
    }

    /**
     * Whether this model event should produce no audit row: auditing
     * suspended (a service is writing its own row), or a model this
     * observer does not cover.
     */
    protected function shouldSkip(Model $model): bool
    {
        return $this->audit->isSuspended()
            || ! array_key_exists($model::class, self::AUDITABLE);
    }

    /**
     * The semantic action for an update: member status and role
     * changes and invitation acceptance get their own names.
     *
     * @param  array<int, string>  $changed
     */
    protected function updateAction(Model $model, array $changed): string
    {
        if ($model instanceof OrganizationMember) {
            if (in_array('status', $changed, true)) {
                return $model->status->value === 'active'
                    ? 'member.activated'
                    : 'member.deactivated';
            }

            if (in_array('role', $changed, true)) {
                return 'member.role_changed';
            }

            return 'member.updated';
        }

        if ($model instanceof OrganizationInvitation
            && in_array('accepted_at', $changed, true)
            && $model->accepted_at !== null
        ) {
            return 'invitation.accepted';
        }

        return $this->entityName($model).'.updated';
    }

    /**
     * The snake-case entity name used in action strings.
     */
    protected function entityName(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    /**
     * Auditable fields for a model.
     *
     * @return array<int, string>
     */
    protected function auditableFields(Model $model): array
    {
        return self::AUDITABLE[$model::class] ?? [];
    }

    /**
     * The model's current auditable attributes.
     *
     * @return array<string, mixed>
     */
    protected function auditableAttributes(Model $model): array
    {
        $attributes = [];

        foreach ($this->auditableFields($model) as $field) {
            $attributes[$field] = $model->getAttribute($field);
        }

        return $attributes;
    }
}
