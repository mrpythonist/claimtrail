<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\DeadlineSetting;
use App\Models\ExportTemplate;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions, value validation, export columns, and
 * deadline-setting resolution for one organization.
 *
 * Definitions are per organization and resource type (`shipment` or
 * `claim`). Values are stored as a JSON map on the shipment or claim
 * row, keyed by field_key. Only active definitions accept new
 * values; values stored under a deactivated definition are kept on
 * the record but no longer validated or offered as export columns.
 */
class CustomFieldService
{
    /**
     * Fixed shipment export columns, in export order.
     *
     * @var array<int, string>
     */
    public const SHIPMENT_COLUMNS = [
        'internal_reference',
        'external_load_reference',
        'broker_or_customer_name',
        'carrier',
        'driver_name',
        'driver_contact',
        'pickup_location',
        'delivery_location',
        'pickup_appointment',
        'delivery_appointment',
        'actual_arrival_at',
        'actual_departure_at',
        'base_rate',
        'currency',
        'status',
        'notes',
    ];

    /**
     * Fixed claim export columns, in export order.
     *
     * @var array<int, string>
     */
    public const CLAIM_COLUMNS = [
        'claim_reference',
        'shipment_reference',
        'broker_or_customer_name',
        'claim_category',
        'description',
        'requested_amount',
        'accepted_amount',
        'recovered_amount',
        'currency',
        'status',
        'submission_deadline_at',
        'submitted_at',
        'created_at',
    ];

