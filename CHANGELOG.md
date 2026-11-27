# Changelog

All notable changes to ClaimTrail are documented here. The format follows
Keep a Changelog, and the project follows semantic versioning.

## [1.5.2] - 2026-11-27

Phase 5, Operational Intelligence (the last version of
Phase 5): detention trends, facility performance, rejection
reasons, missing-evidence trends, broker-level trends,
revenue leakage, and deterministic operational
recommendations, all computed from the organization's own
recorded claims, shipments, payments, and evidence. There
is no external benchmark, no industry data, and no live
payment verification: billing runs on a mock provider, so
a paid claim means the organization recorded the money as
received, not that a bank or processor confirmed it.
Leakage is gaps between recorded amounts, not proven lost
money. Recommendations are fixed rules over the org's own
numbers, each carrying the numbers it was derived from,
and each firing only when its stated minimum sample is
met. App version bumped to 1.5.2; OpenAPI at 188 paths
(181 + 7 new paths, 247 operations).

### Added

- Operational intelligence service
  `App\\Services\\OperationalIntelligenceService` (extends
  the analytics service, so the submitted population, rate
  rounding, and money totals have one definition each):
  detention trends are detention claims only, with the
  monthly series and a comparison against the preceding
  window; facilities group by the shipment's delivery
  location where one is recorded, otherwise its pickup
  location, otherwise "Unnamed facility"; rejection
  reasons are the reasons recorded on the claims, with
  unrecorded ones under "Not recorded" and no invented
  taxonomy; missing-evidence trends reuse the existing
  readiness computation over attached evidence against the
  organization's configured requirements; broker trends
  carry each broker's monthly series and its change
  against the preceding window; revenue leakage sums the
  per-claim v1.5.0 balances (requested vs approved is what
  the broker cut at approval, approved vs paid is the
  outstanding balance, overpaid is paid above the amount
  due, legacy is money recorded as paid outside the
  ledger, unsubmitted totals are pipeline, reported
  separately); recommendations are eight fixed rules with
  stated minimum samples and severity cutoffs chosen for
  this report. The analytics outstanding total is computed
  at the aggregate level while leakage sums per-claim
  balances, and the two can disagree when some claims
  have an accepted amount and others do not; the doc
  states the difference.
- Endpoints `GET /operations/detention-trends`,
  `/facilities`, `/rejection-reasons`,
  `/missing-evidence`, `/broker-trends`,
  `/revenue-leakage`, and `/recommendations`. All take
  optional `from` and `to` dates and default to the last
  12 months. Reading needs the existing `view-analytics`
  permission; Free-plan organizations are refused with
  402, the same as analytics and reports. No new
  notification types, no new console commands, no new
  migration, and no CSV export for the operations
  endpoints in this version.
- Frontend: `/operations` (recommendations, detention
  trends, facility and broker tables, rejection reasons,
  missing evidence, revenue leakage), typed client in
  `src/lib/operations.ts`, and an Operational Intelligence
  entry in the AppShell Insights section. Charts reuse the
  existing `CountBar` CSS bar component; no chart library
  was added.
- Docs: `docs/OPERATIONAL-INTELLIGENCE.md` (metric
  definitions and honest limits), a section in
  `docs/API.md`, and the Operational Intelligence tag and
  schemas in `docs/openapi.yaml`.

## [1.5.1] - 2026-11-27

Phase 5, Advanced Analytics: recovery and rejection rates,
average submission and payment times, broker, carrier, and
event-type performance, monthly and yearly comparisons, and
CSV exports, all computed from the organization's own
recorded claims, shipments, and payments. There is no
external benchmark, no industry data, and no live payment
verification: billing runs on a mock provider, so a paid
claim means the organization recorded the money as received,
not that a bank or processor confirmed it. Rates use the
submitted population only, money is summed as recorded with
no currency conversion, and every average carries its sample
size. App version bumped to 1.5.1; OpenAPI at 181 paths
(174 + 7 new paths, 240 operations).

### Added

- Analytics service `App\\Services\\ClaimAnalyticsService`
  with one definition per metric, enforced in one place:
  recovery vs requested is paid over requested across the
  submitted population, recovery vs approved is paid over
  approved across the submitted claims that have an accepted
  amount, rejection is rejected over submitted, average
  submission time is `submitted_at` minus `created_at`, and
  average payment time is `paid_at` minus `approved_at`
  where one exists, otherwise `paid_at` minus
  `submitted_at`. Rates are null when their denominator is
  zero, never zero dressed up as a measurement. Rates round
  to two decimals at six-decimal precision (400/600 is
  66.67, not 66.66); the live smoke caught the truncation
  and the service was fixed, with a unit test pinning the
  corrected rounding.
- Endpoints `GET /analytics/overview`, `/brokers`,
  `/carriers`, `/event-types`, `/monthly`, `/yearly`, and
  `GET /analytics/export?type=overview|brokers|carriers|
  event-types|monthly` (CSV only, no PDF or Excel). The
  overview adds a period comparison against the immediately
  preceding window of equal length, with changes in
  percentage points. The monthly series covers every month
  in the range, the yearly series defaults to the last three
  years, and the event-type table lists all seven categories
  including empty ones. All take optional `from` and `to`
  dates and default to the last 12 months.
- Permission `view-analytics` (owner, admin, operations
  manager, dispatcher, billing staff, viewer) for reading
  analytics; exports need the existing `export-reports`.
  Free-plan organizations are refused with 402, the same as
  reports. No new notification types and no new console
  commands: analytics reads what the app already recorded.
- Frontend: `/analytics` (rates, averages, period
  comparison, monthly table and bars, yearly bars, CSV
  exports), `/analytics/brokers`, `/analytics/carriers`,
  `/analytics/event-types` (performance tables with CSV
  export), typed client in `src/lib/claim-analytics.ts`,
  `canViewAnalytics` in `src/lib/permissions.ts`, and an
  Analytics entry in the AppShell Insights section. Charts
  reuse the existing `CountBar` CSS bar component; no chart
  library was added.
- Docs: `docs/ADVANCED-ANALYTICS.md` (metric definitions
  and honest limits), a section in `docs/API.md`, and the
  Analytics tag and schemas in `docs/openapi.yaml`.

## [1.5.0] - 2026-11-27

Phase 5, Financial Reconciliation: the claim payment ledger,
outstanding balances, and reconciliation reports. The ledger
records what the organization says it received; nothing here
moves real money and no payment is verified against a bank,
Stripe, or any processor, because billing runs on a mock
provider. Partial payments are normal: a claim stays accepted
until the paid total reaches the amount due. App version
bumped to 1.5.0; OpenAPI at 174 paths (167 + 7 new paths,
233 operations).

### Added

- Payment ledger: `App\\Models\\ClaimPayment` (amount,
  currency, payment method, payment reference, payment date,
  notes, recorder) with `GET /reconciliation/ledger` (filters:
  `claim_id`, `payment_method`, `from`, `to`),
  `GET /reconciliation/ledger/{payment}`,
  `POST /reconciliation/claims/{claim}/payments`, and
  `DELETE /reconciliation/ledger/{payment}` (void; refused
  with 422 on a paid or closed claim).
- Outstanding rule in one place,
  `ReconciliationService::balanceFor`: due is the approved
  amount, or the requested amount where no approval is
  recorded; paid is the claim `recovered_amount`; outstanding
  is max(0, due - paid). The claim balance endpoint
  (`GET /reconciliation/claims/{claim}`) also returns the
  ledger total, the legacy amount paid before the ledger
  existed, and the payment history.
- Reconciliation reports: `GET/POST /reconciliation/reports`
  and `GET /reconciliation/reports/{report}`. A report
  snapshots a period (requested, approved, paid, outstanding,
  counts) and flags discrepancies: overpaid, legacy payments,
  paid-but-outstanding, and payments on an unsubmitted claim.
  The `reconciliation:run` command generates the current
  month's report for organizations with ledger payments that
  month (daily at 06:00), skipping one already generated
  today.
- Organization summary: `GET /reconciliation/summary` with
  requested, approved, paid, and outstanding totals and
  counts by balance status.
- Permission `manage-reconciliation` (owner, admin,
  operations manager, dispatcher) for recording or voiding
  a payment and generating a report; any member may read the
  ledger, balances, summary, and reports. Notification types
  `reconciliation.payment_recorded` (partial payments) and
  `reconciliation.completed` (report generated); 24 types in
  total. The final payment still goes through the existing
  record-payment flow, so the success-fee metering runs
  exactly once.
- Frontend: `/reconciliation` (totals, ledger, reports),
  `/reconciliation/claims/[id]` (balance, payment history,
  record and void), `/reconciliation/reports/[id]` (period
  totals and discrepancies), typed client in
  `src/lib/reconciliation.ts`, `canManageReconciliation` in
  `src/lib/permissions.ts`, and a Reconciliation nav entry.
- Docs: `docs/FINANCIAL-RECONCILIATION.md` and a Financial
  Reconciliation section in `docs/API.md`.

## [1.3.2] - 2026-11-26

