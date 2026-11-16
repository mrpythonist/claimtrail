<?php

namespace App\Services;

use App\ShipmentStatus;
use Illuminate\Validation\Rule;

/**
 * The single definition of shipment field validation.
 *
 * The shipment controller and the CSV importer both use these rules so
 * a row that passes import validation would also pass the create
 * endpoint. Status transitions are not validated here: the controller
 * checks them against the current status after validation.
 */
class ShipmentRules
{
    /**
     * Rules for creating a shipment.
     *
     * New shipments start as draft or planned only. The unique check
     * on internal_reference is scoped to the organization.
     *
     * @return array<string, mixed>
     */
    public static function createRules(string $organizationId, bool $withUnique = true): array
    {
        $reference = ['required', 'string', 'max:255'];

        if ($withUnique) {
            $reference[] = Rule::unique('shipments', 'internal_reference')
                ->where('organization_id', $organizationId);
        }

        return [
            'internal_reference' => $reference,
            'external_load_reference' => ['nullable', 'string', 'max:255'],
            'broker_or_customer_name' => ['required', 'string', 'max:255'],
            'carrier_id' => [
                'nullable', 'uuid',
                Rule::exists('carriers', 'id')->where('organization_id', $organizationId),
            ],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:255'],
            'pickup_location' => ['nullable', 'string', 'max:500'],
            'delivery_location' => ['nullable', 'string', 'max:500'],
            'pickup_appointment' => ['nullable', 'date'],
            'delivery_appointment' => ['nullable', 'date'],
            'actual_arrival_at' => ['nullable', 'date'],
            'actual_departure_at' => ['nullable', 'date'],
            'base_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'status' => ['nullable', 'string', Rule::in([
                ShipmentStatus::Draft->value,
                ShipmentStatus::Planned->value,
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Rules for updating a shipment.
     *
     * Every field is optional. The status rule only checks the value
     * is a real status; the controller checks the transition is
     * allowed from the current status.
     *
     * @return array<string, mixed>
     */
    public static function updateRules(string $organizationId, string $shipmentId): array
    {
        $rules = self::createRules($organizationId, false);

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_merge(['sometimes'], $fieldRules);
        }

        $rules['internal_reference'] = [
            'sometimes', 'required', 'string', 'max:255',
            Rule::unique('shipments', 'internal_reference')
                ->where('organization_id', $organizationId)
                ->ignore($shipmentId),
        ];

        $rules['status'] = [
            'sometimes', 'string', Rule::in(ShipmentStatus::values()),
        ];

        return $rules;
    }
}
