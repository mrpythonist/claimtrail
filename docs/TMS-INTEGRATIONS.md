# TMS Integrations

A generic TMS (transportation management system) provider
adapter with a mock provider and Trimble TMW.Suite modelled
schema-only. Syncs apply provider records to shipments,
shipment statuses, appointments, and documents (stored as
evidence). See docs/API.md for the endpoint table and
docs/openapi.yaml for the machine-readable contract (tag
`TMS`, 10 operations).

## The honest state of this feature

No live TMS is contacted anywhere in v1.3.2. The mock
provider syncs three fixed dummy loads. The Trimble
provider models the public TMW.Suite API surface from
Trimble's published documentation (developer.trimble.com,
checked 2026-11-26) and never performs a live HTTP call:
every fetch throws a live-unavailable error, and the
connection test only validates the credential shape.

Live TMW.Suite access is per-customer gated: a customer's
IT installs an on-prem Cloud Hub Connector (publicly
reachable over HTTPS), a TTC account and API Key
application are provisioned through a Trimble
representative, and a CloudHub configuration record maps
the account to that connector. No self-serve sandbox
exists. Until a TMW customer, a Trimble rep, and an
on-prem connector exist, the Trimble provider stays
schema-only, and the app says so on the provider
catalogue, the connection test, and every failed sync run.

This version also serves larger and enterprise customers,
not the pilot beachhead: the pilot profiles run on phone,
email, spreadsheets, and a load board, not a TMS, and a
carrier already on a full TMS is a pilot disqualifier.
The priority list below is a market-presence judgement,
not a customer-used list: no pilot customer exists yet
and no interviews have been done.

## Providers

Aqib's priority list (2026-11-26):

1. **Trimble TMW.Suite** — strong market presence and
   documented APIs. Modelled schema-only in v1.3.2.
2. **McLeod LoadMaster** — strong operational capabilities
   and integration ecosystem. Not built: partner-gated
   (the vendor's partner programme), no public sandbox.
3. **AscendTMS** — worth investigating for smaller
   carriers. Not built: no public self-serve developer
   API was found for shipment, status, appointment, and
   document sync.
4. **TruckMate** — established carrier platform. Not
   built: licensed access, keys generated from a Windows
   desktop app, on-prem or single-tenant hosted.
5. **Rose Rocket** — modern workflows and collaboration.
   Not built: the most builder-friendly public API of
   the five, and the rational first live provider when
   one is wanted.

Each provider implements the `App\Tms\TmsProvider`
contract: name, label, live availability, capabilities,
credential validation, shipment fetch, status fetch,
appointment fetch, document fetch, and the request
descriptor (the HTTP request a live call would make).
The `App\Tms\TmsProviderRegistry` resolves driver names;
adding a vendor is one registry entry plus the provider
class. Unknown drivers are refused with 422.

## The Trimble schema model

The published endpoints (developer.trimble.com, checked
2026-11-26):

- Token: `POST https://cloud.api.trimblecloud.com/transportation/api/identity/v2/oauth2/token`
  (OAuth 2.0 client credentials, scope `sas_token`).
- OData API: `https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v0/odata/`
- CloudHub API: `https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v2/`
  (Drivers v3: `/v3/`).
- OrderCreate API: `https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v0/automation/`

The provider's request descriptors pin these URLs in
code (see `TrimbleTmwProvider::requestDescriptor`). The
OData entity set names used there (`Orders`,
`Appointments`, `Documents`) are modelled, not verified
against a live system: no live TMW system has been
observed. The provider status vocabulary the mapper
assumes (`planned`, `dispatched`, `in_transit`,
`delivered`, `completed`) is the same kind of assumption,
and an unknown status is skipped rather than guessed.
When a live provider lands, the mapper
(`App\Tms\TrimbleTmwMapper`) is where the real values
get pinned down.

## Integrations

An integration connects one organization to one provider:

```json
{
  "provider": "mock",
  "name": "Mock TMS",
  "credentials": {},
  "settings": {
    "sync_shipments": true,
    "sync_status": true,
    "sync_appointments": true,
    "sync_documents": true,
    "auto_sync": false,
    "sync_interval_hours": 24
  }
}
```

Credentials are stored encrypted and are never returned
by the API: the payload says only whether any are stored
and, for Trimble, which fields are present. Trimble
needs `client_id`, `client_secret`, and `account_id`;
missing fields are refused with 422. The mock needs no
credentials. Names are unique per organization. Status
is `active` or `disabled`; a disabled integration never
syncs. Managing integrations needs the
`manage-tms-integrations` permission (owner, admin,
operations manager, dispatcher). Any member may list
integrations, read the provider catalogue, and read sync
runs.

The connection test (`POST
/tms/integrations/{integration}/test`) authenticates
against the provider without syncing: the mock always
succeeds, Trimble succeeds when the credentials are
well-formed, and both report `live_available: false`
with a plain-language explanation.

## Syncs

A sync runs one kind — `shipments`, `status`,
`appointments`, `documents` — or `full`, which runs the
kinds the integration settings enable. An explicit kind
runs regardless of settings. Every run is recorded as a
TmsSyncRun with its kind, status, per-outcome summary,
and failure reason; members who can manage integrations
are notified on completion or failure
(`tms.sync_completed`, `tms.sync_failed`).

- **Shipments:** provider loads are applied by external
  reference first, then internal reference. Missing
  shipments are created; existing ones are updated.
  Status changes follow the forward-only transition
  rule (draft to planned to in transit to delivered to
  completed); a blocked transition leaves the status
  alone.
- **Status:** the shipment status is updated where the
  transition is allowed. Shipments the provider knows
  but the app does not are skipped, not created.
- **Appointments:** pickup and delivery appointments
  and actual arrival and departure times are updated on
  matched shipments.
- **Documents:** each provider document is stored as an
  evidence file on the matched shipment, with the
  document type mapped to an evidence category (proof of
  delivery, bill of lading, rate confirmation,
  appointment confirmation, other). A document with the
  same filename already on the shipment is skipped, so
  re-running a sync never duplicates evidence.

Re-running a full mock sync creates nothing new: the
second run reports zero created, zero updated, and zero
documents stored. A run over a schema-only provider
fails with the live-unavailable message, and the failure
is recorded on the integration (`last_error`) and in the
run history.

Auto-sync runs daily at 05:00 (`tms:run-due`): active
integrations with auto-sync on whose last sync is older
than their interval run a full sync. The `tms:sync`
command runs one integration on demand, and `tms:list`
lists integrations across organizations for the
operator.

## What this does not do

- No live TMS integration exists. The Trimble provider
  is a schema model, not an integration.
- No other provider is built: McLeod, AscendTMS,
  TruckMate, and Rose Rocket are follow-ups, gated on
  partner access or a vendor account.
- TMS sync does not fix the missing submission channel:
  the app still never sends a claim package to a broker;
  `submitted` is a status the member sets by hand. That
  gap is separate (see
  docs/PRODUCTION-LAUNCH-CHECKLIST.md).