Phase 4, TMS Integrations: a generic TMS provider adapter
with a mock provider and Trimble TMW.Suite modelled
schema-only. No live TMS is contacted: the mock syncs
three fixed dummy loads, and the Trimble provider fails
every fetch with a live-unavailable message. App version
bumped to 1.3.2; OpenAPI at 155 paths (148 + 7 new paths,
207 operations).

### Added

- TMS provider adapter: `App\Tms\TmsProvider` contract
  (name, label, live availability, capabilities,
  credential validation, shipment, status, appointment,
  and document fetch, request descriptors) with a
  registry that resolves driver names. The mock provider
  implements it against deterministic dummy data (three
  loads: one in transit with a proof of delivery and a
  bill of lading, one delivered with a rate confirmation,
  one planned with no documents). The Trimble provider
  models the public TMW.Suite surface from the published
  docs (OAuth 2.0 client credentials, token URL, scope
  `sas_token`, OData, CloudHub, and OrderCreate
  endpoints) and never performs a live call.
- TMS integrations: `GET /tms/providers` (provider
  catalogue with live availability and credential
  fields), `GET/POST /tms/integrations`, `GET/PUT/DELETE
  /tms/integrations/{integration}`. Credentials are
  stored encrypted and never returned; Trimble needs a
  client id, client secret, and account id. Settings
  select which kinds sync, whether auto-sync runs, and
  the interval. Managing integrations needs the new
  `manage-tms-integrations` permission (owner, admin,
  operations manager, dispatcher); any member may list
  and read.
- Connection test: `POST
  /tms/integrations/{integration}/test` authenticates
  without syncing. The mock always succeeds; Trimble
  succeeds when the credentials are well-formed. Both
  report `live_available: false` with a plain-language
  explanation.
- Syncs: `POST /tms/integrations/{integration}/sync`
  with kind `shipments`, `status`, `appointments`,
  `documents`, or `full`. Shipments are created or
  updated by external reference (then internal
  reference); statuses follow the forward-only
  transition rule; appointments fill appointment and
  actual times; documents are stored as evidence files
  with duplicate filenames skipped. Re-running a full
  mock sync creates nothing new.
- Sync monitoring: `GET /tms/sync-runs` (filters
  `tms_integration_id`, `kind`, `status`), `GET
  /tms/sync-runs/{syncRun}`. Every run records its kind,
  status, per-outcome summary, and failure reason, and
  members who can manage integrations are notified
  (`tms.sync_completed`, `tms.sync_failed`). A run over
  a schema-only provider fails with the live-unavailable
  message, recorded on the integration as `last_error`.
- Auto-sync and CLI: `tms:run-due` runs due auto-syncs
  daily at 05:00; `tms:sync` runs one integration on
  demand; `tms:list` lists integrations across
  organizations.
- Frontend: `/tms` (provider catalogue, connect form,
  integration list) and `/tms/[id]` (settings,
  connection test, sync controls, run history), with a
  TMS Integrations entry in the sidebar.
- Docs: `docs/TMS-INTEGRATIONS.md` documents the
  adapter, the mock provider, the Trimble schema model
  and its gating, the priority list with the follow-up
  providers, and the honest limits (no live test, no
  other provider built, pilot beachhead unaffected,
  submission channel still missing).

## [1.3.3] - 2026-11-27

Phase 4, Communication Integrations: Slack and
Microsoft Teams channels per organization, driver
evidence submission links, and the email workflows
around them. No live Slack workspace, Teams tenant, or
real mailer is contacted: the mock provider records
deliveries locally, and Slack and Teams record a
well-formed payload without sending it anywhere. App
version bumped to 1.3.3; OpenAPI at 167 paths (155 +
12 new paths, 224 operations).

### Added

- Channel providers: `App\Comms\ChannelProvider`
  contract (name, label, live availability, webhook
  URL requirement and validation, payload shape) with
  a registry that resolves driver names. The mock
  provider needs no webhook URL and records every
  delivery locally. The Slack provider validates
  `https://hooks.slack.com/services/...` URLs and
  builds a Block Kit payload; the Teams provider
  validates Office 365 connector webhook URLs and
  builds a MessageCard payload. Neither performs a live
  call, and every provider reports `live_available:
  false`.
- Communication channels: `GET /comms/providers`
  (provider catalogue), `GET/POST /comms/channels`,
  `GET/PUT/DELETE /comms/channels/{channel}`.
  Webhook URLs are stored encrypted and never returned
  by the API. Channels subscribe to five events
  (`claim_created`, `evidence_received`,
  `driver_link_used`, `sync_completed`,
  `delivery_failed`). Managing channels needs the new
  `manage-communications` permission (owner, admin,
  operations manager, dispatcher); any member may list
  and read.
- Channel dispatcher and deliveries: one dispatcher
  fans events out to the active channels that want
  them and records every delivery (`logged`, `sent`,
  `failed`) with its payload and error message.
  `GET /comms/deliveries` (filters `channel_id`,
  `event`, `status`), `GET
  /comms/deliveries/{delivery}`. A send failure updates
  the channel's `last_error` and notifies the
  organization.
- Connection test: `POST
  /comms/channels/{channel}/test` checks the webhook
  shape and records a test delivery. The mock always
  succeeds; Slack and Teams succeed when the URL is
  well-formed. All report `live_available: false` with
  a plain-language explanation. A malformed stored URL
  records a failed delivery instead of sending.
- Driver evidence links: `GET/POST
  /comms/driver-links`, `GET/DELETE
  /comms/driver-links/{driverLink}`, `POST
  /comms/driver-links/{driverLink}/resend`, `GET
  /comms/driver-links/{driverLink}/submissions`. A link
  is scoped to one shipment, one claim, or both (which
  must match), expires after `ttl_hours` (1-720,
  default 72), is single-use by default (`max_uses`
  1-10), and can be revoked immediately. Only the
  SHA-256 hash is stored; the raw token is returned
  once, at creation or resend, and a resend rotates it.
  Evidence from a claim-only link is filed against the
  claim's shipment.
- Public driver evidence page: `GET
  /driver-evidence/{token}` and `POST
  /driver-evidence/{token}/submit` (multipart, file
  max 20 MB, verified by checksum and size before
  acceptance, stored on the evidence disk). No account
  is needed. Unknown, expired, used-up, or revoked
  tokens all return the same 404 with the same message.
  Both endpoints are throttled.
- Email workflows: driver-link emails on create and
  resend (`DriverEvidenceLinkMail`, queued). A failed
  send is caught, logged, and notified; the link stays
  usable. Evidence received through a link notifies the
  organization in-app and fires the `evidence_received`
  channel event.
- Communication notifications: seven new types
  (`comms.channel_connected`,
  `comms.channel_tested`, `comms.driver_link_created`,
  `comms.driver_link_used`,
  `comms.driver_link_expired`, `comms.email_sent`,
  `comms.email_failed`). Preferences now list 22 types.
  Channel tests and email sends use per-run unique
  dedupe keys so repeats notify again.
- Expiry command: `comms:expire-links` notifies once
  per expired link (links expired more than 30 days ago
  are skipped), scheduled daily at 05:30. CLI:
  `comms:list` lists channels across organizations;
  `comms:test {channel}` tests one channel.
- Frontend: `/communications` (provider catalogue,
  connect form, channels, create-a-driver-link form
  showing the token once, driver links, recent
  deliveries), `/communications/[id]` (test,
  enable/disable, delete, event subscriptions,
  delivery history), and the public
  `/driver-evidence/[token]` submit page, with a
  Communications entry in the sidebar.
- Docs: `docs/COMMUNICATION-INTEGRATIONS.md` documents
  the providers, the mock/log delivery, the signed-link
  security model, the email workflows, and the honest
  limits (no live Slack/Teams delivery, no real mailer,
  no OAuth app or bot, no per-driver identity, no
  delivery retry).

## [1.3.1] - 2026-11-26

Phase 4, Advanced Imports: saved CSV mappings, import
history with row-level error correction, scheduled imports,
import notifications, and synchronization workflows over
stored files. App version bumped to 1.3.1; OpenAPI at 148
paths (136 + 12 new paths, 20 operations).

### Added

