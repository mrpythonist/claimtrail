<?php

namespace App\Tms;

/**
 * The Trimble TMW.Suite provider, schema-only (v1.3.2).
 *
 * This provider models the public TMW.Suite API surface from
 * Trimble's published documentation (developer.trimble.com,
 * checked 2026-11-26) and NEVER performs a live HTTP call.
 * There is no live test of this provider anywhere in the
 * app: live TMW.Suite access is per-customer gated (a
 * customer's IT installs an on-prem Cloud Hub Connector, a
 * TTC account and API Key application are provisioned through
 * a Trimble representative, and a CloudHub configuration
 * record maps the account to that connector), and no
 * self-serve sandbox exists.
 *
 * What this provider does:
 * - validates the credential shape against the published
 *   OAuth 2.0 client-credentials requirements (client id,
 *   client secret, account id);
 * - builds the request descriptors (method, URL, headers)
 *   a live integration would send, from the published
 *   endpoints, so the schema is pinned in code and tested;
 * - throws TmsProviderException live_unavailable from every
 *   fetch method, with a plain-language explanation of what
 *   is missing.
 *
 * When a live TMW customer exists, the fetch methods are
 * where the HTTP calls go, using these same descriptors
 * plus the stored credentials. Nothing else changes.
 */
class TrimbleTmwProvider implements TmsProvider
{
    public function name(): string
    {
        return 'trimble-tmw';
    }

    public function label(): string
    {
        return 'Trimble TMW.Suite';
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

    /**
     * The credential fields the published OAuth 2.0 flow
     * needs: a client id and client secret from a TTC API
     * Key application, and the TTC account id the CloudHub
     * configuration maps to the customer's connector.
     *
     * @return array<int, string>
     */
    public static function requiredCredentialFields(): array
    {
        return ['client_id', 'client_secret', 'account_id'];
    }

    public function authenticate(array $credentials): array
    {
        $missing = [];

        foreach (self::requiredCredentialFields() as $field) {
            if (! isset($credentials[$field]) || trim((string) $credentials[$field]) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'live_available' => false,
                'message' => 'Trimble credentials are incomplete: missing '.implode(', ', $missing).'. A client id and client secret come from a TTC API Key application, provisioned through a Trimble representative.',
                'token' => null,
                'expires_at' => null,
            ];
        }

        return [
            'ok' => true,
            'live_available' => false,
            'message' => 'Credentials are well-formed, but no live TMW.Suite system is reachable from here. Live access needs a TMW customer whose IT has installed an on-prem Cloud Hub Connector, plus a TTC account and API Key application provisioned through a Trimble representative. No live call was made.',
            'token' => null,
            'expires_at' => null,
        ];
    }

    public function fetchShipments(array $credentials, array $params = []): array
    {
        throw $this->liveUnavailable('shipments');
    }

    public function fetchShipmentStatus(array $credentials, string $externalReference): array
    {
        throw $this->liveUnavailable('shipment status');
    }

    public function fetchAppointments(array $credentials, string $externalReference): array
    {
        throw $this->liveUnavailable('appointments');
    }

    public function fetchDocuments(array $credentials, string $externalReference): array
    {
        throw $this->liveUnavailable('documents');
    }

    public function requestDescriptor(string $operation, array $params = []): array
    {
        $odata = (string) config('tms.providers.trimble-tmw.odata_base');
        $orderCreate = (string) config('tms.providers.trimble-tmw.order_create_base');

        $descriptors = [
            'token' => [
                'method' => 'POST',
                'url' => config('tms.providers.trimble-tmw.token_url'),
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => ['grant_type' => 'client_credentials', 'scope' => 'sas_token'],
            ],
            'shipments' => [
                'method' => 'GET',
                'url' => $odata.'/Orders',
                'headers' => ['Authorization' => 'Bearer <access_token>', 'Accept' => 'application/json'],
                'body' => null,
            ],
            'shipment_status' => [
                'method' => 'GET',
                'url' => $odata.'/Orders('.($params['external_reference'] ?? '{order_id}').')',
                'headers' => ['Authorization' => 'Bearer <access_token>', 'Accept' => 'application/json'],
                'body' => null,
            ],
            'appointments' => [
                'method' => 'GET',
                'url' => $odata.'/Orders('.($params['external_reference'] ?? '{order_id}').')/Appointments',
                'headers' => ['Authorization' => 'Bearer <access_token>', 'Accept' => 'application/json'],
                'body' => null,
            ],
            'documents' => [
                'method' => 'GET',
                'url' => $odata.'/Orders('.($params['external_reference'] ?? '{order_id}').')/Documents',
                'headers' => ['Authorization' => 'Bearer <access_token>', 'Accept' => 'application/json'],
                'body' => null,
            ],
            'order_create' => [
                'method' => 'POST',
                'url' => $orderCreate.'/OrderCreate',
                'headers' => ['Authorization' => 'Bearer <access_token>', 'Content-Type' => 'application/json'],
                'body' => ['order' => '{order}'],
            ],
        ];

        return $descriptors[$operation] ?? [
            'method' => 'GET',
            'url' => $odata.'/'.$operation,
            'headers' => ['Authorization' => 'Bearer <access_token>', 'Accept' => 'application/json'],
            'body' => null,
        ];
    }

    /**
     * The exception every live method throws: the schema is
     * modelled, the live system is not reachable.
     */
    protected function liveUnavailable(string $what): TmsProviderException
    {
        return new TmsProviderException(
            TmsProviderException::CODE_LIVE_UNAVAILABLE,
            'Trimble TMW.Suite '.$what.' cannot be fetched: no live TMW system is reachable. Live access needs a TMW customer whose IT has installed an on-prem Cloud Hub Connector, plus a TTC account and API Key application provisioned through a Trimble representative. This provider is schema-only until then.'
        );
    }
}
