<?php

/*
|--------------------------------------------------------------------------
| TMS integrations
|--------------------------------------------------------------------------
|
| TMS (transportation management system) integrations for ClaimTrail
| (v1.3.2). The TMS provider is behind an interface
| (App\Tms\TmsProvider) so the provider can be swapped without
| rewriting sync logic. The default driver is the mock provider:
| it implements the same shape as the Trimble TMW.Suite
| integration specified in docs/TMS-INTEGRATIONS.md against
| deterministic dummy data, with no live TMS calls and no
| vendor keys.
|
| The Trimble provider is schema-only: it models the public
| TMW.Suite API surface from Trimble's published documentation
| (developer.trimble.com, checked 2026-11-26) and never performs
| a live call. Live TMW.Suite access is per-customer gated: a
| customer's IT installs an on-prem Cloud Hub Connector, a TTC
| account and API Key application are provisioned through a
| Trimble representative, and a CloudHub configuration record
| maps the account to that connector. No self-serve sandbox
| exists. The endpoint URLs below are the published ones; the
| OData entity set names the provider uses (Orders,
| Appointments, Documents) are modelled, not verified against
| a live system.
|
| To wire a live provider later: implement TmsProvider against
| the vendor's HTTP API, register the driver in the registry,
| and leave every other file alone. The mock's load references
| (TMW-MOCK-*) are recognisably fake and never collide with
| real provider ids.
|
*/

return [

    /*
    | The default provider driver for new integrations:
    | "mock". Only "mock" and "trimble-tmw" are implemented
    | in v1.3.2; the other platforms on the priority list
    | (McLeod LoadMaster, AscendTMS, TruckMate, Rose Rocket)
    | are partner-gated follow-ups and are not drivers yet.
    */
    'default_provider' => env('TMS_PROVIDER', 'mock'),

    /*
    | Provider endpoint configuration. The Trimble URLs are
    | the published TTC endpoints (developer.trimble.com,
    | checked 2026-11-26). The mock has no endpoints.
    */
    'providers' => [
        'mock' => [
            'label' => 'Mock TMS',
            'live_available' => false,
        ],
        'trimble-tmw' => [
            'label' => 'Trimble TMW.Suite',
            'live_available' => false,
            'service_root' => 'https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite',
            'odata_base' => 'https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v0/odata',
            'cloudhub_base' => 'https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v2',
            'order_create_base' => 'https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v0/automation',
            'token_url' => 'https://cloud.api.trimblecloud.com/transportation/api/identity/v2/oauth2/token',
            'token_scope' => 'sas_token',
        ],
    ],

    /*
    | How often an auto-sync integration is due, in hours.
    | The tms:run-due command picks up active integrations
    | with auto-sync on whose last sync is older than this.
    */
    'auto_sync_interval_hours' => 24,

];