- Saved CSV mappings: `GET/POST /imports/mappings`,
  `GET/PUT/DELETE /imports/mappings/{mapping}`. A mapping
  names exact CSV header text and the shipment field each
  header fills; every field must be one a mapping may target
  (the importer's header-alias fields plus `carrier_id`),
  names are unique per organization, and one mapping per
  target is the default (a new default unsets the previous
  one). Preview and confirm accept an optional `mapping_id`;
  without one the built-in header aliases apply, which only
  cover the standard header spellings. Deleting a mapping
  nulls the link on runs, schedules, and sync workflows.
  Managing mappings needs the new `manage-imports`
  permission (owner, admin, operations manager, dispatcher);
  any member may list or read.
- Import history: `GET /imports/runs` (filters `status` and
  `source`), `GET /imports/runs/{run}` (run with rows),
  `GET /imports/runs/{run}/rows` (filter by row status).
  Run status is computed from the rows: `completed` when
  every row imported, `partial` when some imported and some
  did not, `failed` when none imported (including a file
  whose rows are all duplicates, and a file that never
  parsed, which records `total_rows: 0` and the reason in
  `error_message`). Counts are `total_rows`,
  `imported_rows`, `skipped_rows`, `duplicate_rows`, and
  `error_rows`; corrected rows count as imported.
- Duplicate detection: a row is a duplicate when its
  `internal_reference` or `external_load_reference` already
  exists in the organization, in the database or earlier in
  the same file. Duplicate rows are recorded with status
  `duplicate` and no shipment is created; re-importing the
  same file imports nothing. Exact match only, no fuzzy
  matching.
- Error correction: `POST
  /imports/runs/{run}/rows/{row}/correct` merges `data`
  over the stored row, re-validates with the importer's
  rules, and re-checks the reference. A valid row creates
  the shipment, becomes `corrected`, and the run counts
  refresh (a run whose last failed row was corrected flips
  to `completed`). A correction that still fails stores the
  new values and errors and returns 422. An already imported
  or already corrected row cannot be corrected again (422).
  Rows that fail validation are stored as `skipped`;
  `failed` is reserved for rows whose correction retry still
  fails. Requires `manage-imports`.
- Scheduled imports: `GET/POST /imports/schedules`,
  `GET/PUT/DELETE /imports/schedules/{schedule}`, `POST
  /imports/schedules/{schedule}/run`. A schedule is a named
  stored file (CSV/TXT, max 5120 KB, on the private disk
  under `imports/{organization}/schedules/`) plus an optional
  saved mapping and a frequency (`daily`, `weekly`,
  `manual`). Daily advances `next_run_at` by one day after
  each run, weekly by one week; manual has no next run and
  only runs on demand. The artisan command
  `imports:run-scheduled` runs due schedules (flags
  `--schedule`, `--organization`) and is scheduled daily at
  04:30; it runs as the organization's owner. Deleting a
  schedule deletes its stored file; runs it produced stay in
  the history. Requires `manage-imports`.
- Import notifications: when a run finishes, every member
  who can manage imports except the actor gets an in-app
  notification, `import.completed` or `import.failed`,
  naming the file and the counts. A single-member
  organization has no one else to notify, so no
  notification is created for its own runs.
- Synchronization workflows: `GET/POST
  /imports/sync-runs`, `GET/DELETE
  /imports/sync-runs/{syncRun}`, `POST
  /imports/sync-runs/{syncRun}/run`. A workflow is a named
  stored file plus an optional saved mapping; running it
  moves it through `running` to `completed` or `failed`
  and links the import run that did the work. Deleting a
  workflow deletes its stored file; runs it produced stay in
  the history. Requires `manage-imports`.

### Changed

- The shipment CSV import preview and confirm endpoints
  accept an optional `mapping_id`, and confirm records an
  import run (source `upload`) and returns `import_run_id`
  alongside the imported/skipped counts. The preview and
  confirm response shapes are otherwise unchanged.
- Notification types grew from 11 to 13 with
  `import.completed` and `import.failed`.

### Limitations

- No live source for schedules or sync workflows: no SFTP,
  no URL fetch, no email-attachment pickup, no provider API.
  A schedule re-imports a file the organization already
  uploaded.
- No TMS integration: a sync workflow re-imports a stored
  file with a saved mapping and workflow states; it is not a
  live connection to another system. TMS integrations are
  v1.3.2.
- No per-mapping or per-schedule plan limits; imports are
  not metered against any plan.
- No reprocess endpoint for a failed run: correct the rows,
  or re-upload the file.
- Imports target shipments only: no claims, carriers, or
  evidence import.

## [1.3.0] - 2026-11-26

Phase 4, Email Intake: organization-specific inbound email
addresses, the HMAC-signed inbound webhook, attachment
extraction, shipment reference extraction, automatic shipment
matching, the manual matching interface, and the processing
history. App version bumped to 1.3.0; OpenAPI at 136 paths
(127 + 9 new paths).

### Added

- Organization-specific email addresses: every organization
  gets one address when it is created, shaped
  `{org-slug}-{random6}@{domain}` with the domain from
  `EMAIL_INTAKE_DOMAIN` (default `inbound.claimtrail.local`, a
  placeholder that resolves nowhere). `GET/POST
  /email-intake/addresses`, `POST
  /email-intake/addresses/{address}/regenerate` (the old address
  stops working), `POST
  /email-intake/addresses/{address}/deactivate` (the last active
  address cannot be deactivated). Listing provisions the default
  address for organizations created before v1.3.0. Managing
  addresses needs the new `manage-email-intake` permission
  (owner, admin, operations manager, dispatcher); any member may
  list.
- Inbound email webhook: `POST /email-intake/inbound` (public,
  120/min) takes a normalized JSON payload (`from`, `to`,
  `subject`, `text_body`, `html_body`, `message_id`,
  `attachments` as base64 with filename and MIME type),
  authenticated by HMAC-SHA256 over the raw body
  (`X-Email-Intake-Signature: sha256=<hex>`,
  `EMAIL_INTAKE_WEBHOOK_SECRET`). Bad signatures return 401;
  unknown `to` addresses return 422 with a generic message that
  never says whether an address exists. A repeated `message_id`
  for the same organization returns the stored email with
  `duplicate: true` and is not processed twice.
- Attachment extraction: attachments are validated with the same
  limits as evidence uploads (20480 KB, the same extension
  allowlist, MIME type checked against real content) and stored
  on the private disk. A delivery whose attachments would pass
  the organization's storage cap is refused with 402 before
  anything is stored.
- Shipment reference extraction: `ShipmentReferenceExtractor`
  pulls reference-shaped tokens from the subject, body, and
  attachment filenames and matches them exactly (case-insensitive)
  against the org's shipments on `internal_reference` and
  `external_load_reference`. Candidates are stored on the email
  as JSON with their source and what matched.
- Automatic shipment matching: exactly one matching shipment
  auto-matches (status `matched`, reason "exact reference match",
  confidence "high") and files the attachments as evidence;
  zero matches leave the email `unmatched` ("no shipment
  reference found"), several matches leave it `unmatched`
  ("multiple shipments match the extracted references"). The
  queued `ProcessInboundEmail` job wraps the same processor for
  reprocessing.
- Manual matching interface: `GET /email-intake/emails` (the
  processing history, filter by `status` or `matched`,
  paginated), `GET /email-intake/emails/{email}`, `POST
  /email-intake/emails/{email}/match`, `POST .../unmatch`, `POST
  .../dismiss` (reason required). Matching files attachments as
  evidence (category `other`, source email named in the
  description). Email bodies are never written to the audit log;
  intake events are audit rows (`email_intake.received`,
  `auto_matched`, `unmatched`, `manually_matched`, `dismissed`,
  `failed`).
- Email intake pages: `/email-intake` (addresses with create,
  regenerate, and deactivate; status filter; history) and
  `/email-intake/[id]` (message, extracted references,
  attachments, shipment search, match, unmatch, dismiss),
  linked from the sidebar for every member, with management
  actions gated on `canManageEmailIntake`. New typed client
  `src/lib/emailIntake.ts`.
- Operator command: `php artisan email-intake:list` (`--status`,
  `--organization`, `--limit`), following the `support:list`
  pattern.
- Docs: new `docs/EMAIL-INTAKE.md` runbook (addresses, webhook
  contract with an example payload, HMAC scheme, matching
  rules, provider setup as a future step); the email-intake
  section in `docs/API.md`; OpenAPI `EmailIntake` tag with
  `InboundEmailAddress`, `InboundEmail`,
  `InboundEmailAttachment`, and `ExtractedReference` schemas.

### Fixed

- The ops health version assertion in `HealthCheckTest` now
  expects 1.3.0 (the only test that pinned the old version).

### Not in v1.3.0

- No real inbound provider is wired: no SendGrid, Mailgun, or
  SES client, no provider credentials, no real mail delivered.
  Wiring one is a documented future step (domain, webhook
  secret, normalized payload, signature header).
- No OCR: text is read from the subject, the text body, and
  attachment filenames only. Reading scanned documents is
  v2.0.1.
- The webhook processes synchronously; the queued job exists
  for reprocessing, not as the primary path.

### Verification

- Backend 497 tests, 2576 assertions, all passing (479 before
  this version + 18 email intake tests).
- Frontend 333 tests, all passing (318 + 15 new);
  `tsc --noEmit` clean; lint clean of new findings (the two
  remaining findings are pre-existing from v1.2.2);
  `npm run build` succeeds, 60/60 pages including
  `/email-intake` and `/email-intake/[id]`.
- Live HTTP smoke test on a fresh SQLite DB: address
  provisioning, webhook bad signature 401, unknown recipient
  422, valid email auto-matched with attachments stored as
  evidence, no-reference email unmatched then manually matched,
  duplicate message_id deduped, cross-tenant 404, plus a
  regression pass (register, login, organization, plans, trial,
  support create).
- Still unverified (standing blockers): browser E2E, live
  Postgres, live Redis, Docker, real mailer.

## [1.2.3] - 2026-11-26

Phase 3, Production Launch — customer support, help documentation,
subscription administration, refunds and cancellations, production
monitoring, backup verification, and the internal review of the
privacy policy, terms of service, and data processing documentation.
Phase 3 is complete with this version. App version bumped to 1.2.3;
OpenAPI at 127 paths (119 + 8 new paths).

### Added

- Customer support tickets: any member can open a ticket and reply
  on it (`GET/POST /support/tickets`, `GET /support/tickets/{id}`,
  `POST /support/tickets/{id}/messages`, `POST
  /support/tickets/{id}/status`). Owner and admin triage, change
  statuses, and write internal notes that only they see. A staff
  reply moves an open or resolved ticket to in progress; a customer
  reply on a resolved ticket reopens it; a closed ticket takes no
  more messages. Ticket bodies and messages are never written to
  the audit log; status changes are audit rows without the message
  text. Support pages in the app sidebar, plus operator commands
  `support:list` (`--status`, `--organization`, `--limit`) and
  `support:show`.
- Help documentation: public help page at `/help` (linked from the
  marketing footer, listed in the sitemap) and `docs/HELP.md`
  covering the product, billing, refunds, roles, data, and what the
  app does not do.
- Invoice refunds: `POST /billing/invoices/{invoice}/refund`
  (manage-billing). No amount means a full refund of the remaining
  paid amount; a full refund sets the invoice status to refunded,
  a partial refund to partially_refunded. The reason is required.
  Amounts above the remaining paid amount, refunds on unpaid
  invoices, and negative amounts are rejected. Every refund is a
  `refunds` row, an audit row, and a billing event, and the billing
  page has a Refund button on paid invoices with a confirm form.
  Refunds run against the mock provider; no real money moves.
- Billing event log: `GET /billing/events` (manage-billing) returns
  subscription, invoice, payment, and refund events, newest first,
  shown on the billing page under "Billing events".
- Subscription administration: `php artisan billing:admin
  <uuid or slug>` prints a subscription, its period, trial, payment
  method, credit balance, recent invoices with refunded amounts,
  and recent billing events. It changes nothing.
- Production monitoring: `monitoring:check` runs the health checks,
  counts failed jobs and queue depth, and stores the result
  (hourly by schedule). The operations page shows the latest run
  and the recent history with a "Run check now" button
  (`GET /ops/monitoring`, `POST /ops/monitoring/check`). A failing
  check writes a warning to the log; there is no alerting channel
  yet.
- Backup verification command: `php artisan backup:verify`
  re-hashes the latest completed run (or `--run=<uuid>`) against
  its manifest; weekly on Sundays at 04:00. Exit code 0 means the
  backup is intact, 1 means it is missing, corrupt, or not
  completed.
- Privacy policy, terms of service, and data processing
  documentation: internally reviewed against the app's actual data
  practices (`docs/legal-review-1.2.3.md`,
  `docs/DATA-PROCESSING.md`). The public `/privacy` and `/terms`
  pages are now full drafts carrying the review date and the banner
  that they are not legal advice and have not been reviewed by a
  lawyer. They must not be described as compliant with any law.
- Production launch checklist: `docs/PRODUCTION-LAUNCH-CHECKLIST.md`
  lists what is done in the code, what still needs a person
  (lawyer review, real Stripe, real mailer, production hosting,
  domain, backup retention policy, account deletion flow,
  alerting channel), and the known gaps that stay open after
  launch.
- Docs: refund, cancellation, and subscription administration
  sections in `docs/BILLING.md`; production monitoring and backup
  verification sections in `docs/OPERATIONS.md`; the backup
  retention note corrected (`keep_days` is configured at 30 days
  but enforced by no command).

### Changed

- OpenAPI info version bumped to 1.2.3 with the v1.2.0-v1.2.3
  history in the description; `Billing` and `Support` tags are now
  declared. `BillingInvoice` carries `amount_refunded_cents`.

### Known gaps (unchanged)

- Sanctum tokens never expire; access tokens live in browser
  localStorage. No malware scanning on uploads. No
  application-level encryption for backups or stored files.
- A refunded payment does not change metered success-fee usage:
  the fee is on claim payments recorded as paid, not on
  subscription invoices, so refunding a fee invoice is just money
  back.
- No alerting channel for monitoring failures; no account or
  organization deletion flow; carriers cannot be deleted.
- Browser E2E, live Postgres, live Redis, Docker, and real mailer
  remain unverified on this VM.

## [1.2.2] - 2026-11-26

Phase 3, Marketing and Acquisition — the public marketing site, lead
capture, SEO, analytics, and the trial wiring from the pricing page
into signup. App version bumped to 1.2.2; OpenAPI at 119 paths
(116 + 3 public marketing paths).

### Added

- Marketing website: a shared marketing layout (header, footer,
  analytics tracker) and public pages at `/` (landing, refactored
  onto the shared layout), `/product` (overview), `/features` and
  `/features/[slug]` (seven features, each with its honest limits),
  `/pricing`, `/demo`, `/demo-request`, `/screenshots`, and
  `/getting-started`. Shared content lives in
  `frontend/src/lib/marketing.ts`.
- Pricing page: the five listed tiers from v0.5.0 section 8 (Free
  $0, Starter $19, Growth $49, Fleet $99, Agency $149 base + $12
  per extra carrier), monthly/annual toggle (annual is 10x monthly,
  two months free), the 30-day no-card Growth trial, and an FAQ.
  Prices are labelled hypotheses being tested, not prices set in
  stone. The success-fee plan is described as offered at trial
  exit, not listed as a default.
- Product demonstration: an interactive seven-step walkthrough of
  the PLT-1001 detention claim, plus the full demo set (twelve
  synthetic shipments, eleven claims, $2,440 requested) from the
  v0.6.0 pilot sample. All demo data is synthetic and labelled so.
- Demo request form and lead capture: `POST /v1/leads` (public,
  5/min) stores a lead (`leads` table, new migration); idempotent
  by email and source within 90 days (updates instead of
  duplicating, returns 200). No IP address or user agent is stored.
  The operator reviews leads with `php artisan leads:list`
  (`--status`, `--limit`); there is no admin UI yet.
- Public plan catalog: `GET /v1/plans` (public, 30/min) returns the
  listed plans from config/billing.php plus the trial offer, so the
  pricing page and the billing system cannot drift apart. The
  unlisted Success Fee plan is not returned.
- Analytics integration: the marketing tracker posts page-view and
  conversion events to `POST /v1/analytics/events` (public, 60/min,
  always 202). Events are anonymous (event, path, optional
  referrer) and go to the driver in `config/analytics.php`: the
  Laravel log by default, or nowhere when the driver is `none`.
  The tracker is off unless `NEXT_PUBLIC_ANALYTICS_ENABLED` is
  `"true"`, and sending never blocks the page.
- SEO: per-page metadata (title, description, canonical, Open
  Graph, Twitter card) via `frontend/src/lib/seo.ts`, JSON-LD
  `SoftwareApplication` on the home and pricing pages, and
  `sitemap.ts` / `robots.ts`. Absolute URLs need
  `NEXT_PUBLIC_SITE_URL`; until the site has a real domain the
  sitemap is empty and robots.txt lists no sitemap.
- Customer onboarding wiring: the pricing page links to
  `/register?plan=<key>`; the register page remembers the choice
  and the in-app onboarding page offers the matching trial through
  the existing `POST /billing/trials` endpoint (30 days on Growth,
  no card). The banner only shows for a Free organization that is
  not already trialing. Plus a public `/getting-started` page: the
  30-day path and the seven first steps.

### Fixed

- Throttle buckets for the public marketing endpoints: Laravel's
  guest throttle key is domain + IP only, so without a prefix the
  plan catalog, lead capture, and analytics endpoints shared one
  bucket with each other (and with the auth routes), and five
  pricing-page loads would block a demo request for a minute. Each
  of the three new routes now carries its own throttle prefix
  (`plans`, `leads`, `analytics`). Caught by the live smoke test,
  not the suite; a regression test proves five plan loads leave all
  five lead submissions available and the sixth is still throttled.

### Not in v1.2.2

- Product screenshots: the gallery is built (`/screenshots`,
  renders a real PNG from `public/screenshots/` when one exists),
  but no real captures exist yet: there is no headless browser on
  the build VM, and a mockup dressed up as a screenshot would be a
  fabrication. The frames are labelled placeholders, the capture
  process is in docs/MARKETING.md, and the checklist item stays
  unchecked until real captures land.
- Analytics provider: the log driver works; GA4 or Plausible needs
  a measurement ID that only Aqib can supply.
- Privacy policy and terms are still placeholders pending legal
  review (v1.2.3).

## [1.2.1] - 2026-11-25

Phase 3, Plans and Entitlements — the plan limits from v0.5.0
section 8 are now enforced in the backend. App version bumped to
1.2.1; OpenAPI still at 116 paths (no new endpoints).

### Added

- Entitlement enforcement (`App\\Services\\EntitlementService`,
  `App\\Exceptions\\EntitlementExceededException`): the org's
  current plan is read from its subscription (trialing orgs are on
  the Growth trial, canceled subscriptions fall back to Free).
  Guarded actions are claim creation, member invitations, evidence
  uploads, PDF package generation, and full reports. A refusal is
  402 with `error: "plan_limit_exceeded"` and a `limit` block
  naming the cheapest plan that raises the limit.
- Limits per plan: claims per month Free 5, Starter 20, Growth 100,
  Fleet 300, Agency 100 per carrier, Success Fee uncapped; members
  Free 1, Starter 3, Growth 10, Fleet 25, Agency unlimited;
  storage Free 100 MB, Starter 1 GB, Growth 10 GB, Fleet 50 GB,
  Agency 10 GB per carrier; PDF packages not on Free, unlimited
  on paid plans; reports dashboard-only on Free, full on Starter
  and above. Shipments are not capped: the claim cap is the
  binding limit.
- Usage tracking (`GET /billing/usage`, `entitlements` block, also
  in `GET /billing/subscription`): claims created in the current
  period, active members plus pending invitations, storage used,
  and per-resource upgrade prompts. The billing page shows a Plan
  usage section; the claim creation page renders a 402 as an
  upgrade alert linking to billing.
- Caps never delete data: claims created before a cap stay, and a
  downgrade keeps everything while blocking new claims above the
  lower limit.
- Downgrade carryover (`claims_carryover` on the subscription, new
  migration): when a move to a lower plan takes effect at a
  renewal (scheduled downgrade, cancellation, dunning downgrade,
  trial lapse, immediate cancel), the claims created since the old
  period began still count toward the new lower cap for one
  period. The next renewal clears it, and a same-plan renewal
  clears it too, so a normal renewal still resets the monthly
  allowance.
- OpenAPI: `EntitlementSummary` and `EntitlementLimitInfo`
  schemas, a `PlanLimitExceeded` 402 response referenced from the
  seven guarded endpoints, and the `entitlements` block on
  `BillingUsageSummary`.

### Fixed

- The frontend `ApiRequestError` dropped every error-body field
  except `message` and `errors`, so a 402 `limit` block never
  reached the page that renders the upgrade prompt. The full
  parsed body is now carried on the error.
- The downgrade block was a one-second race: the claim-cap count
  used only the current billing period, so a renewal reset the
  count and the downgrade handed out a fresh allowance at once,
  blocking nothing except when the claims and the renewal landed
  in the same second. The carryover above makes the block
  deterministic, and the downgrade test no longer depends on
  wall-clock timing.

### Not in v1.2.1

- No vendor dashboard surface for usage: the operations dashboard
  is per-org queue monitoring with no org-listing view.
- No per-claim cap on evidence files or generated documents:
  v0.5.0 section 8 does not specify one, and storage is the
  governor.

## [1.2.0] - 2026-11-25

Phase 3, Subscription Billing — against a mock provider, by decision:
no real Stripe calls, no Stripe keys. The provider sits behind the
`BillingProvider` interface so real Stripe is a later provider swap,
not a redesign (see docs/BILLING.md). App version bumped to 1.2.0;
OpenAPI at 116 paths (99 + 17 billing paths).

### Added

- Mock billing provider (`App\Billing\BillingProvider` interface +
  `MockBillingProvider`): fake customer/session/charge ids, Stripe
  test cards `pm_mock_visa` (4242, succeeds) and `pm_mock_declined`
  (4000...0002, declines), process-local idempotency cache,
  HMAC-SHA256 webhook signing with `BILLING_WEBHOOK_SECRET`.
- Plans from v0.5.0 section 8 (`config/billing.php`): Free $0,
  Starter $19, Growth $49, Fleet $99, Agency $149 base + $12 per
  extra carrier, plus the unlisted Success Fee plan ($0 + 15% of
  amounts recorded as paid, capped at $49/mo first year). Monthly
  and annual billing (annual 10x monthly). Plan limits are shown
  but not enforced; enforcement is v1.2.1.
- Checkout flow (`POST /billing/checkout`, then
  `POST /billing/checkout/{session}/complete`): a paid plan needs a
  payment method; a declined card leaves the session open for a
  retry. On success the subscription activates and a paid invoice
  is issued.
- 30-day no-card Growth trial (`POST /billing/trials`): converts to
  paid Growth when a card is on file, otherwise lapses to Free.
- Plan changes (`POST /billing/subscription/change-plan`): upgrades
  apply immediately with proration (credit for unused days, charge
  for remaining days, both at daily rates); downgrades are scheduled
  for the next renewal. `preview=true` returns the proration
  without changing anything.
- Renewals (`billing:renew`, daily 03:30) and cancellations
  (at period end, immediately, or resumed before the period ends).
- Invoice history (`GET /billing/invoices`, `GET
  /billing/invoices/{invoice}`) and paying an open or past-due
  invoice (`POST /billing/invoices/{invoice}/pay`). Invoice numbers
  are per-organization, per-year (`INV-2026-0001` for each
  organization's first invoice).
- Failed-payment handling: a failed renewal leaves the invoice past
  due; `billing:dunning` (daily 04:00) retries (+3 days, then +7
  days) and downgrades to Free after 14 days past due, never
  deleting data.
- Simulated webhooks (`POST /billing/webhooks`, HMAC-verified, and
  `POST /billing/webhooks/simulate`): exact redelivery is recorded
  as a duplicate and never applied twice; unknown types are
  recorded as unprocessed.
- Mocked billing portal (`POST /billing/portal`): returns a portal
  URL (the frontend billing page) and a token that opens the
  billing summary at `GET /billing/portal/{token}`, expiring after
  30 minutes.
- Metered Success Fee billing: recording a payment on an accepted
  claim creates a usage record (15% of the amount, inside the
  record-payment transaction, so a paid claim is never metered
  twice or missed). Renewal invoices all uninvoiced records in
  arrears with the $49/mo first-year cap applied in the billing
  code.
- Billing hub (`/settings/billing`, Owner/Admin/Billing Staff):
  current subscription, plans with monthly/annual toggle and test
  card selector, trial, plan change with proration preview,
  Success Fee usage, payment method, invoices with pay, billing
  portal entry. New `canManageBilling` permission helper and typed
  client `src/lib/billing.ts`.
- `manage-billing` permission (Owner, Admin, Billing Staff).
- Billing runbook `docs/BILLING.md` (mock provider, plan movement,
  metered billing, webhooks, invoices, scheduled commands, the
  Stripe swap-out path), OpenAPI billing paths and schemas
  (116 paths total, verified against the live route list), API.md
  billing section.

### Fixed

- Invoice numbers are unique per organization, not globally: the
  billing migration had a global unique on `invoices.number` while
  numbering is per-organization, per-year, so the second
  organization's first invoice failed with a unique-constraint
  violation (its checkout returned 500 and its renewal threw).
  Found by the v1.2.0 live smoke test; the constraint is now
  `(organization_id, number)`, with a regression test proving two
  organizations can each hold `INV-2026-0001`.

### Verification

- Backend 421 tests, 2169 assertions, all passing (382 before this
  version + 33 billing tests + 6 proration unit tests).
- Frontend 268 tests, all passing (241 before + 27 billing tests);
  `npm run build` succeeds, 38/38 pages including `/settings/billing`.
- Live HTTP smoke test on a fresh SQLite DB, 59/59 steps: plans,
  trial, checkout to Fleet, proration preview and upgrade to Agency,
  cancel/resume, declined-card renewal failure, dunning retry and
  14-day downgrade to Free, webhook bad signature 401 / good
  signature applied / duplicate redelivery, portal token summary,
  Success Fee checkout with no card, claim lifecycle to accepted,
  payment recorded, metered usage $1,000 recovered / $150 fee,
  arrears invoice $150.00 - $101.00 = $49.00 (the cap), paid, usage
  empty after.
- Still unverified (standing blockers): browser E2E, live Postgres,
  live Redis, Docker, real mailer.

## [0.1.0] - 2026-11-21

Phase 0, Business Validation — Market Research. Research and
documentation only; no code changed, app version stays 1.1.3.

### Added

- Market research document `docs/validation/0.1.0-market-research.md`:
  trucking revenue leakage (ATRI Sep 2024: detention at 39.3% of stops,
  135M+ hours, $15.1B in 2023; 94.5% of fleets charge detention but
  fewer than 50% of invoices are paid; DOT OIG 2018: $1.1–1.3B/yr driver
  earnings impact), missed accessorial charges with sourced rate ranges
  (detention $50–$75/hr, layover $250–$350/day, TONU $200–$300, lumper
  $150–$250, plus liftgate/tarp/hazmat/driver-assist/stops/redelivery),
  target segments (FMCSA census: ~1.11M authorized-for-hire carriers,
  66% single-truck, 92% five or fewer; ATA: $906B freight revenue 2024),
  existing solutions (GetDockPay Pro $24.99/mo, Detention Buddy Pro
  $34.99/yr, Tezify/TenTrucks TMS detention billing, dispatch services
  as human substitutes), and market opportunities with TAM/SAM/SOM
  labelled as estimates (SOM sketch: 10,000 paying orgs at $39/mo =
  $4.7M ARR). Recommended beachhead, as a hypothesis for v0.2.0 to
  test: dispatch agencies and 2–20 truck fleets running reefer or spot
  freight. Key risks recorded: willingness to pay unproven against
  $2.92–$24.99/mo driver apps, small-carrier churn, TMS absorption,
  relationship fear, broker power.
- Research finding for a future version: GPS-stamped mobile capture
  (driver taps arrival/departure) is the most valuable feature this
  research surfaced and is not in any checklist version yet; proposed
  for a Phase 2/4 slot after validation.

### Fixed

- Test suite no longer depends on an ambient `APP_KEY`: the full
  backend suite failed 4 `SecurityCheckTest` tests in a clean checkout
  (no `.env` present) because `phpunit.xml` set no key. A fixed
  test-only key is now set in `phpunit.xml`, so the suite is
  self-contained. Verified: 382 tests, 1902 assertions, all passing —
  the same counts as v1.1.3.

## [1.1.3] - 2026-11-20

Phase 2, Security and Operations. One new permission
(`manage-security`: owner, admin).

### Added

- Tenant isolation audit: an automated sweep
  (`TenantIsolationAuditTest`) attempts cross-tenant access (show,
  update, delete, plus nested resources: events and evidence under
  another organization's shipment, evidence attached across
  organizations) across every API resource and asserts 404. The
  code audit found no unscoped queries: every tenant-owned model
  uses the `BelongsToOrganization` global scope, and the five
  `withoutGlobalScope` call sites are legitimate (invitation
  acceptance, queued jobs, queue monitoring). Findings in
  docs/SECURITY.md.
- RBAC security audit: a role-matrix test
  (`RbacAuditTest`) asserts viewer cannot write, billing staff
  cannot manage the organization or members, dispatcher limits
  (cannot delete events, review claims, read audit logs or
  operations), and operations managers cannot manage security;
  plus a sweep over every registered API route asserting that every
  route except the six documented public ones requires
  `auth:sanctum` and every tenant-scoped route resolves an
  organization. The matrix is documented in docs/SECURITY.md.
- File access security review: evidence and package downloads now
  refuse storage keys that escape their prefix (`StorageKey::isSafe`,
  checked on every download and preview). Tests cover
  unauthenticated 401, cross-tenant 404, traversal attempts
  refused, and disallowed upload types rejected by content
  (`FileAccessSecurityTest`). Downloads were already behind auth,
  tenant checks, and policy gates; the traversal guard is new.
- Backup configuration: `config/backup.php`, `backup:create`
  artisan command (daily 02:00 schedule), and `GET`/`POST
  /backups`, `GET /backups/{backup}`, `POST
  /backups/{backup}/verify`. A backup copies the whole database
  plus every evidence and package file, with a manifest.json of
  SHA-256 checksums. SQLite file databases are fully supported;
  an in-memory database and a missing `pg_dump` fail with a clear
  message instead of a fake success. Runs are recorded in
  `backup_runs` (status running/completed/failed, size, checksum,
  restore timestamp).
- Restore testing: `backup:restore` verifies every manifest
  checksum before touching live data and refuses on any mismatch;
  `BackupRestoreTest` creates a backup, wipes the database and a
  stored file, restores, and asserts the data and the file bytes
  are back. The PostgreSQL restore path (`pg_restore`) is
  implemented but never run against live PostgreSQL on this VM.
- Data retention policies: `config/retention.php` defaults (audit
  logs 365 days, notifications 90, job executions 30, failed
  documents 30, security events 180; 0 keeps forever),
  per-organization overrides via `GET`/`PUT /retention`, and
  `retention:purge` with `--dry-run` (weekly Sunday 03:00
  schedule). Purging is tenant-scoped, writes one `retention.purged`
  audit row per organization, and never deletes ready claim
  packages.
- Production deployment hardening: the security-headers middleware
  now also sends a strict Content-Security-Policy and HSTS (when
  the request is HTTPS or HSTS is enabled, on by default in
  production); session cookies are secure by default in
  production; and a new `security:check` command (with `--json`)
  and `GET /security/check` report ten production-readiness
  checks (APP_DEBUG, APP_KEY, APP_URL HTTPS, session cookies,
  Sanctum token expiration, evidence disk, CORS origin, backup
  freshness, mail and queue drivers) with a failing exit code.
- Incident response documentation: `docs/INCIDENT-RESPONSE.md`
  (severity levels, first-response steps, containment, eradication
  and recovery, rollback, data-breach steps, communication
  templates, on-call placeholders). Failed logins and
  authorization denials are now recorded as security events
  (`security_events` table, `GET /security/events`), and surfaced
  on the new Security settings hub.
- Security settings hub (`/settings/security`, owner/admin only):
  production-readiness check results, backup status with create
  and verify actions, retention settings, recent security events,
  and links to the security, incident-response, and operations
  runbooks. New `canViewSecurity` permission helper and
  `src/lib/security.ts` typed client.

### Changed

- API version is now 1.1.3 (backend `APP_VERSION`, frontend
  `package.json`, shell and landing page). OpenAPI grows from 91
  to 99 paths (all routes documented).
- `docs/SECURITY.md` now carries the v1.1.3 audit findings, the
  role matrix, the hardening notes, and the updated open items
  (backups and retention are done; token lifetime and upload
  malware scanning are still open).

### Verification

- Backend 382 tests (1902 assertions), all passing; frontend 241
  tests, all passing; `npm run build` succeeds (37/37 pages,
  including `/settings/security`); `tsc --noEmit` clean; lint
  clean. `migrate:fresh` passes with the 2 new migrations.
- Still unverified (standing blockers, unchanged): browser E2E,
  live Postgres, live Redis, Docker, real mailer. v1.1.3-specific
  notes: the PostgreSQL backup/restore paths were never run
  against live PostgreSQL (no server or client tools on this VM);
  queue monitoring is still database-driver only; the errors
  endpoint still reads the local log file.

## [1.1.2] - 2026-11-19

Phase 2, Customization (backend only; no frontend was built for
customization in this version). One new permission
(`manage-customization`: owner, admin, operations manager).

### Added

- Custom fields for shipments and claims: definitions per
  organization and resource type (`GET`/`POST /custom-fields`,
  `GET`/`PUT`/`DELETE /custom-fields/{field}`), with field types
  `text`, `number`, `date`, `select`, and `boolean`, an auto-slugged
  key (immutable after creation), required flags, select options,
  sort order, and an active flag. At most 20 active definitions per
  organization and resource type. Shipment and claim create/update
  accept `custom_field_values`, validated against the active
  definitions (unknown keys, missing required fields, and wrong
  types return 422); updates merge over the stored values, and both
  resources return `custom_field_values`. Deleting a definition
  strips its key from every shipment and claim value map in the
  organization.
- Event category updates (`PUT /event-categories/{category}`) and
  deletion (`DELETE /event-categories/{category}`) for
  organization-owned categories: rename with slug recompute and
  collision check against visible categories, disable with
  `is_active`, and default estimated amount and currency that
  prefill new events when none is given. System categories return
  404; a category with events returns 422 on delete. Creating an
  event under a disabled category returns 422; events recorded
  before a category was disabled stay readable.
- Evidence templates (`GET`/`POST /evidence-templates`,
  `GET`/`PUT`/`DELETE /evidence-templates/{template}`,
  `POST /evidence-templates/{template}/apply-to-claim/{claim}`):
  named checklists of document categories with labels, required
  flags, and sort order. Applying a template creates a
  `ClaimRequirement` row per document category the claim does not
  already have; applying the same template twice creates nothing
  the second time.
- Deadline settings (`GET`/`POST /deadline-settings`,
  `GET`/`PUT`/`DELETE /deadline-settings/{setting}`,
  `GET /deadline-settings/resolve`): organization defaults for how
  soon after an event a claim must be submitted, scoped `global`,
  `claim_category`, or `event_category`, with `submit_within_days`
  (1 to 365) and `reminder_days_before`. Resolution precedence is
  claim category, then event category, then global. When a claim is
  created without an explicit submission deadline, the resolved
  setting creates a `ClaimDeadline` row (source "Organization
  deadline default") and sets the claim's `submission_deadline_at`.
- Export templates (`GET`/`POST /export-templates`,
  `GET`/`PUT`/`DELETE /export-templates/{template}`,
  `POST /export-templates/{template}/set-default`,
  `GET /export-templates/available-columns`): saved column
  selections per resource type, with one default per organization
  and resource type. `GET /shipments/export` and the new
  `GET /claims/export` accept `?export_template_id=`; without it
  the default column sets are used. Columns are fixed keys or
  `custom:<field_key>` for active custom fields.
- Organization settings now accept `settings.default_currency`
  (3 letters), `settings.date_format` (`Y-m-d`, `d/m/Y`, or
  `m/d/Y`), and `settings.claim_reference_prefix` (up to 10
  characters, uppercase letters, digits, and hyphens). Unknown
  settings keys are rejected with 422, and given settings merge
  over the stored ones. The claim reference prefix only affects
  claims created after the setting is saved; the format is
  `<PREFIX>-YYYY-NNNN` with `CLM` as the default.
- OpenAPI now documents all 93 route paths (up from 79), matching
  the route list exactly.

### Changed

- Claim references use the organization settings prefix when one is
  set; the default `CLM` prefix is unchanged, so existing tests and
  references are unaffected.
- The event-categories list now includes `is_active`,
  `default_estimated_amount`, and `default_currency` per category.

### Notes

- Notification preferences were verified pre-existing (model plus
  `GET`/`PUT /notification-preferences`, per-member per-event
  in-app/email) and were not rebuilt in this version.
- Deadline defaults only apply when no explicit submission deadline
  is given at claim creation; they never overwrite one.
- The claim reference prefix only affects new claims.

## [1.1.1] - 2026-11-18

Phase 2, Reliability. One new permission (`view-operations`: owner,
admin).

### Added

- Public health endpoint `GET /health` (no authentication): overall
  status plus per-check status and latency for the database, cache,
  queue, and storage. Returns 200 when ok or degraded, 503 when
  down (database or storage failure). The queue check reports
  "skipped" unless the database queue driver is in use. Intended
  for load balancers and uptime monitors.
- Operations endpoints (all require `view-operations`): detailed
  health `GET /ops/health` (versions, queue totals), queue
  statistics `GET /ops/queue` (per-queue pending, reserved, and
  delayed counts with the age of the oldest pending job), failed
  jobs `GET /ops/failed-jobs` (paginated, tenant-filtered by
  payload), `POST /ops/failed-jobs/{uuid}/retry`,
  `POST /ops/failed-jobs/{uuid}/forget`,
  `POST /ops/failed-jobs/retry-all`, `POST /ops/failed-jobs/flush`
  (deletes only the organization's jobs older than a given number
  of hours, default 168), and recent errors `GET /ops/errors`.
  Failed jobs that cannot be attributed to an organization are
  never listed or acted on.
- Operations page (`/settings/operations`) for organization admins:
  health card, queue stats, failed-jobs table with retry/forget,
  retry-all and flush actions, and recent errors, with manual
  refresh.
- Idempotent background jobs: `JobExecution` records keyed by a
  unique idempotency key. Claim package generation keys on the
  document id; notification emails key on the notification id (or
  a payload hash for ad-hoc emails). A second delivery of the same
  job is skipped, a failed run releases its key, and a running key
  abandoned for over 60 minutes can be re-claimed.
- Standard retry profile for queued jobs (3 tries, 120 second
  timeout, backoff 30/120/600 seconds, retry until 24 hours),
  with a `failed()` hook on both jobs logging the final failure.
  The notification email keeps its longer 60/300/900 backoff.
- Upload reliability: evidence uploads store a SHA-256 checksum
  of the uploaded file; the write is verified (exists and size
  matches) before the database row is created, and a failed row
  create removes the stored file. Downloads and previews verify
  the stored object against its checksum and fail with a 500 on
  mismatch. A weekly scheduled command
  (`claimtrail:cleanup-orphan-evidence`, `--dry-run` supported)
  deletes evidence objects with no database row and older than
  one hour.
- Structured logging: a JSON log channel (`laravel.json.log`) and
  an errors-only JSON channel (`errors.json.log`), both carrying
  the correlation id, application name, and environment in every
  record. Server errors (5xx) are reported with exception class,
  file and line, route, method, user, organization, and
  correlation id; 4xx responses are not recorded as errors.
- Slow-query logging: queries slower than `DB_SLOW_LOG_MS`
  (default 500 ms) are logged with the SQL and duration. Bindings
  are never logged.
- API performance: an `X-Response-Time` header on every response,
  and ETag support on the event-categories list (304 on
  `If-None-Match`).
- Database indexes: composite index on `jobs` (queue,
  available_at, reserved_at) for queue polling and monitoring,
  and indexes on `shipments` (organization_id,
  delivery_appointment) and `claims` (organization_id,
  requested_amount) for the common filtered list queries.
- Operations runbook: `docs/OPERATIONS.md` (health endpoint,
  queue monitoring, failed-job recovery, log locations, orphan
  cleanup).

### Changed

- `GET /api/v1/health` is the only unauthenticated endpoint
  besides auth; everything under `/ops` requires the
  `view-operations` permission.
- Evidence responses now include the `checksum` field.

### Notes

- Queue monitoring reads the database queue tables and reports
  `supported: false` on other drivers. Redis queue monitoring is
  not implemented; the Redis worker itself remains unverified on
  this VM (no Redis server), as recorded in the v1.0.1
  limitations.
- The errors endpoint reads the local `errors.json.log` file. On
  multi-server deployments the log must be aggregated (for
  example by shipping the JSON log to a central store) or each
  server shows only its own errors.

## [1.1.0] - 2026-11-17

Phase 2, UX Improvements. One new permission (`delete-claims`:
owner, admin, operations manager, billing staff).

### Added

- Guided onboarding: a progress endpoint (`GET /onboarding`) that
  derives seven first steps live from the organization's data (invite
  a member, first shipment, first event, first evidence upload, first
  claim, first review), a dashboard checklist panel with a guided
  first-steps page (`/onboarding`), and organization-wide dismissal
  (`POST /onboarding/dismiss`, requires `manage-organization`). Only
  the dismissal is stored (`organizations.onboarding_dismissed_at`).
- Bulk shipment operations: `POST /shipments/bulk-status` and
  `POST /shipments/bulk-archive` (up to 100 ids). Each shipment
  follows the forward-only transition map and the update/archive
  policy; shipments that cannot move are reported in `failed` with a
  reason instead of failing the batch. Archiving is the bulk-delete
  equivalent: shipments are never hard-deleted. Status changes are
  audit-logged by the model observer. The shipments list has
  multi-select with a bulk action bar.
- Bulk claim operations: `POST /claims/bulk-transition` (up to 100
  ids; each claim goes through the transition service with its own
  map, data rules, history row, audit row, and notifications; moving
  to `paid` is rejected) and `POST /claims/bulk-delete` (only
  pre-submission claims; submitted or later claims are reported in
  `failed`). The claims list has multi-select with a bulk action bar,
  including accepted-amount and reason inputs for the targets that
  need them.
- Advanced search and filters: shipments gain delivery date range,
  base-rate range, event type, broker substring, and search that also
  matches carrier name and pickup/delivery location. Claims gain
  requested-amount range, submission-deadline range, deadline status
  (`overdue`, `upcoming`, `none`; overdue and upcoming only count
  while the claim is still being prepared), event type, broker, and
  carrier filters, plus search that also matches carrier name.
- Saved views (`saved_views` table): per-member named filter sets for
  the shipment and claim lists (`GET`, `POST`, `PUT`, `DELETE
  /saved-views`), at most 25 per member per organization per resource
  type. Filter payloads are flat maps of list query parameters to
  text values; unknown keys are dropped and nested values are
  rejected. The list pages can save the current filters, apply a
  view, and delete it.
- Improved CSV imports: the confirm result now reports each skipped
  row with its `reason`, an `is_duplicate` flag, and the per-field
  `errors`, so the import page can show exactly what was wrong with
  every row. The preview already returned per-row errors; the import
  page now displays them in a dedicated errors table.
- Evidence previews: a dedicated `GET /evidence/{evidence}/preview`
  endpoint serving images (JPEG, PNG, WebP) and PDFs inline with the
  correct content type and disposition; other file types return 422
  with `previewable: false` and the client falls back to download.
  Evidence records now carry a `previewable` flag. The evidence
  library and the claim detail page have a preview modal (image or
  PDF embed, download fallback).
- Improved claim review workflows: reviewer notes
  (`claim_review_notes` table, append-only) with `GET` and `POST
  /claims/{claim}/review-notes`, and a review action (`POST
  /claims/{claim}/review`) where approve moves an under-review claim
  to ready to submit and request changes moves it back to evidence
  required with a mandatory comment. The comment is recorded as a
  reviewer note and as the reason on the status history row. The
  review page now shows a plain-language readiness checklist,
  reviewer notes, and next-action guidance.
- Responsive UI and navigation: grouped sidebar sections (Workspace,
  Insights, Settings), 44px touch targets on navigation and buttons,
  a mobile drawer that closes on navigation, backdrop, or Escape,
  horizontal-scroll tables, and a full breadcrumb pass (every detail
  page already had crumbs; none were missing). Version strings
  updated to v1.1.0 across the app shell, footer, landing page, and
  package version.
- 38 new backend feature tests (238 total, 1029 assertions) and 34
  new frontend tests (144 total): onboarding, bulk shipments, bulk
  claims, saved views, advanced filters, evidence preview, claim
  review, and per-row import errors. OpenAPI updated to 70 paths
  (verified against the live route list); `docs/API.md` and
  `docs/DATABASE.md` updated for the new endpoints and tables.

### Changed

- Shipment and claim list search now also matches the carrier name;
  shipment search also matches pickup and delivery locations.
- `ImportResult.skipped_rows` entries now carry `is_duplicate` and
  `errors` in addition to `index` and `reason`.
- `EvidenceFile` responses now include `previewable`.

## [1.0.1] - 2026-11-16

Phase 1, complete. All milestones (0 to 5) are done.

### Added

- Milestone 0: development environment probe. PHP 8.3.6, Composer 2.7.1,
  Laravel 13.42.0, Sanctum 4.4, Next.js 16.4.6, React 19.3.0, Tailwind
  CSS 4.3.3, Vitest 5.1.0.
- Milestone 1: authentication API (register, login, logout, forgot
  password, reset password, email verification, profile and password
  update) with rate limiting.
- Milestone 1: multi-tenant organizations with member invitations,
  activation and deactivation, member removal, and organization switching.
- Milestone 1: role-based access control with six roles, one central
  permission map, gates, policies, and owner protections.
- Milestone 1: tenant isolation enforced on the backend (header
  resolution middleware, global query scope, 404 for cross-tenant
  access).
- Milestone 1: Next.js application shell with sidebar navigation,
  organization switcher, user menu, and pages for landing, auth,
  dashboard, organization settings, members and invitations, and profile
  settings.
- Milestone 1: Docker Compose development setup (backend, queue worker,
  frontend, PostgreSQL 16, Redis 7) and Dockerfiles.
- Milestone 1: documentation (README, architecture, database, API,
  OpenAPI, security, development, scope, roadmap) and this changelog.
- Milestone 1: 51 backend feature tests and 16 frontend tests.
- Milestone 2: carriers (list, create, update) and shipments (CRUD,
  forward-only status transitions, archiving, search, filters, sorting,
  pagination) with tenant isolation and permission checks.
- Milestone 2: CSV import in two steps (preview with per-row validation
  errors and duplicate detection, then confirm from the server-side
  preview) and streaming CSV export honoring the current filters.
- Milestone 2: shipment events with seven system categories, custom
  organization categories, duration computed from start and end times,
  and potential/under-review/dismissed statuses.
- Milestone 2: evidence uploads (type and size validation, private
  storage, authorized download and inline preview, categorization,
  verification status) with a permission-gated evidence library.
- Milestone 2: dashboard counts for shipments, shipments with events,
  open events, and evidence. Frontend pages for shipments, shipment
  detail with events and evidence, CSV import, and the evidence library.
- Milestone 2: two new permissions (`delete-events`, `verify-evidence`)
  and shipment export for the billing role, all in the central map.
- Milestone 2: 51 new backend feature tests and 21 new frontend tests
  (102 backend and 37 frontend in total).
- Milestone 3: claims (CRUD, per-organization references CLM-YYYY-NNNN
  from a locked sequence counter, 10-status workflow with controlled
  transitions enforced by one service, append-only status history,
  evidence attach/detach through a pivot, payment recording as the only
  route to paid). Requested, accepted, and recovered amounts are kept
  separate; accepted is never defaulted from requested.
- Milestone 3: evidence requirements per organization (global or per
  event category, required or optional) with seeded illustrative
  defaults, live readiness calculation with snapshots, and overrides
  that record the reviewer and reason. Readiness is informational only.
- Milestone 3: claim deadlines (customer-supplied, with timezone and
  reminder schedule, one active row per claim, superseded rows kept),
  hourly status refresh command (`claimtrail:refresh-deadline-statuses`)
  with upcoming, due soon, overdue, met, and missed states. No deadline
  is ever inferred from an event type.
- Milestone 3: claim package PDFs (barryvdh/laravel-dompdf) generated
  by a queued job onto the private disk, with an evidence index and a
  status timeline, authorized download, and regeneration. Internal
  notes are excluded unless explicitly requested.
- Milestone 3: two new permissions (`attach-claim-evidence`,
  `manage-evidence-requirements`) in the central map. The claim role
  mapping was corrected to the specification: dispatchers no longer
  create claims, billing staff no longer review claims, and operations
  managers gained claim submission. See `config/permissions.php`.
- Milestone 3: dashboard counts for claims and deadlines. Frontend
  pages for claims list, create, detail, edit, review, deadlines, and
  evidence requirements management.
- Milestone 3: 54 new backend feature tests (156 total, 545
  assertions) and 37 new frontend tests (74 total): claim form
  validation, claim form component, and readiness panel component.
- Milestone 4: append-only audit trail (`audit_logs`) for shipments,
  events, evidence, claims, deadlines, membership, and invitations,
  written by model observers plus explicit calls for claim status
  changes, payment recording, and package generation. A correlation id
  middleware honors or generates `X-Correlation-Id`, echoes it on the
  response, and records it on audit rows. One new permission
  (`view-audit-logs`: owner, admin, operations manager). No update or
  delete routes exist, and the model cancels updates and deletes.
- Milestone 4: notifications (`notifications`,
  `notification_preferences`) for claim created, evidence missing,
  ready for review, deadline upcoming and overdue, claim submitted,
  accepted, and rejected, payment recorded, and organization
  invitation. Per-member per-event in-app and email preferences, read
  state, unread count, and dedupe keys (unique per organization and
  user) so repeated runs cannot notify twice. Email goes through a
  queued mailable with 3 tries and backoff. The deadline refresh
  command now notifies instead of only logging counts. Invitation
  notifications go only to users who already have an account; unknown
  emails get the invitation email only.
- Milestone 4: reports (`ReportService`) with explicit financial
  definitions: potential, submitted, accepted, recovered, rejected,
  and outstanding, every figure grouped by currency and never mixed.
  Endpoints for a summary, recovery history by month, and a streaming
  CSV export. The `view-reports` and `export-reports` permissions were
  already in the central map and are now enforced by these endpoints.
- Milestone 4: full dashboard (`DashboardService`): opportunity,
  evidence, readiness, submission, approval, rejection, payment, and
  deadline counts, upcoming deadlines, and per-currency financials,
  with date and status filters. Potential money is labeled estimated,
  never recovered.
- Milestone 4: frontend pages for notifications (list, unread filter,
  mark read, mark all read), reports (date filters, per-currency money
  tables, CSS-bar breakdowns, CSV export), and the audit log (filters,
  before/after viewer); a rebuilt dashboard; an unread badge in the
  app shell that polls on navigation; and a notification preferences
  form in profile settings.
- Milestone 4: 40 new backend feature tests (196 total, 765
  assertions) and 31 new frontend tests (105 total): audit rows,
  notification delivery and preferences, report math and CSV export,
  dashboard counts and filters, reporting helpers, and permission
  helpers.
- Milestone 5: create-event-category UI on the shipment detail page
  (closes the Milestone 2 gap): authorized users add a custom
  organization category without leaving the event section, with
  validation and duplicate-name error states.
- Milestone 5: security headers middleware on all API responses
  (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, and a minimal `Permissions-Policy`).
- Milestone 5: full security review of the tree (tenancy,
  authorization, mass assignment, SQL, files, auth, headers, CORS,
  secrets, error leakage, audit sanitizing). Findings are in
  `docs/SECURITY.md`. Two fixes landed: the security headers above
  and an allowlist guard on the report money-column helper.
- Milestone 5: scripted end-to-end scenario pass (specification
  scenarios A to I) run live over HTTP against a fresh database:
  registration and invitations, shipment and detention event,
  evidence upload, claim and readiness, review, PDF generation and
  download, submission, acceptance, payment, cross-tenant denial,
  and duplicate-free deadline notifications. 9 of 9 pass.
- Milestone 5: 4 new backend feature tests (200 total, 783
  assertions) and 5 new frontend tests (110 total).

### Changed

- Milestone 4: the dashboard `claims_submitted` count now means claims
  with status `submitted` only. In Milestones 2 and 3 it counted
  submitted plus accepted plus partially accepted. Accepted and
  partially accepted claims are now reported under the new
  `claims_approved` count. The `claims_by_status` breakdown was always
  per status and is unchanged.

### Fixed

- Milestone 2: the organization middleware now clears the resolved
  organization at the start of every request, and membership lookups
  bypass the tenant scope, so a stale organization cannot leak into a
  later request in long-running processes or tests.
- Milestone 5: unauthenticated API requests no longer return 500
  ("Route [login] not defined") when they omit the
  `Accept: application/json` header. Guests are never redirected, and
  authentication failures on `/api/*` render as JSON 401. Regression
  tests cover the request with and without the header.
- Milestone 5: frontend id types now match the backend (UUID strings
  for carriers, shipments, events, categories, evidence, claims, and
  the rest; integers only for users, members, and pivot rows). This
  fixed two real bugs the wrong types were hiding: the shipment form
  sent `Number(carrier_id)` and the event form sent
  `Number(event_category_id)`, which turned UUIDs into NaN and
  silently dropped the selection. Both forms now send the string.

### Notes

- The original brief specified Laravel 11. `composer create-project`
  installs the current stable release, so the project uses Laravel 13.
- Docker builds were not verified on the build machine (no Docker
  available there). See `docs/DEVELOPMENT.md`.
- The CI workflow is not in the repository yet; workflow files cannot be
  pushed through the GitHub API and must be added through the web UI.
  See `docs/DEVELOPMENT.md` for the manual step.
- No v1.0.1 git tag exists yet. Create the release and tag through the
  GitHub web UI.
- Not verified on the build machine: Docker builds, migrations against
  live PostgreSQL, the queue worker against live Redis, S3 storage
  paths, emails through a real mailer, and the frontend in a real
  browser. See `docs/PHASE-1-SCOPE.md`.