    /**
     * Active definitions for a resource type, in display order.
     *
     * @return Collection<int, CustomField>
     */
    public function definitions(Organization $organization, string $resourceType): Collection
    {
        return $organization->customFields()
            ->where('resource_type', $resourceType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /**
     * Validate and normalize custom field values.
     *
     * The supplied values are merged over the existing stored values
     * (for updates), so a partial update keeps untouched fields.
     * Unknown keys in the supplied values, missing required fields,
     * and wrong types all fail with a ValidationException keyed
     * `custom_field_values.<key>`. Values stored under definitions
     * that are no longer active are kept untouched, not validated.
     *
     * @param  array<string, mixed>|null  $values
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateValues(
        Organization $organization,
        string $resourceType,
        ?array $values,
        ?array $existing = null,
    ): array {
        $definitions = $this->definitions($organization, $resourceType)
            ->keyBy('field_key');

        $supplied = $values ?? [];
        $errors = [];

        foreach ($supplied as $key => $value) {
            if (! $definitions->has($key)) {
                $errors['custom_field_values.'.$key] = 'Unknown custom field.';
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $merged = array_merge($existing ?? [], $this->normalizeSupplied($definitions, $supplied, $errors));

        foreach ($definitions as $key => $definition) {
            $value = $merged[$key] ?? null;

            if ($definition->is_required && $this->isEmpty($value)) {
                $errors['custom_field_values.'.$key] = 'This custom field is required.';
                continue;
            }

            if ($value === null || $this->isEmpty($value)) {
                continue;
            }

            $this->validateTypedValue($definition, $value, $errors);
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        // Return defined keys in definition order first, then any
        // kept values from deactivated definitions, untouched.
        $ordered = [];

        foreach ($definitions as $key => $definition) {
            if (array_key_exists($key, $merged)) {
                $ordered[$key] = $merged[$key];
            }
        }

        foreach ($merged as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /**
     * Export columns for a resource type: the fixed columns plus one
     * `custom:<field_key>` entry per active custom field.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function availableExportColumns(string $resourceType, Organization $organization): array
    {
        $fixed = $resourceType === 'claim'
            ? self::CLAIM_COLUMNS
            : self::SHIPMENT_COLUMNS;

        $columns = array_map(fn (string $column) => [
            'key' => $column,
            'label' => Str::headline($column),
        ], $fixed);

        foreach ($this->definitions($organization, $resourceType) as $definition) {
            $columns[] = [
                'key' => 'custom:'.$definition->field_key,
                'label' => $definition->label,
            ];
        }

        return $columns;
    }

    /**
     * Resolve the deadline setting that applies to a claim.
     *
     * Precedence: a claim_category match, then an event_category
     * match, then the global setting. Returns null when the
     * organization has no matching setting.
     */
    public function resolveDeadlineSetting(
        Organization $organization,
        ?string $claimCategory,
        ?string $eventCategory,
    ): ?DeadlineSetting {
        $settings = $organization->deadlineSettings()->get();

        $byScope = static function (Collection $settings, string $scope, ?string $value): ?DeadlineSetting {
            if ($value === null || $value === '') {
                return null;
            }

            return $settings->first(
                fn (DeadlineSetting $setting) => $setting->scope_type === $scope
                    && $setting->scope_value === $value
            );
        };

        return $byScope($settings, 'claim_category', $claimCategory)
            ?? $byScope($settings, 'event_category', $eventCategory)
            ?? $settings->first(fn (DeadlineSetting $setting) => $setting->scope_type === 'global');
    }

    /**
     * Normalize supplied values to their stored types, collecting
     * type errors instead of throwing on the first one.
     *
     * @param  Collection<string, CustomField>  $definitions
     * @param  array<string, mixed>  $supplied
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    protected function normalizeSupplied(Collection $definitions, array $supplied, array &$errors): array
    {
        $normalized = [];

        foreach ($supplied as $key => $value) {
            $definition = $definitions->get($key);

            if ($definition === null) {
                continue;
            }

            if ($value === null || $this->isEmpty($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if ($definition->field_type === 'boolean') {
                $normalized[$key] = $this->normalizeBoolean($value, $key, $errors);
                continue;
            }

            if ($definition->field_type === 'number' && ! is_numeric($value)) {
                $errors['custom_field_values.'.$key] = 'This custom field must be a number.';
                continue;
            }

            $normalized[$key] = $definition->field_type === 'number'
                ? $value + 0
                : $value;
        }

        return $normalized;
    }

    /**
     * Type-check one stored value against its definition.
     *
     * @param  array<string, string>  $errors
     */
    protected function validateTypedValue(CustomField $definition, mixed $value, array &$errors): void
    {
        $key = 'custom_field_values.'.$definition->field_key;

        match ($definition->field_type) {
            'text' => $this->validateText($definition, $value, $errors, $key),
            'number' => $this->validateNumber($value, $errors, $key),
            'date' => $this->validateDate($value, $errors, $key),
            'select' => $this->validateSelect($definition, $value, $errors, $key),
            'boolean' => $this->validateBoolean($value, $errors, $key),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function validateText(CustomField $definition, mixed $value, array &$errors, string $key): void
    {
        if (! is_string($value)) {
            $errors[$key] = 'This custom field must be text.';
        } elseif (mb_strlen($value) > 500) {
            $errors[$key] = 'This custom field must not be longer than 500 characters.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function validateNumber(mixed $value, array &$errors, string $key): void
    {
        if (! is_numeric($value)) {
            $errors[$key] = 'This custom field must be a number.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function validateDate(mixed $value, array &$errors, string $key): void
    {
        if (! is_string($value)) {
            $errors[$key] = 'This custom field must be a date.';
            return;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $errors[$key] = 'This custom field must be a valid date in Y-m-d format.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function validateSelect(CustomField $definition, mixed $value, array &$errors, string $key): void
    {
        $options = $definition->options ?? [];

        if (! is_string($value) || ! in_array($value, $options, true)) {
            $errors[$key] = 'This custom field must be one of the allowed options.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function validateBoolean(mixed $value, array &$errors, string $key): void
    {
        if (! is_bool($value)) {
            $errors[$key] = 'This custom field must be true or false.';
        }
    }

    /**
     * Normalize a boolean input: true booleans, 0/1, and the strings
     * "true" and "false" are accepted; anything else is an error.
     *
     * @param  array<string, string>  $errors
     */
    protected function normalizeBoolean(mixed $value, string $key, array &$errors): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        $errors['custom_field_values.'.$key] = 'This custom field must be true or false.';

        return null;
    }

    /**
     * Whether a value counts as empty for required-field checks.
     */
    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Clear the default flag on every other template of the same
     * organization and resource type.
     */
    public function clearOtherDefaults(Organization $organization, string $resourceType, ?string $exceptId = null): void
    {
        $organization->exportTemplates()
            ->where('resource_type', $resourceType)
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
