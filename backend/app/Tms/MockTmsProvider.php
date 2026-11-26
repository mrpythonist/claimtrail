<?php

namespace App\Tms;

/**
 * The mock TMS provider (v1.3.2).
 *
 * Implements the TmsProvider contract against deterministic
 * dummy data, so the full sync flow works end to end with no
 * live TMS, no vendor account, and no keys. Every id it
 * returns is recognisably fake (TMW-MOCK-*, DOC-MOCK-*).
 *
 * The dummy fleet is three loads, fixed so tests and the
 * smoke run can assert exact counts: one in transit with a
 * proof of delivery and a bill of lading, one delivered with
 * a rate confirmation, and one planned with no documents.
 * Re-running a sync over the same loads changes nothing the
 * second time (dedupe on the external reference), which is
 * what the idempotency checks assert.
 */
class MockTmsProvider implements TmsProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Mock TMS';
    }

    public function liveAvailable(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            'shipments' => true,
            'status' => true,
            'appointments' => true,
            'documents' => true,
            'webhooks' => false,
        ];
    }

    public function authenticate(array $credentials): array
    {
        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'Mock provider connected. No live TMS is contacted; syncs run against dummy data.',
            'token' => 'tok_mock_'.hash('sha256', 'mock-tms-provider'),
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
    }

    public function fetchShipments(array $credentials, array $params = []): array
    {
        $shipments = self::loads();

        if (isset($params['limit'])) {
            $shipments = array_slice($shipments, 0, max(0, (int) $params['limit']));
        }

        return $shipments;
    }

    public function fetchShipmentStatus(array $credentials, string $externalReference): array
    {
        $load = $this->findLoad($externalReference);

        if ($load === null) {
            throw new TmsProviderException(
                TmsProviderException::CODE_PROVIDER_ERROR,
                'Mock TMS has no load with external reference "'.$externalReference.'".'
            );
        }

        return [
            'external_reference' => $load['external_reference'],
            'status' => $load['status'],
            'updated_at' => $load['updated_at'],
        ];
    }

    public function fetchAppointments(array $credentials, string $externalReference): array
    {
        $load = $this->findLoad($externalReference);

        if ($load === null) {
            throw new TmsProviderException(
                TmsProviderException::CODE_PROVIDER_ERROR,
                'Mock TMS has no load with external reference "'.$externalReference.'".'
            );
        }

        return [[
            'pickup_appointment' => $load['pickup_appointment'],
            'delivery_appointment' => $load['delivery_appointment'],
            'actual_arrival_at' => $load['actual_arrival_at'],
            'actual_departure_at' => $load['actual_departure_at'],
            'pickup_location' => $load['pickup_location'],
            'delivery_location' => $load['delivery_location'],
        ]];
    }

    public function fetchDocuments(array $credentials, string $externalReference): array
    {
        $load = $this->findLoad($externalReference);

        if ($load === null) {
            throw new TmsProviderException(
                TmsProviderException::CODE_PROVIDER_ERROR,
                'Mock TMS has no load with external reference "'.$externalReference.'".'
            );
        }

        return array_map(function (array $doc) use ($externalReference) {
            $content = 'Mock TMS document '.$doc['filename'].' for load '.$externalReference.".\n";

            return array_merge($doc, [
                'content' => $content,
                'size_bytes' => strlen($content),
            ]);
        }, $load['documents']);
    }

    public function requestDescriptor(string $operation, array $params = []): array
    {
        return [
            'method' => 'GET',
            'url' => 'mock://tms/'.$operation,
            'headers' => ['Accept' => 'application/json'],
            'body' => null,
        ];
    }

    /**
     * Find one dummy load by its external reference.
     *
     * @return array<string, mixed>|null
     */
    protected function findLoad(string $externalReference): ?array
    {
        foreach (self::loads() as $load) {
            if ($load['external_reference'] === $externalReference) {
                return $load;
            }
        }

        return null;
    }

    /**
     * The dummy fleet: three loads with fixed references,
     * statuses, appointments, and documents.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loads(): array
    {
        return [
            [
                'external_reference' => 'TMW-MOCK-1001',
                'internal_reference' => 'CT-TMS-1',
                'broker_or_customer_name' => 'Heartland Foods',
                'pickup_location' => 'Kansas City, MO',
                'delivery_location' => 'Dallas, TX',
                'pickup_appointment' => '2026-11-27T09:00:00Z',
                'delivery_appointment' => '2026-11-28T14:00:00Z',
                'actual_arrival_at' => null,
                'actual_departure_at' => null,
                'status' => 'in_transit',
                'base_rate' => 1250.00,
                'currency' => 'USD',
                'updated_at' => '2026-11-26T18:00:00Z',
                'documents' => [
                    [
                        'external_id' => 'DOC-MOCK-1',
                        'type' => 'proof_of_delivery',
                        'filename' => 'pod-1001.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                    [
                        'external_id' => 'DOC-MOCK-2',
                        'type' => 'bill_of_lading',
                        'filename' => 'bol-1001.pdf',
                        'mime_type' => 'application/pdf',

                    ],
                ],
            ],
            [
                'external_reference' => 'TMW-MOCK-1002',
                'internal_reference' => 'CT-TMS-2',
                'broker_or_customer_name' => 'Ozark Supply',
                'pickup_location' => 'Springfield, MO',
                'delivery_location' => 'Memphis, TN',
                'pickup_appointment' => '2026-11-25T08:00:00Z',
                'delivery_appointment' => '2026-11-26T11:00:00Z',
                'actual_arrival_at' => '2026-11-26T10:47:00Z',
                'actual_departure_at' => '2026-11-26T12:15:00Z',
                'status' => 'delivered',
                'base_rate' => 980.00,
                'currency' => 'USD',
                'updated_at' => '2026-11-26T12:15:00Z',
                'documents' => [
                    [
                        'external_id' => 'DOC-MOCK-3',
                        'type' => 'rate_confirmation',
                        'filename' => 'ratecon-1002.pdf',
                        'mime_type' => 'application/pdf',

                    ],
                ],
            ],
            [
                'external_reference' => 'TMW-MOCK-1003',
                'internal_reference' => 'CT-TMS-3',
                'broker_or_customer_name' => 'Blue Ridge Logistics',
                'pickup_location' => 'Charlotte, NC',
                'delivery_location' => 'Atlanta, GA',
                'pickup_appointment' => '2026-11-29T07:30:00Z',
                'delivery_appointment' => '2026-11-29T16:00:00Z',
                'actual_arrival_at' => null,
                'actual_departure_at' => null,
                'status' => 'planned',
                'base_rate' => 740.00,
                'currency' => 'USD',
                'updated_at' => '2026-11-26T09:30:00Z',
                'documents' => [],
            ],
        ];
    }
}
