<?php

namespace App\Tms;

/**
 * The TMS provider contract (v1.3.2).
 *
 * Every TMS operation the app needs goes through this interface,
 * so the provider is a swap, not a rewrite. The mock provider
 * (MockTmsProvider) implements it against deterministic dummy
 * data; the Trimble provider (TrimbleTmwProvider) models the
 * public TMW.Suite API surface against its published schemas
 * and never performs a live call. A real live provider would
 * implement the same methods against the vendor's HTTP API.
 *
 * A provider never writes to the app database and never
 * creates app state: it answers provider-side questions and
 * returns normalized records. The TmsService applies those
 * records to shipments, events, appointments, and evidence.
 *
 * Normalized shipment record shape:
 *
 *   [
 *     'external_reference' => 'TMW-1001',   // provider load/order id
 *     'internal_reference' => 'CT-TMS-1',   // claimtrail reference, when the provider carries one
 *     'broker_or_customer_name' => '...',
 *     'pickup_location' => '...',
 *     'delivery_location' => '...',
 *     'pickup_appointment' => '2026-11-27T09:00:00Z'|null,
 *     'delivery_appointment' => '2026-11-28T14:00:00Z'|null,
 *     'actual_arrival_at' => '...Z'|null,
 *     'actual_departure_at' => '...Z'|null,
 *     'status' => 'planned|dispatched|in_transit|delivered|completed',
 *     'base_rate' => 1250.00|null,
 *     'currency' => 'USD',
 *   ]
 *
 * Normalized document record shape:
 *
 *   [
 *     'external_id' => 'DOC-1',
 *     'type' => 'proof_of_delivery|bill_of_lading|rate_confirmation|appointment_confirmation|other',
 *     'filename' => 'pod-1001.pdf',
 *     'mime_type' => 'application/pdf',
 *     'size_bytes' => 1234,
 *     'content' => '...',   // mock only: dummy file content; a live provider would download the file
 *   ]
 *
 * Authentication result shape:
 *
 *   [
 *     'ok' => bool,               // credentials are well-formed / accepted
 *     'live_available' => bool,   // a live TMS can actually be reached
 *     'message' => '...',         // plain-language explanation
 *     'token' => 'tok_...'|null,  // opaque token when one exists (mock only)
 *     'expires_at' => '...Z'|null,
 *   ]
 */
interface TmsProvider
{
    /**
     * The driver name, e.g. "mock" or "trimble-tmw". Stored on
     * the integration so a later swap can tell which provider
     * produced a sync run.
     */
    public function name(): string;

    /**
     * The human-readable provider label, e.g. "Mock TMS" or
     * "Trimble TMW.Suite".
     */
    public function label(): string;

    /**
     * Whether a live TMS can be reached through this provider
     * from this app. False for the mock (simulated data) and
     * false for the schema-only Trimble provider (no live
     * access until a TMW customer, a Trimble rep, and an
     * on-prem connector exist).
     */
    public function liveAvailable(): bool;

    /**
     * What the provider can do. Keys: shipments, status,
     * appointments, documents, webhooks. A live provider
     * would report the vendor's real surface; the mock and
     * the schema-only Trimble provider report the modelled
     * surface.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * Validate the stored credentials for this provider.
     * Never performs a live call: the mock accepts any
     * credentials (it needs none), and the Trimble provider
     * checks the credential shape against the published
     * OAuth 2.0 client-credentials requirements.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, live_available: bool, message: string, token: string|null, expires_at: string|null}
     */
    public function authenticate(array $credentials): array;

    /**
     * Fetch the provider's shipments (loads/orders).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $params  Optional filters: since, until, limit.
     * @return array<int, array<string, mixed>> Normalized shipment records.
     *
     * @throws TmsProviderException When the provider cannot be
     *                              reached (live_unavailable for a
     *                              schema-only provider).
     */
    public function fetchShipments(array $credentials, array $params = []): array;

    /**
     * Fetch the current status of one provider shipment.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{external_reference: string, status: string, updated_at: string|null}
     *
     * @throws TmsProviderException
     */
    public function fetchShipmentStatus(array $credentials, string $externalReference): array;

    /**
     * Fetch the appointments of one provider shipment.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<int, array{pickup_appointment: string|null, delivery_appointment: string|null, actual_arrival_at: string|null, actual_departure_at: string|null, pickup_location: string|null, delivery_location: string|null}>
     *
     * @throws TmsProviderException
     */
    public function fetchAppointments(array $credentials, string $externalReference): array;

    /**
     * Fetch the documents of one provider shipment.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<int, array<string, mixed>> Normalized document records.
     *
     * @throws TmsProviderException
     */
    public function fetchDocuments(array $credentials, string $externalReference): array;

    /**
     * The HTTP request the live call for an operation would
     * make (method, URL, headers without secrets, body).
     * This is the schema model: it documents the vendor API
     * surface and is never executed by a schema-only provider.
     * The mock returns a mock:// descriptor.
     *
     * @param  array<string, mixed>  $params
     * @return array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>|null}
     */
    public function requestDescriptor(string $operation, array $params = []): array;
}
