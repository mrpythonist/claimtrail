<?php

namespace App\Tms;

use App\EvidenceDocumentCategory;
use App\ShipmentStatus;

/**
 * Maps provider records onto ClaimTrail records (v1.3.2).
 *
 * The provider status vocabulary is the one the mock returns
 * and the Trimble schema model assumes: planned, dispatched,
 * in_transit, delivered, completed. It is an assumption, not a
 * verified fact: no live TMW.Suite system has been observed,
 * and the real OData order status values may differ. When a
 * live provider lands, this mapper is where the real values
 * get pinned down. An unknown status maps to null and the
 * sync skips that record instead of guessing.
 */
class TrimbleTmwMapper
{
    /**
     * Provider status values the mapper recognises.
     *
     * @return array<int, string>
     */
    public static function providerStatuses(): array
    {
        return ['planned', 'dispatched', 'in_transit', 'delivered', 'completed'];
    }

    /**
     * Map a provider status to a shipment status, or null
     * when the value is unknown.
     */
    public static function toShipmentStatus(string $providerStatus): ?ShipmentStatus
    {
        return match (strtolower(trim($providerStatus))) {
            'planned' => ShipmentStatus::Planned,
            'dispatched', 'in_transit' => ShipmentStatus::InTransit,
            'delivered' => ShipmentStatus::Delivered,
            'completed' => ShipmentStatus::Completed,
            default => null,
        };
    }

    /**
     * Map a provider document type to an evidence category.
     */
    public static function toEvidenceCategory(string $documentType): EvidenceDocumentCategory
    {
        return match (strtolower(trim($documentType))) {
            'proof_of_delivery', 'pod' => EvidenceDocumentCategory::ProofOfDelivery,
            'bill_of_lading', 'bol' => EvidenceDocumentCategory::BillOfLading,
            'rate_confirmation', 'ratecon' => EvidenceDocumentCategory::RateConfirmation,
            'appointment_confirmation' => EvidenceDocumentCategory::AppointmentConfirmation,
            default => EvidenceDocumentCategory::Other,
        };
    }

    /**
     * Map a normalized provider shipment record onto the
     * shipment attributes the app stores. Only fields the
     * shipment table carries are returned; the provider
     * status is returned separately so the caller can apply
     * the forward-only transition rule.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function toShipmentAttributes(array $record): array
    {
        $attributes = [
            'external_load_reference' => $record['external_reference'] ?? null,
            'internal_reference' => $record['internal_reference'] ?? null,
            'broker_or_customer_name' => $record['broker_or_customer_name'] ?? null,
            'pickup_location' => $record['pickup_location'] ?? null,
            'delivery_location' => $record['delivery_location'] ?? null,
            'pickup_appointment' => $record['pickup_appointment'] ?? null,
            'delivery_appointment' => $record['delivery_appointment'] ?? null,
            'actual_arrival_at' => $record['actual_arrival_at'] ?? null,
            'actual_departure_at' => $record['actual_departure_at'] ?? null,
            'base_rate' => $record['base_rate'] ?? null,
            'currency' => $record['currency'] ?? 'USD',
        ];

        return array_filter($attributes, fn ($value) => $value !== null);
    }
}
