<?php

namespace App;

/**
 * Document categories for evidence files.
 */
enum EvidenceDocumentCategory: string
{
    case BillOfLading = 'bill_of_lading';
    case ProofOfDelivery = 'proof_of_delivery';
    case RateConfirmation = 'rate_confirmation';
    case ArrivalDepartureRecord = 'arrival_departure_record';
    case AppointmentConfirmation = 'appointment_confirmation';
    case BrokerCommunication = 'broker_communication';
    case DriverPhoto = 'driver_photo';
    case Receipt = 'receipt';
    case LumperReceipt = 'lumper_receipt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BillOfLading => 'Bill of Lading',
            self::ProofOfDelivery => 'Proof of Delivery',
            self::RateConfirmation => 'Rate Confirmation',
            self::ArrivalDepartureRecord => 'Arrival and Departure Record',
            self::AppointmentConfirmation => 'Appointment Confirmation',
            self::BrokerCommunication => 'Broker Communication',
            self::DriverPhoto => 'Driver Photograph',
            self::Receipt => 'Receipt',
            self::LumperReceipt => 'Lumper Receipt',
            self::Other => 'Other',
        };
    }

    /**
     * Every category value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }
}
