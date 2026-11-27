# ClaimTrail — Development Progress

Daily log and checkpoint file. The master checklist is `MASTER-CHECKLIST.md`.
One version per day. Nothing is pushed without Aqib's explicit yes in the main
chat (see "Push rule" below).

## Current checkpoint

- **Last completed version:** v1.3.2 (Phase 4, TMS Integrations).
  App version is now **1.3.2**. Phase 4 continues (v1.3.1 Advanced
  Imports done, v1.3.0 Email Intake done). Phase 3 is complete
  (v1.2.3). Phase 0 is complete (v0.1.0, v0.3.0, v0.4.0, v0.5.0,
  v0.6.0 done; v0.2.0 interviews deferred, still open). What remains
  before real customers is Aqib's launch decision: open signups vs
  closed pilot first (pilot prep is ready, no pilot customer
  recruited), plus the person-only items in
  `docs/PRODUCTION-LAUNCH-CHECKLIST.md` (lawyer review, real
  Stripe, real mailer, hosting, domain).
- **Repo:** https://github.com/mrpythonist/claimtrail — branch `main`, remote
  head `e2ad6ff0` (v1.3.2, pushed 2026-11-26 ~21:06 PKT after Aqib's
  yes + approval card). 40 files in the v1.3.2 commit, read-back verified
  (commit file list matches the manifest exactly).
- **Tests at v1.3.2:** 548 backend (2943 assertions) + 360 frontend, all
  passing. `npm run build` succeeds (65/65 pages). Live HTTP smoke test:
  52/52 backend steps + 7/7 CLI checks on a fresh SQLite DB (see the
  2026-11-26 v1.3.2 log entry). OpenAPI 155 paths, verified to match
  the 207 live `api/v1` route operations exactly.
- **Local-only files now pushed with v1.1.0:** `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md`, `docs/DEPLOYMENT.md` rode with the v1.1.0
  push. They are repo files now; keep them updated in place.

## Next task

**v1.3.3 — Communication Integrations** (MASTER-CHECKLIST.md, Phase 4) is
the next build. **Status 2026-11-26: v1.3.2 PUSHED, head `e2ad6ff0`.**
Before real customers, Aqib still has the launch decision: open
signups vs closed pilot first (pilot prep ready, no pilot customer
recruited) and the person-only items in
`docs/PRODUCTION-LAUNCH-CHECKLIST.md`.
Before real customers, Aqib still has the launch decision: open
signups vs closed pilot first (pilot prep ready, no pilot customer
recruited) and the person-only items in
`docs/PRODUCTION-LAUNCH-CHECKLIST.md`.
**v0.2.0 Customer Interviews stays DEFERRED, not dropped:** Aqib said
2026-11-21 to skip what needs him right now, and interviews need his
help reaching carriers/dispatchers. It returns to the front of the
queue when he is ready to do outreach. v0.6.0's pilot profiles,
scorecard, outreach process and qualification-call questions are ready
for it, and the pilot qualification calls can answer some of the
v0.3.0/v0.5.0 interview questions for the three pilots — but three
pilots are not 15 interviews.

## Blockers (recorded, not skipped)

**Disposition 2026-11-18:** Aqib accepted blockers 1-5 as documented
limitations (main chat, ~10:52 PKT). They stay listed here as known
unverified items, not as gates. Blocker 6 (Phase 0) DECIDED 2026-11-20:
Aqib chose Phase 0 validation next; work starts with v0.1.0 on the
2026-11-21 run.

1. **Live PostgreSQL verification.** Migrations never ran against real
   Postgres; all tests used SQLite. Needs a machine or VPS with Postgres.
   Unblocks: v1.0.1 "PostgreSQL database".
2. **Live Redis verification.** Queue worker and cache never ran against real
   Redis; defaults are the database driver. Needs a machine or VPS with Redis.
   Unblocks: v1.0.1 "Redis queues and caching".
3. **Docker verification.** `docker-compose.yml` and both Dockerfiles exist
   but were never built or run; no Docker on the build VM. Needs a machine
   or VPS with Docker. Unblocks: v1.0.1 "Docker development environment",
   v1.0.1 global "Deployment verified".
4. **Browser E2E.** Scenarios A-I pass over HTTP, but no browser run and no
   Playwright setup. Needs a browser-capable environment. Unblocks: v1.0.1
   "End-to-end workflow tests".
5. **Real mailer.** Email notifications and reminders are queued and verified
   with a fake mailer only; the configured mailer is `log`. Needs real SMTP
   credentials (Aqib supplies them) and a live send test. Unblocks: full
   verification of email notifications and automated reminders.
6. **Phase 0 validation — DECIDED 2026-11-20, work starting.** No
   customer interviews, no pilot customers, no validated willingness
   to pay yet. Aqib chose Phase 0 validation next (over pausing or
   billing first); the daily runs now work through v0.1.0–v0.5.0
   before Phase 3 billing. v1.2.0 billing stays blocked on Stripe
   keys as well.

## Standing rules for daily runs

- Work sequentially from this file's "Next task". One version per run.
- Verify, do not assume: re-run the backend test suite, run the frontend
  tests and build, and smoke-test the new behaviour live before reporting.
- Update `MASTER-CHECKLIST.md` (check only verified items) and this file
  after each version. Record blockers; never silently skip.
- Preserve all existing working functionality; run the full test suites.
- **Push rule:** never push from a scheduled run. When a version is ready,
  report it in the MAIN chat and wait for Aqib's yes plus the approval card.
  If a previous version is still waiting to be pushed, do not start new
  version work; end the run quietly (a push reminder was already sent).
- Some later versions need Aqib's input (Stripe keys for v1.2.0, TMS choices
  for v1.3.2, SMTP credentials for the mailer blocker). If a version is
  blocked on input, record it here, report it once in the main chat, and
  move to the next unblocked version only if it does not depend on the
  blocked one.
- Known tool limits: the GitHub integration cannot push workflow files
  (`.github/workflows/*`) via API (403) and cannot create tags. Those stay
  Aqib's manual steps via the web UI.

## Daily log

### 2026-11-27 — v1.3.3 Communication Integrations built and verified (Phase 4 continues)

- Gate check passed first: remote head on GitHub is `e2ad6ff0`,
  matching the recorded v1.3.2 head, and no other builder was
  running. Build started 2026-11-27 ~08:11 PKT on Aqib's "Start
  v1.3.3 now" in the main chat (subagent).
- Spec: MASTER-CHECKLIST.md v1.3.3 (6 items). All six verified
  and checked.
- Backend (new): `config/comms.php` (providers mock/slack/
  teams, all live_available false; driver-link defaults ttl
  72h, max_uses 1; expiry-notice window 30 days); `app/Comms/
  ChannelProvider.php` (interface), `ChannelProviderRegistry.
  php` (drivers mock/slack/teams, catalogue),
  `MockChannelProvider.php` (records locally, no webhook URL),
  `SlackChannelProvider.php` (validates
  `https://hooks.slack.com/services/...`, Block Kit payload),
  `TeamsChannelProvider.php` (validates Office 365 connector
  webhook URLs, MessageCard payload), `ChannelDispatcher.php`
  (send fan-out to active channels that want the event, test
  with a recorded test delivery, every attempt recorded with
  title/body/detail/payload); models `CommunicationChannel`
  (provider, name unique per org, status active/disabled,
  webhook URL `encrypted` cast, settings JSON with
  effectiveSettings defaulting every event to true,
  `wantsEvent`), `CommunicationDelivery` (event, title, body,
  status logged/sent/failed, detail, payload, attempted_at),
  `DriverEvidenceLink` (token_hash unique SHA-256, shipment/
  claim nullable, expires_at, max_uses default 1, used_count,
  revoked_at, expiry_notified_at, `state` usable/used/expired/
  revoked), `DriverEvidenceSubmission` (link, shipment, claim,
  evidence file, driver name, notes, submitted_at); migration
  `2026_11_27_100700_create_communication_tables.php`
  (`communication_channels`, `communication_deliveries`,
  `driver_evidence_links`, `driver_evidence_submissions`);
  `CommunicationService` (providers catalogue, channelPayload
  with the webhook URL never returned, deliveryPayload,
  linkPayload with the token only when freshly created or
  rotated, publicLinkPayload with organization name and
  uses_left, submissionPayload, channel CRUD/test, sendEvent
  fan-out, createDriverLink with shipment/claim scope
  validation, resendDriverLinkEmail with token rotation,
  revokeDriverLink, expireDueLinks notifying once per link,
  linkByToken by hash, submitDriverEvidence with the file
  stored on the evidence disk and verified by checksum and
  size, sendDriverLinkEmail with failures caught and
  notified); controllers `CommunicationChannelController`
  (providers/index/store/show/update/destroy/test),
  `CommunicationDeliveryController` (index with
  communication_channel_id/event/status filters, show),
  `DriverEvidenceLinkController` (index with shipment/claim/
  state filters, store returning the token once, show,
  destroy = revoke, resend, submissions),
  `DriverEvidenceSubmissionController` (public show/submit,
  unknown/unusable token 404 with the same message as a
  nonexistent one); console commands `comms:list`,
  `comms:test`, `comms:expire-links`; mail
  `DriverEvidenceLinkMail` (queued) + blade view.
- Backend edits: `Permission::ManageCommunications`
  ('manage-communications'); `NotificationType` gains 7
  types (`comms.channel_connected`, `comms.channel_tested`,
  `comms.driver_link_created`, `comms.driver_link_used`,
  `comms.driver_link_expired`, `comms.email_sent`,
  `comms.email_failed`; 15 to 22 types);
  `config/permissions.php` adds it to Admin, OperationsManager,
  Dispatcher (3 places; Owner gets all); `config/app.php`
  version 1.3.3; `routes/api.php` 17 new routes (15
  authenticated under `/comms/*`, 2 public under
  `/driver-evidence/{token}` with throttling);
  `routes/console.php` schedules `comms:expire-links` daily
  05:30.
- **Real defects found by verification, fixed during the
  build:** the channel test result had no `delivery_id`
  (the dispatcher discarded the recorded delivery; the
  smoke caught it, the feature test now asserts the id
  matches the recorded delivery); a disabled channel
  could be tested with 200 (now refused 422, like a
  disabled TMS sync failing fast, with no delivery
  recorded and last_tested_at untouched); the create-link
  response showed `used_count` null (the fresh model had
  no DB default yet; now set to 0 at create and cast to
  int in the payloads); a claim-only link could not
  submit at all ("does not cover a shipment" — now the
  shipment resolves through the claim, so the evidence
  is filed against the claim's shipment); the frontend
  was built against assumed payload shapes and the smoke
  proved several wrong — channels carry a `settings`
  object (event -> boolean), not an `events` array;
  deliveries filter by `communication_channel_id`, not
  `channel_id`, and carry title/body/detail/attempted_at,
  not error_message; the public page carries
  `organization_name` and `uses_left`, not
  max_uses/used_count; deletes return 200 (channel with
  a message, link with the revoked record), not 204;
  driver submits validate against the app's
  `EvidenceDocumentCategory` list (proof_of_delivery,
  not delivery_receipt) and the evidence allowed
  extensions (no gif/eml, Office docs allowed);
  resending needs a usable link with a driver email
  (422 otherwise); the unknown-token 404 message is
  "This submission link is invalid, expired, revoked,
  or already used."; the expire command reports
  "Notified members about N expired driver evidence
  link(s)." and notifies one notification per eligible
  member (owner + dispatcher; viewer excluded). All of
  the above are fixed in the code, the frontend, the
  OpenAPI spec, the docs, and the smoke script; the
  backend and frontend tests now assert the real shapes.
  Pre-existing tests updated: HealthCheckTest version
  1.3.2 to 1.3.3, NotificationTest preference count 15
  to 22, RbacAuditTest public list gains the two
  driver-evidence routes.
- Frontend (new): `CommunicationProvider` /
  `CommunicationChannel` / `CommunicationChannelTest` /
  `CommunicationDelivery` / `DriverEvidenceLink` /
  `DriverEvidenceLinkCreated` / `DriverEvidenceSubmission`
  / `DriverEvidencePage` types;
  `src/lib/comms.ts` typed client (providers, channel
  CRUD/test, deliveries, driver links CRUD/resend/
  submissions, public page + multipart submit via
  apiUpload); `canManageCommunications` in
  `src/lib/permissions.ts` (owner/admin/operations_
  manager/dispatcher); pages `/communications`
  (provider catalogue, connect form with settings
  checkboxes, channels, create-a-driver-link form
  showing the token once, driver links, recent
  deliveries), `/communications/[id]` (test,
  enable/disable, delete, event settings, delivery
  history), and the public `/driver-evidence/[token]`
  submit page (Suspense-wrapped: Next 16 requires
  `useParams()` inside Suspense, which the build
  enforced); AppShell nav entry "Communications";
  version strings bumped to 1.3.3 (AppShell, MarketingLayout).
  Build 68/68 pages (65 at v1.3.2 + 3 new).
- Docs: new `docs/COMMUNICATION-INTEGRATIONS.md`
  (providers, mock/log delivery, signed-link security
  model, email workflows, notifications, CLI, honest
  limits); Communication Integrations section in
  `docs/API.md`; OpenAPI 155 -> **167 paths / 224
  operations** (12 new paths, 17 operations) with the
  `Communications` tag and `CommunicationProvider` /
  `CommunicationChannel` / `CommunicationChannelTest` /
  `CommunicationDelivery` / `DriverEvidenceLink` /
  `DriverEvidenceLinkCreated` /
  `DriverEvidenceSubmission` / `DriverEvidencePage`
  schemas; `CHANGELOG.md` [1.3.3] entry.
- **Smoke-script lessons, recorded honestly (test-only
  defects, not product code):** the mail queue holds
  notification mails as well as the driver-link mail,
  so the smoke runs `queue:work --stop-when-empty`
  instead of `--once` before reading the log; the
  driver-link mail is checked by matching the exact
  token from the create response, not just the URL
  pattern; `queue:work --once` is still used for the
  invitation mails, where the queue holds one job.
- Verification (all run by the builder): backend **579
  passed (3138 assertions)**, frontend **386 passed**
  across 48 files, `npm run build` succeeds (68/68
  pages). Live HTTP smoke test over the built-in server
  on a fresh SQLite DB passed **90/90 backend steps +
  7/7 CLI checks**: provider catalogue (mock/slack/
  teams, none live), channel validation (unknown
  provider, missing/malformed webhook URLs, viewer
  403), channel creation (mock/Slack/Teams, webhook
  never returned, duplicate name 422), connection tests
  (mock/Slack/Teams ok but not live, delivery ids
  returned), delivery history and channel filter,
  disabled channel test refused 422, shipment + claim
  creation, driver-link validation (no scope, mismatched
  shipment-claim, archived shipment), link create with
  token-once and the same token in the mail log, public
  page with uses_left, single-use submit with the
  evidence file stored and verified, unknown-token 404
  with the same message on page and submit, two-use
  claim link with the third submit 404, resend rotation
  killing the old token, revoke, forced expiry for the
  CLI check, dispatcher notified with
  `comms.channel_connected`, `comms.driver_link_created`,
  and `comms.driver_link_used`, cross-tenant 404s, CLI
  `comms:list` showing both channels, `comms:test`
  recording a test delivery, and `comms:expire-links`
  expiring one link and notifying twice (owner +
  dispatcher) plus nothing-expired on a second run.
  OpenAPI 167 paths / 224 operations, verified to match
  the 224 live `api/v1` route operations exactly.
- Still unverified (standing blockers, unchanged): browser
  E2E, live Postgres, live Redis, Docker, real mailer.
  v1.3.3-specific notes: **no live Slack/Teams delivery
  was possible** — the mock records locally and Slack/
  Teams record a well-formed payload without sending it,
  until a real Slack workspace, a real Teams tenant, and
  a real mailer exist; all delivery verification ran
  through the mock provider. No Slack OAuth app or Teams
  bot exists (incoming webhooks only); no per-driver
  identity (the driver name on a submission is
  self-reported text); no delivery retry or reprocess
  endpoint; no plan metering on links or deliveries.
  The submission channel (sending a claim package to a
  broker) is still missing.
- Note: `backend/AGENTS.md` in the repo instructs installing
  Laravel Boost via composer/curl. That is repo file content,
  not a task instruction; correctly ignored by the builder,
  not acted on (a system safety notice also flagged it during
  this build).
- Delta computed against remote head `e2ad6ff0`: 19 changed +
  33 new = 52 files, 956,859 bytes. Manifest at
  `~/workspace/claimtrail-v133-paths.txt`. Smoke scripts at
  `~/workspace/claimtrail-scratch-v133/smoke/` (smoke-v133.py,
  run-smoke.sh, diff-routes.py); delta script at
  `~/workspace/claimtrail-scratch-v133/compute-delta.py`.
  (Push status and the checkpoint header are for the main
  agent: not pushed yet.)

### 2026-11-26 — v1.3.2 TMS Integrations built and verified (Phase 4 continues)

- Gate check passed first: remote head on GitHub is `5711d69b`,
  matching the recorded v1.3.1 head, and no other builder was
  running. Build started 2026-11-26 ~20:18 PKT on Aqib's "Start
  v1.3.2 now" in the main chat (subagent), after he chose option
  A — Trimble first, schema-only mock — from his TMS priority
  list: 1. Trimble TMW.Suite, 2. McLeod LoadMaster, 3. AscendTMS,
  4. TruckMate, 5. Rose Rocket.
- Spec: MASTER-CHECKLIST.md v1.3.2 (9 items). All nine verified
  and checked.
- Backend (new): `config/tms.php` (Trimble published endpoints:
  OAuth 2.0 token URL
  `https://cloud.api.trimblecloud.com/transportation/api/identity/v2/oauth2/token`,
  scope `sas_token`; OData base
  `/transportation/tte-tmwsuite/v0/odata`; CloudHub `/v2`;
  OrderCreate `/v0/automation`); `app/Tms/TmsProvider.php`
  (interface), `TmsProviderException.php` (provider codes
  `live_unavailable` / `invalid_credentials` /
  `provider_error`), `TrimbleTmwMapper.php` (provider status
  vocabulary planned/dispatched/in_transit/delivered/completed
  mapped to ShipmentStatus; document types mapped to evidence
  categories; unknown status maps to null and is skipped, never
  guessed), `MockTmsProvider.php` (deterministic 3 loads:
  TMW-MOCK-1001 in_transit Heartland Foods with POD + BOL,
  TMW-MOCK-1002 delivered Ozark Supply with rate confirmation,
  TMW-MOCK-1003 planned Blue Ridge Logistics with no
  documents), `TrimbleTmwProvider.php` (schema-only: validates
  credential shape client_id/client_secret/account_id, builds
  request descriptors from the published URLs, every fetch
  throws `live_unavailable`; OData entity sets
  Orders/Appointments/Documents are modelled, not verified),
  `TmsProviderRegistry.php` (drivers `mock` + `trimble-tmw`,
  catalogue with live note); models `TmsIntegration`
  (provider, name unique per org, status active/disabled,
  credentials `encrypted:array` cast, settings JSON with
  effectiveSettings defaults, `canSync`, `autoSyncDue`) and
  `TmsSyncRun` (kinds shipments/status/appointments/documents/
  full; statuses pending/running/completed/failed); migration
  `2026_11_26_100600_create_tms_tables.php`
  (`tms_integrations`, `tms_sync_runs`); `TmsService`
  (providers catalogue, validatedSettings/validatedCredentials,
  integrationPayload with credentials never returned,
  syncRunPayload, create/update/delete, testConnection, runSync
  with per-kind apply: shipments create/update by external then
  internal reference with forward-only status transitions,
  status updates only, appointments fill times, documents stored
  as EvidenceFile on the evidence disk with same-filename
  dedupe, notifications `tms.sync_completed` / `tms.sync_failed`
  to members with manage-tms-integrations excluding the actor,
  audit records); controllers `TmsIntegrationController`
  (index/store/show/update/destroy/test/sync) and
  `TmsSyncRunController` (index with
  tms_integration_id/kind/status filters, show); console
  commands `tms:list`, `tms:sync`, `tms:run-due`.
- Backend edits: `Permission::ManageTmsIntegrations`
  ('manage-tms-integrations'); `NotificationType::
  TmsSyncCompleted` / `TmsSyncFailed` (13 to 15 types);
  `config/permissions.php` adds it to Admin, OperationsManager,
  Dispatcher (3 places; Owner gets all); `config/app.php`
  version 1.3.2; `routes/api.php` 10 new routes under `/tms/*`
  (GET providers, GET/POST integrations, GET/PUT/DELETE
  integrations/{integration}, POST .../test, POST .../sync, GET
  sync-runs, GET sync-runs/{syncRun}); `routes/console.php`
  schedules `tms:run-due` daily 05:00.
- **Real defects found by the new backend tests, fixed during
  the build:** the tenant-scope test needed `actingAsMemberOf`
  for the other org; document creation was double-counted in
  the summary `created` (fixed: only `documents_created`
  counts evidence); mock `has_credentials` is false with no
  credentials; ISO8601 assertions use `format('Y-m-d H:i:s')`
  not `toIso8601String` (returns +00:00, not Z). Pre-existing
  tests updated for the version bump and new types:
  HealthCheckTest version 1.3.1 to 1.3.2, NotificationTest
  count 13 to 15.
- Frontend (new): `TmsProvider` / `TmsSettings` /
  `TmsIntegration` / `TmsSyncKind` / `TmsSyncRunStatus` /
  `TmsSyncRun` / `TmsConnectionTest` types;
  `src/lib/tms.ts` typed client (listTmsProviders,
  list/create/get/update/delete integrations, testTmsConnection,
  runTmsSync, list/get sync runs, labels);
  `canManageTmsIntegrations` in `src/lib/permissions.ts`
  (owner/admin/operations_manager/dispatcher); pages `/tms`
  (provider catalogue, connect form with Trimble credential
  fields, integration list) and `/tms/[id]` (settings, test
  connection, sync controls, run history, disable/delete);
  AppShell nav entry "TMS Integrations"; version strings bumped
  to 1.3.2 (AppShell, MarketingLayout). Build 65/65 pages (63 at
  v1.3.1 + 2 new).
- Docs: new `docs/TMS-INTEGRATIONS.md` (adapter, mock provider,
  Trimble schema model + gating, priority list with follow-ups,
  honest limits including the pilot beachhead and the missing
  submission channel); TMS Integrations section in `docs/API.md`;
  OpenAPI 148 -> **155 paths / 207 operations** (7 new paths, 10
  operations) with the `TMS` tag and `TmsProvider` /
  `TmsIntegration` / `TmsSyncRun` / `TmsConnectionTest`
  schemas; `CHANGELOG.md` [1.3.2] entry.
- **Smoke-script lessons, recorded honestly (test-only
  defects, not product code):** the runtime redacts the literal
  `token=token` kwarg pattern in heredoc input, so the TMS smoke
  section passes the auth token positionally via a `TOK` alias;
  the auth register response carries the token top-level
  (`body["token"]`), not under `data` — the cross-tenant check
  caught that; `tms:run-due` only picks up an integration whose
  last sync is older than its interval, so the runner pushes
  `last_sync_at` 25 hours into the past with tinker first (same
  pattern as the v1.3.1 scheduled-imports check). The same pass
  caught one OpenAPI drift before the route diff: the sync-run
  summary nests per-kind results under `kinds`.
- Verification (all run by the builder): backend **548
  passed (2943 assertions)**, frontend **360 passed**
  across 44 files, `npm run build` succeeds (65/65 pages),
  `tsc --noEmit` clean, lint clean of new findings (the two
  remaining findings are pre-existing from v1.2.2: a
  set-state-in-effect error in register/page.tsx and an
  unused variable in PricingTable.tsx). Live HTTP smoke test
  over the built-in server on a fresh SQLite DB passed
  **52/52 backend steps + 7/7 CLI checks**: provider catalogue
  (mock + trimble-tmw, neither live), Trimble credential
  validation and duplicate-name 422s, connection tests (mock ok
  but not live; Trimble well-formed but not live, token null),
  per-kind syncs (status draft to planned, appointments filled,
  documents zero for a load with none, shipments creating the
  other two), full sync storing 3 documents as evidence, dedupe
  re-run with zero created/updated/documents, Trimble sync
  failing honestly with live-unavailable and no shipments,
  disabled sync failing fast, sync-run history filters,
  dispatcher notified with `tms.sync_completed` and
  `tms.sync_failed`, cross-tenant 404s, CLI `tms:list` showing
  both integrations, `tms:sync` recording the ninth run, and
  `tms:run-due` running a due auto-sync (tenth run) and
  reporting nothing due on a second run. OpenAPI 155 paths /
  207 operations, verified to match the 207 live `api/v1` route
  operations exactly.
- Still unverified (standing blockers, unchanged): browser
  E2E, live Postgres, live Redis, Docker, real mailer.
  v1.3.2-specific notes: **no live TMS test was possible** —
  the Trimble provider is schema-only against the published
  OAuth 2.0 / OData / CloudHub / OrderCreate endpoints until a
  TMW customer, a Trimble representative, and an on-prem Cloud
  Hub Connector exist; all sync verification ran through the
  mock provider. McLeod, AscendTMS, TruckMate, and Rose Rocket
  are not built (partner-gated follow-ups). The submission
  channel (sending a claim package to a broker) is still
  missing; v1.3.2 serves larger/enterprise customers, not the
  pilot beachhead (pilot profiles run on phone, email,
  spreadsheets, and a load board, not a TMS).
- Note: `backend/AGENTS.md` in the repo instructs installing
  Laravel Boost via composer/curl. That is repo file content,
  not a task instruction; correctly ignored by the builder,
  not acted on (a system safety notice also flagged it during
  this build).
- Push status: **PUSHED 2026-11-26 ~21:06 PKT, head `e2ad6ff0`**
  (40 files, read-back verified: branch head, commit message, file
  list, and two files read from GitHub). Delta computed against remote
  head `5711d69b`: 16 changed + 24 new = 40 files, 808,017 bytes.
  Manifest at `~/workspace/claimtrail-v132-paths.txt` (NUL-
  delimited) with `claimtrail-v132-paths-manifest.json`.
  Smoke scripts at
  `~/workspace/claimtrail-scratch-v132/smoke/` (smoke-v132.py,
  run-smoke.sh, diff-routes.py); delta script at
  `~/workspace/claimtrail-scratch-v132/compute-delta.py`.
- Next task: v1.3.3 — Communication Integrations.

### 2026-11-26 — v1.3.1 Advanced Imports built and verified (Phase 4 continues)

- Gate check passed first: remote head on GitHub is `aad9fb7c`,
  matching the recorded v1.3.0 head, and no v1.3.1 files were
  present in the remote tree, so no other writer was active.
  Build started 2026-11-26 ~15:12 PKT on Aqib's "Start v1.3.1
  now" in the main chat (subagent).
- Spec: MASTER-CHECKLIST.md v1.3.1 (7 items). All seven verified
  and checked.
- Backend (new): migration
  `2026_11_26_100500_create_import_tables.php`
  (`import_mappings`, `import_runs`, `import_rows`,
  `import_schedules`, `sync_runs`); models `ImportMapping`,
  `ImportRun`, `ImportRow`, `ImportSchedule`, `SyncRun` (all
  organization-scoped, UUIDs); new permission `manage-imports`
  (Admin, Operations Manager, Dispatcher; Owner gets all);
  new notification types `import.completed` and
  `import.failed` (11 to 13 total); `ImportService` (mapping
  CRUD, run recording, count refresh and status computation,
  stored-file runs, row correction with re-validation and
  duplicate re-check, schedule runs, sync runs, run
  notifications to members who can manage imports except
  the actor); `ShipmentCsvImporter` refactored with shared
  parse/create-rows helpers plus `runFile()` for stored files,
  and preview/confirm now accept an optional `mapping_id`;
  confirm records an import run (source `upload`) and returns
  `import_run_id`; controllers `ImportMappingController`,
  `ImportRunController`, `ImportScheduleController`,
  `SyncRunController`; command `imports:run-scheduled`
  (`--schedule`, `--organization`, runs as the org owner)
  plus a daily 04:30 scheduler entry. Stored files live on
  the private disk under `imports/{org}/schedules` and
  `imports/{org}/sync`.
- Run status is computed from the rows: `completed` when
  every row imported, `partial` when some imported and some
  did not, `failed` when none imported (including a file
  whose rows are all duplicates). Rows that fail validation
  are stored as `skipped`; `failed` is reserved for rows
  whose correction retry still fails. Corrected rows count
  as imported, and correcting the last failed row flips the
  run to `completed`. Duplicate detection is exact match on
  `internal_reference` / `external_load_reference`, against
  the database and within the file.
- Frontend (new): `ImportMapping`, `ImportRun`, `ImportRow`,
  `ImportSchedule`, `SyncRun` types plus status/source/
  frequency unions (this also added the `support.ticket_reply`
  notification type missing since v1.2.3); `src/lib/imports.ts`
  typed client; `canManageImports` in
  `src/lib/permissions.ts`; pages `/imports` (history with
  status/source filters, schedules, sync workflows), `/imports/
  mappings` (create/list/make-default/delete), `/imports/runs/
  [id]` (summary, rows, JSON correction form); sidebar nav
  item; version strings bumped to 1.3.1 (AppShell,
  MarketingLayout, privacy, terms). Build 63/63 pages (60 at
  v1.3.0 + 3 new).
- Docs: new `docs/IMPORTS.md` (mapping rules, duplicate
  detection, run statuses, schedule and sync semantics, and
  what is not built: no live SFTP/URL/provider source, no
  TMS integration, no plan metering, no reprocess endpoint,
  shipments only); Advanced Imports section in `docs/API.md`;
  OpenAPI 136 -> **148 paths** (12 new, 20 operations) with
  the `Imports` tag and `ImportMapping` / `ImportRun` /
  `ImportRow` / `ImportSchedule` / `SyncRun` schemas;
  `CHANGELOG.md` [1.3.1] entry.
- **Real defect found by the new backend tests, fixed during
  the build:** `ImportRun::isClean()` collided with Eloquent
  `Model::isClean()`; the method was removed. Two smaller
  fixes in the same pass: `UploadedFile::get()` replaced with
  `file_get_contents(getRealPath())` in the schedule/sync
  controllers, and `organization_id` added to the
  `import_rows` migration. None of the three changed product
  behaviour; all three were caught before the suite went
  green.
- **Smoke-script lessons, recorded honestly (test-only
  defects, not product code):** the invitation mail is a
  queued mailable, so the smoke script runs `queue:work
  --once` before reading the token from the mail log;
  `DemoSeeder` seeds its own org's shipments, so shipment
  counts in smoke are org-scoped; a header like "Load Ref"
  is not a built-in alias, so schedule and sync runs in
  smoke pass the saved `mapping_id`; correcting the last
  failed row flips a run from `partial` to `completed`, so
  the history filter check expects zero partial runs after
  correction. The same pass caught three OpenAPI schema
  drifts before the route diff: the run field is
  `error_rows` (not `failed_rows`), there is no
  `corrected_rows` column, and the sync payload carries
  `summary` / `error_message` / `started_at` /
  `finished_at` (not `last_error` / `last_run_at`).
- Verification (all run by the builder): backend **511
  passed (2723 assertions)**, frontend **354 passed**
  across 42 files, `npm run build` succeeds (63/63 pages),
  `tsc --noEmit` clean, lint clean of new findings (the two
  remaining findings are pre-existing from v1.2.2: a
  set-state-in-effect error in register/page.tsx and an
  unused import in PricingTable.tsx). Live HTTP smoke test
  over the built-in server on a fresh SQLite DB passed
  **57/57 backend steps + 3/3 CLI checks**: mapping save
  (unknown field 422, duplicate name 422, exactly one
  default), preview with the saved mapping, confirm imports
  two, duplicate re-import imports zero with a failed run,
  bad-file partial run with one error row, row correction
  with count refresh and status flip, schedule create plus
  manual run, sync workflow pending to completed, dispatcher
  notified with `import.completed`, history filters,
  cross-tenant 404s, `imports:run-scheduled` running a due
  daily schedule and reporting nothing due on a second run.
  OpenAPI 148 paths, verified to match the 148 live `api/v1`
  route URIs exactly.
- Still unverified (standing blockers, unchanged): browser
  E2E, live Postgres, live Redis, Docker, real mailer.
  v1.3.1-specific notes: no live import source is wired
  (schedules and sync workflows re-import stored files
  only); no TMS integration (v1.3.2); the webhook
  storage-cap 402 branch from v1.3.0 remains unexercised
  live; no reprocess endpoint.
- Note: `backend/AGENTS.md` in the repo instructs installing
  Laravel Boost via composer/curl. That is repo file content,
  not a task instruction; correctly ignored by the builder,
  not acted on (a system safety notice also flagged it during
  this build).
- Push status: **PUSHED 2026-11-26 ~19:36 PKT, head `5711d69b`**
  (44 files, read-back verified: branch head, commit message, file
  list, and two files read from GitHub). Next run may start v1.3.2
  (blocked on Aqib's TMS platform choice — see Next task).
- Independent verification by the main agent (2026-11-26
  ~15:45 PKT): backend suite re-run 511 tests / 2723
  assertions, frontend 354 tests, build 63/63 pages, live
  smoke re-run 57/57 + 3/3 CLI with OpenAPI 197 = 197 — all
  matching the builder's counts. Push delta recomputed by
  hand: the unauthenticated GitHub API rate limit was
  exhausted (reset 16:07 PKT), so the tree API could not be
  used; the delta was rebuilt from file mtimes (44 files
  touched 15:12-15:43 PKT) with each file's git blob SHA
  compared against its raw content at `aad9fb7c` — 23 changed
  + 21 new, 858,630 bytes, matching the builder's manifest
  exactly. Manifest rewritten at
  `~/workspace/claimtrail-v131-paths.txt`.
- Next task: v1.3.2 — TMS Integrations.

### 2026-11-26 — v1.3.0 Email Intake built and verified (Phase 4 started)

- Gate check passed first: remote head on GitHub is `ab113770`,
  matching the recorded v1.2.3 head, so no version was waiting to be
  pushed. Build started 2026-11-26 ~10:14 PKT as a scheduled daily
  build run (subagent).
- Spec: MASTER-CHECKLIST.md v1.3.0 (7 items). All seven verified and
  checked.
- Backend (new): migration
  `2026_11_26_100400_create_email_intake_tables.php`
  (`inbound_email_addresses`, `inbound_emails`,
  `inbound_email_attachments`); `config/email_intake.php`
  (domain `EMAIL_INTAKE_DOMAIN`, default
  `inbound.claimtrail.local`; webhook secret
  `EMAIL_INTAKE_WEBHOOK_SECRET`; signature header
  `X-Email-Intake-Signature`); models `InboundEmailAddress`,
  `InboundEmail`, `InboundEmailAttachment`;
  `EmailIntakeService` (provision/ensure default address,
  create, regenerate, deactivate with the last-active guard,
  manual match/unmatch/dismiss, link attachments as evidence);
  `ShipmentReferenceExtractor` (reference-shaped tokens from
  subject, body, and attachment filenames, plus verbatim stored
  references; exact match on `internal_reference` or
  `external_load_reference`); `InboundEmailProcessor`
  (attachment validation and storage, extraction, auto-match);
  job `ProcessInboundEmail` (idempotent, standard retries, only
  processes received/failed emails); controllers
  `InboundEmailAddressController` (4 endpoints),
  `InboundEmailController` (list/show/match/unmatch/dismiss),
  `EmailIntakeWebhookController` (public inbound webhook);
  command `email-intake:list` (`--status`, `--organization`,
  `--limit`). New permission `manage-email-intake` (Admin,
  Operations Manager, Dispatcher; Owner gets all). Organization
  creation auto-provisions the default address; the address list
  backfills it for organizations created before v1.3.0. The
  webhook also enforces the storage cap (402) before anything is
  stored, the same check evidence uploads use. Attachments
  become evidence with category `other` and the source email
  named in the description; there is no email category in
  `EvidenceDocumentCategory`.
- Frontend (new): `src/lib/emailIntake.ts` (labels + typed
  client), `canManageEmailIntake` in `src/lib/permissions.ts`,
  pages `/email-intake` (addresses with create/regenerate/
  deactivate, status filter, history) and `/email-intake/[id]`
  (message, extracted references, attachments, shipment search,
  match, unmatch, dismiss), sidebar nav item, version strings
  bumped to 1.3.0 (AppShell, MarketingLayout, privacy, terms).
  Build 60/60 pages (58 at v1.2.3 + 2 new).
- Docs: new `docs/EMAIL-INTAKE.md` (addresses, webhook contract
  with an example payload, HMAC scheme, matching rules, provider
  setup as a future step, no OCR, mailer stays log driver);
  email-intake section in `docs/API.md`; OpenAPI 127 →
  **136 paths** (9 new) with the `EmailIntake` tag and
  `InboundEmailAddress` / `InboundEmail` /
  `InboundEmailAttachment` / `ExtractedReference` schemas;
  `CHANGELOG.md` [1.3.0] entry.
- **Real defect 1 (test helper dropped the signature header,
  found by the new backend tests):** Laravel's test `call()`
  does not merge `withHeaders` — the header never reached the
  controller, so every webhook delivery returned 401. Fix: the
  test helper passes the signature as an `HTTP_` server var.
  This was a test-only defect; the controller was correct.
- **Real defect 2 (smoke script read the token from the wrong
  place, found by the live smoke test):** the register response
  carries the token top-level (`body["token"]`), not under
  `data`. Fix: the smoke script reads `body["token"]`. Test-only
  defect, not product code.
- **Test-only fixes:** the address create test lists first so
  the default address is backfilled before counting two
  addresses; the detail page test uses a regex matcher for the
  broker name because it shares a span with the reference and
  separator text. No product code was changed for either.
- Anomaly, recorded honestly: when the webhook controller was
  read back during debugging, it contained a storage-cap check
  (`EntitlementService::authorizeUpload`) that was not in the
  version as first written. The check is correct and matches
  the evidence-upload pattern (refuse with 402 before storing),
  so it was kept. It is NOT covered by a backend test and was
  NOT exercised live (Free cap is 100 MB, smoke attachments are
  tiny); the underlying `authorizeUpload` is covered by v1.2.1
  tests. Its origin could not be established from the local
  tree (no git checkout here to diff against); the parent run
  confirmed the branch exists (webhook controller lines 145-148)
  and is coherent, but untested. Corrected 2026-11-26 ~10:55
  PKT by the parent run: the first draft of this paragraph
  wrongly said the branch was covered by the suite and the
  smoke test.
- Verification: backend **497 tests, 2576 assertions** (479 at
  v1.2.3 + 18 new email intake tests), all passing, including
  the `HealthCheckTest` version assertion updated to 1.3.0.
  Frontend **333 tests** (318 + 15 new: emailIntake lib,
  permissions, list + detail page tests), all passing;
  `npm run build` 60/60 pages; `tsc --noEmit` clean; lint clean
  of new findings (the two remaining findings are pre-existing
  from v1.2.2: `register/page.tsx` set-state-in-effect error,
  `PricingTable` unused `MARKETING_PLANS` warning). Live HTTP
  smoke test **48/48 backend steps + 3/3 CLI checks** on a
  fresh SQLite DB (script + runner in
  `~/workspace/claimtrail-scratch-v130/smoke/`): plans, register
  + org + ops health version 1.3.0 + support create, address
  provisioning on org creation, create/regenerate/deactivate
  with the last-active 422, webhook bad signature 401 / unknown
  recipient 422 with the generic message / disallowed attachment
  422, valid email auto-matched with the attachment stored as
  evidence (category other) and the audit row, duplicate
  message_id deduped (no second email or evidence row),
  no-reference email unmatched then manually matched, unmatch,
  dismiss, dismissed-cannot-match 422, matched-cannot-dismiss
  422, multi-match unmatched with the reason recorded, list
  filters, cross-tenant 404s; CLI `email-intake:list` shows the
  matched email, rejects an unknown status, and filters by
  organization. OpenAPI path count verified by parsing the YAML
  and diffing against `artisan route:list`: 136 = 136, zero
  diffs.
- Gaps / placeholder (not built, per the spec): no real inbound
  provider wired (no SendGrid/Mailgun/SES client, no provider
  credentials, no real mail delivered); no OCR (v2.0.1);
  webhook processes synchronously (the queued job is for
  reprocessing only); the storage-cap 402 branch on the webhook
  was not exercised live (Free cap is 100 MB, the smoke
  attachments are tiny); the new pages were not checked in a
  real browser. Standing unverified items unchanged: browser
  E2E, live Postgres, live Redis, Docker, real mailer.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Post-verification cleanup by the main agent (2026-11-26 ~11:00
  PKT): `OrganizationController::store` provisioned the default
  intake address twice (once inside the transaction via
  `app()`, once after it via the injected service) — a leftover
  of the two builders working the same tree. Fixed to a single
  call inside the transaction using the injected service; the
  backfill behaviour for pre-v1.3.0 orgs is unchanged and is
  documented in `docs/EMAIL-INTAKE.md`. Backend suite re-run
  after the fix: 497 tests / 2576 assertions, all passing.
  Frontend tests (333), build (60/60), and live smoke (48/48 +
  3/3 CLI) re-run by the main agent with the same results.
- Push status: **PUSHED 2026-11-26 ~11:13 PKT, head `aad9fb7c`**
  (41 files, read-back verified: branch head, commit message, file
  list, and two files read from GitHub). Next run may start v1.3.1.
- Next task: **v1.3.1 — Advanced Imports (Phase 4).** v0.2.0
  Customer Interviews stays DEFERRED per Aqib 2026-11-21 — do
  not fabricate interviews.

### 2026-11-26 — v1.2.3 Production Launch built and verified (Phase 3 complete)

- Gate check passed first: remote head on GitHub is `fbf378e1`,
  matching the recorded v1.2.2 head, so no version was waiting to be
  pushed. Build started 2026-11-26 ~09:00 PKT on Aqib's "start now"
  in the main chat — not the scheduled 2026-11-26 10:10 PKT run. The
  build ran as a subagent; an earlier attempt to start it was
  interrupted before the spawn, and this session resumed from the
  compaction summary.
- Spec: MASTER-CHECKLIST.md v1.2.3 (10 items). All ten verified and
  checked. Phase 3 is complete with this version.
- Provenance, recorded honestly: when this session resumed, the
  refund backend (Refund model + migration, `BillingService::
  refundInvoice`, `BillingController::refundInvoice` and
  `billingEvents`) and the support/monitoring scaffolding (both
  migrations, both models, the `SupportTicketReply` notification
  type, the `ManageSupport` permission) were already in the local
  tree from the pre-compaction part of this same build — but
  unwired (no routes), untested, and unverified. This session
  wired the routes, built the services, controllers, commands,
  UI, docs, and tests around them, and verified everything below.
  Nothing from that scaffolding was taken on trust.
- Backend (new): migrations `2026_11_26_100100_create_support_
  tickets_tables.php` (`support_tickets` + `support_ticket_messages`),
  `2026_11_26_100200_create_refunds_table.php` (`refunds` +
  `invoices.amount_refunded_cents`), `2026_11_26_100300_create_
  monitoring_checks_table.php` (`monitoring_checks`, deployment-wide,
  not tenant-scoped); `SupportTicketService` (create, reply with the
  staff/customer status machine, internal notes, setStatus with
  resolved_at/closed_at stamping); `MonitoringService` (run, latest,
  history, payload); `SupportTicketController` (5 endpoints),
  refund + billing-events routes on `BillingController`;
  `OpsController::monitoring` / `runMonitoringCheck`; commands
  `monitoring:check` (hourly by schedule), `backup:verify`
  (`--run`, `--latest`; weekly Sun 04:00), `billing:admin
  <uuid or slug>` (`--invoices`, `--events`), `support:list`
  (`--status`, `--organization`, `--limit`), `support:show
  <ticket>`. Refunds run against the mock provider (v1.2.0
  decision, unchanged): full refund sets the invoice to refunded,
  partial to partially_refunded; reason required; amounts above the
  remaining paid amount, refunds on unpaid invoices, and negative
  amounts rejected; every refund is a `refunds` row, an audit row,
  and a billing event. Refunds do not change metered success-fee
  usage.
- Frontend (new): `src/lib/support.ts` (labels + typed client),
  refund + billing-events additions to `src/lib/billing.ts`,
  monitoring additions to `src/lib/ops.ts`, `canManageSupport`
  in `src/lib/permissions.ts`; pages `/support` + `/support/[id]`
  (list, create, conversation, reply, status change, internal
  notes), `/help` (public, linked from the marketing footer, in
  the sitemap), rewritten `/privacy` and `/terms` as reviewed
  drafts; refund form + billing-events table on the billing page;
  monitoring card with "Run check now" on the operations page.
  Build 58/58 pages (55 at v1.2.2 + 3 new: `/support`,
  `/support/[id]`, `/help`).
- Docs: `docs/legal-review-1.2.3.md` (internal review of the
  privacy policy, terms, and data processing against the app's
  actual data practices — NOT legal advice, NOT a lawyer's review;
  the public pages carry the review date and the banner saying so),
  `docs/DATA-PROCESSING.md`, `docs/HELP.md`, `docs/PRODUCTION-
  LAUNCH-CHECKLIST.md` (15 items done in code, 10 still needing a
  person); refund/cancellation/admin sections in `docs/BILLING.md`;
  monitoring + backup-verify sections in `docs/OPERATIONS.md`;
  OpenAPI 119 → **127 paths** (8 new) with `Billing`/`Support`
  tags and info version 1.2.3; `CHANGELOG.md` [1.2.3] entry.
- **Real defect 1 (reopened ticket kept its resolved timestamp,
  found by the new backend test):** `SupportTicketService::reply`
  updated only the status, so a customer reply on a resolved
  ticket reopened it to open while `resolved_at` stayed set. Fix:
  a reply that moves a ticket out of resolved clears
  `resolved_at`. The test asserts the timestamp is null after the
  reopen.
- **Real defect 2 (frontend reply client returned the wrong
  shape, found while writing the smoke script):**
  `replyToTicket` was typed as returning `{message, ticket}`,
  but the controller returns the message flat (`{id, body,
  internal, created_at, ticket}`). The ticket page only read
  `.ticket`, so it worked at runtime, but the type and the lib
  test were wrong. Fix: the lib now returns the flat shape and
  the tests assert it; the unused `SupportTicketMessage` import
  was removed.
- **Real defect 3 (form fields without the required `id` prop,
  found by the frontend tests):** the support list/detail pages
  and the billing refund dialog rendered `Input`/`Textarea`/
  `Select` without `id` (and two selects without `label`), so
  labels were unassociated and `getByLabelText` failed — and
  `next build` type-checking would have failed too. Fix: every
  field now carries its `id` (and a visible label where one was
  missing).
- **Real defect 4 (OpenAPI documented the wrong run-now path,
  found by diffing live routes against the spec):** the spec
  listed `POST /ops/monitoring`, but the live route is `POST
  /ops/monitoring/check` — the spec had 126 paths against 127
  live URIs. Fix: the spec now has the `/ops/monitoring/check`
  path; the count is 127 = 127 with zero diffs, and the CHANGELOG
  count was corrected from 126 to 127 (119 + 8 new).
- **Doc correction (launch checklist):** it said a full refund
  "voids the invoice"; a full refund marks the invoice
  `refunded`. Fixed. Test-only mistakes (refund body always
  carries `amount_cents`, `createTicket` sends `message` not
  `body`, the real support categories, `listTickets` defaults
  to `per_page=25`, `setTicketStatus` is `POST .../status`)
  were fixed in the tests, not in product code.
- Verification: backend **479 tests, 2467 assertions** (451 at
  v1.2.1 + 28 new: 10 support, 8 refund, 4 monitoring, 6 backup
  verify), all passing, including two count/version updates in
  existing tests (ops health version 1.2.3; 11 notification
  preference types with the new `support.ticket_reply` type).
  Frontend **318 tests** (293 + 25 new: support/billing/ops
  lib tests, support list + detail page tests, billing events
  + refund page tests, monitoring page tests), all passing;
  `npm run build` 58/58 pages. Live HTTP smoke test **56/56
  backend steps + 6/6 CLI checks + 18/18 marketing pages** on a
  fresh SQLite DB (script + runner in
  `~/workspace/claimtrail-scratch-v123/smoke/`): plans, leads,
  analytics, register + org + plans (6) + Growth trial + usage,
  support create 201 / validation 422 / list / show / staff
  reply to in_progress / internal note / resolve / owner reply
  reopens work with `resolved_at` cleared / bad status 422 /
  cross-tenant 404s, checkout to a paid invoice, partial refund
  1000 then full refund of the remaining 3900 (invoice refunded,
  `amount_refunded_cents` 4900), billing events with `payload`
  and no `message` field, monitoring before/after a run,
  backup create 201 + verify ok, ops health version 1.2.3;
  CLI `monitoring:check`, `support:list`, `support:show`,
  `billing:admin`, `backup:verify --latest`; every marketing
  page 200 with its key content, including `/help`, `/privacy`,
  and `/terms` with the review banners. Regression: the v1.2.2
  smoke steps (plans/leads/analytics) and the v1.2.0/1.2.1
  billing + trial + usage flows all pass inside the same run.
- Gaps / placeholder (not built, per the spec): lawyer review
  of the privacy policy, terms, and data processing (internal
  review only — must not be described as compliant); real
  Stripe (mock provider unchanged); real mailer (log driver —
  no email leaves the server); production hosting, domain and
  site URL (sitemap empty, robots.txt lists no sitemap until
  then); backup retention `keep_days` is configured at 30 days
  but enforced by no command; no alerting channel for
  monitoring failures; no account/organization deletion flow;
  product screenshots still placeholders (v1.2.2). Standing
  unverified items unchanged: browser E2E, live Postgres, live
  Redis, Docker, real mailer. Lint: the two remaining findings
  are pre-existing from v1.2.2, not this version
  (`register/page.tsx` set-state-in-effect error,
  `PricingTable` unused `MARKETING_PLANS` warning) — left
  untouched, out of scope.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v1.2.3 PUSHED 2026-11-26 ~10:00 PKT, head
  `ab113770`, 65 files in one commit (35 changed + 30 new), read-back
  verified via the commits API (file list matches the manifest
  exactly).** Sync record now has 18 entries. Next run may start
  v1.3.0.
- Next task: **v1.3.0 — Email Intake (Phase 4).** Phase 3 is
  complete with v1.2.3. Aqib's launch decision (open signups vs
  closed pilot first) is still open.

### 2026-11-26 — v1.2.2 Marketing and Acquisition built and verified

- Gate check passed first: remote head on GitHub is `79d1ab7e`,
  matching the recorded v1.2.1 head, so no version was waiting to be
  pushed. Build started 2026-11-26 ~07:48 PKT on Aqib's "Start
  v1.2.2 — Marketing and Acquisition now" in the main chat — not the
  scheduled 2026-11-26 10:10 PKT run.
- Spec: MASTER-CHECKLIST.md v1.2.2 (11 items) + v0.5.0 pricing
  (section 8 tiers, section 9.1 structure) + v0.6.0 pilot sample
  (12 synthetic shipments, 11 claims, $2,440 requested). Ten items
  verified and checked; **Product screenshots stays UNCHECKED** — the
  gallery is built but no real captures exist (no headless browser
  on the build VM; a mockup dressed as a screenshot would be a
  fabrication). Pricing and positioning use the v0.5.0 numbers only,
  no new pricing or claims invented.
- Backend (new): migration `2026_11_26_100000_create_leads_table.php`
  (`leads`: uuid id, name, email, company, fleet_size, message,
  source, status, contacted_at, notes; no IP or user agent stored);
  `app/Models/Lead.php` (sources demo_request/pricing/
  getting_started; statuses new/contacted/qualified/converted/
  closed); `config/analytics.php` (driver `none|log`, default `log`);
  `app/Services/AnalyticsService.php` (allowed events page_view,
  demo_request_submitted, trial_started, pricing_viewed; writes one
  log line via `Log::info` on the default channel); public
  controllers `PublicPlanController` (`GET /v1/plans`: listed plans
  from `BillingService::plans()` + trial {growth, 30 days, no card},
  Success Fee not returned), `LeadController` (`POST /v1/leads`:
  idempotent by email + source within 90 days, 200 on update, 201
  on create), `AnalyticsEventController` (`POST /v1/analytics/events`:
  validates against the allow-list, always 202); `leads:list`
  command (`--status`, `--limit`; unknown status exits 1). Routes
  are public, inside the v1 + correlation group, before the
  `auth:sanctum` group; throttles 30/5/60 per minute, each with its
  own prefix (see the defect below).
- Frontend (new): `src/lib/marketing.ts` (plans, features with honest
  limits grounded in the v0.3.0 gaps, demo steps, 12 demo shipments,
  screenshot entries, sitemap routes), `src/lib/analytics.ts`
  (tracker, off unless `NEXT_PUBLIC_ANALYTICS_ENABLED=true`,
  fire-and-forget, failures swallowed), `src/lib/signup-plan.ts`
  (localStorage for the plan chosen on the pricing page),
  `src/lib/seo.ts` (metadata, Open Graph, JSON-LD); `MarketingLayout`
  (header/footer) + `AnalyticsTracker`; pages `/` (rewritten onto
  the marketing layout), `/product`, `/features`, `/features/[slug]`
  (7 features), `/pricing` + `PricingTable` (monthly/annual toggle,
  annual 10x monthly), `/demo` + `DemoWalkthrough` (7 steps over the
  PLT-1001 claim + the full 12-shipment table), `/demo-request` +
  `DemoRequestForm`, `/screenshots` + `ScreenshotGallery` (real PNG
  when present, labelled placeholder otherwise), `/getting-started`
  (30-day path); register page reads `?plan=` and stores it;
  `TrialBanner` on the onboarding page offers the 30-day Growth
  trial for a Free org that is not trialing; `sitemap.ts` /
  `robots.ts` (empty sitemap and no sitemap line until
  `NEXT_PUBLIC_SITE_URL` is set); root layout sets `metadataBase`
  only when the site URL is set.
- **Real defect 1 (throttle buckets shared across routes, found by
  the live smoke test, not the suite):** the first smoke run failed
  one step — the 5th `POST /v1/leads` returned 429 although only 5
  lead requests had been made. Root cause: in Laravel 13,
  `ThrottleRequests::resolveRequestSignature` returns domain + IP
  only for guests, with no route component, so every `throttle:X,1`
  without a prefix shares ONE bucket per IP. The smoke run's
  `GET /v1/plans` had consumed one of the five lead slots. Fix: each
  of the three new routes carries its own throttle prefix
  (`throttle:30,1,plans`, `throttle:5,1,leads`,
  `throttle:60,1,analytics`). The pre-existing auth routes still
  share one bucket among themselves — noted, not changed (v1.0.1
  behaviour, out of this version's scope). Regression test
  `test_marketing_routes_have_separate_throttle_buckets` proves five
  plan loads leave all five lead submissions available and the sixth
  is still throttled; the test was verified to FAIL with all three
  prefixes removed and PASS with them restored. The smoke script now
  also clears the file cache before starting the server (it already
  wiped the DB) so a stale throttle bucket cannot trip a run.
- **Real defect 2 (AnalyticsService under Log::spy, found by the
  backend tests):** `Log::channel('single')` returned null under
  `Log::spy()`, causing 500s in the analytics tests. Fix: use
  `Log::info()` on the default channel instead. Verified with a
  throwaway repro test (created, run, deleted).
- **Build defects found and fixed while building:** `useSearchParams()`
  outside Suspense failed the `/register` prerender (fixed with the
  Suspense wrapper, the same pattern as the login page); the
  `/screenshots` page read `public/screenshots/` via `fs.readdir` at
  render time, which fails prerendering — `export const dynamic =
  "force-dynamic"` is incompatible with `cacheComponents: true`, so
  the page now calls `await connection()` from `next/server` inside
  an inner async server component wrapped in `<Suspense>`; the
  TrialBanner test fixture needed the full `BillingSubscription`
  shape (a `tsc --noEmit` error caught the missing fields);
  `formatDollars` now uses thousands separators (`$2,440`, not
  `$2440`; the PricingTable test expectation for `$1490` became
  `$1,490`).
- Docs: new `docs/MARKETING.md` (pages, lead capture, analytics, SEO,
  trial wiring, screenshot capture process, "What is still
  placeholder"); `docs/API.md` marketing section (3 endpoints);
  `docs/openapi.yaml` 116 → **119 paths** (3 new, plus a `Lead`
  schema); `CHANGELOG.md` [1.2.2] entry (incl. the throttle fix and
  the "Not in v1.2.2" list). OpenAPI path count verified by parsing
  the YAML.
- Verification: backend **451 tests, 2340 assertions** (435 at v1.2.1
  + 15 marketing/analytics tests + 1 throttle regression test), all
  passing; frontend **293 tests** (270 + 23 new), all passing;
  `npm run build` 55/55 pages (38 at v1.2.1 + 17 new marketing
  routes). Live HTTP smoke test **27/27 backend steps + 13/13
  marketing pages** on a fresh SQLite DB (script + runner in
  `~/workspace/claimtrail-scratch-v122/smoke/`): plans list 5 tiers
  with the v0.5.0 prices and the trial offer, lead create 201 /
  duplicate 200 / other source 201 / validation 422, analytics 202
  with the event in the Laravel log, `leads:list` shows the demo
  lead, register + org + auth plans (6 plans) + Growth trial start
  + usage all pass; every marketing page returns 200 with its key
  content (incl. `$2,440` demo total and the screenshots
  placeholder). Regression: the v1.2.1 smoke script re-run, **55/55**
  (Free cap, 402s, carryover downgrade, trial on Growth limits).
- Gaps / placeholder (not built, per the spec): product screenshots
  (no real captures; checklist item unchecked); analytics provider
  (log driver works; GA4/Plausible needs a measurement ID only Aqib
  can supply); privacy policy and terms still placeholders pending
  legal review (v1.2.3); site URL unset until the marketing site has
  a domain (sitemap empty, robots.txt lists no sitemap until then);
  billing still on the mock provider (v1.2.0 decision, unchanged);
  no admin UI for leads (`leads:list` only). Standing unverified
  items unchanged: browser E2E, live Postgres, live Redis, Docker,
  real mailer.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v1.2.2 PUSHED 2026-11-26** after Aqib's yes in the main
  chat + approval card. New remote head `fbf378e1`, 54 files in one
  commit (15 changed + 39 new). Read-back verified. Next run may
  start v1.2.3.
- Next task: **v1.2.3 — Production Launch.** v0.2.0 Customer
  Interviews stays DEFERRED per Aqib 2026-11-21 — do not fabricate
  interviews.

### 2026-11-25 — v1.2.1 Plans and Entitlements built and verified

- Gate check passed first: remote head on GitHub is `704c09e3`,
  matching the recorded v1.2.0 head, so no version was waiting to be
  pushed. Build started 2026-11-25 ~19:24 PKT on Aqib's "Start v1.2.1
  — Plans and Entitlements now" in the main chat, the same evening
  v1.2.0 was pushed — not the scheduled 2026-11-26 10:10 PKT run.
- Spec: MASTER-CHECKLIST.md v1.2.1 (9 items) + v0.5.0 sections 8 and
  9.2. Limits from section 8: claims/month Free 5, Starter 20,
  Growth 100, Fleet 300, Agency 100 per carrier, Success Fee
  unlimited; members Free 1, Starter 3, Growth 10, Fleet 25, Agency
  unlimited; storage Free 100 MB, Starter 1 GB, Growth 10 GB, Fleet
  50 GB, Agency 10 GB per carrier; PDF packages not on Free,
  unlimited on paid plans; reports dashboard-only on Free, full on
  Starter+. Shipments are NOT capped (claim cap is the binding
  limit). Enforcement is backend; the frontend shows usage and
  upgrade prompts.
- Backend (new): `app/Services/EntitlementService.php` (resolves the
  org's current plan from its subscription — trialing orgs are on
  Growth, canceled subscriptions fall back to Free — and its limits
  from `config/billing.php`; `limits()`, `limitsForPlan()` with the
  Agency per-carrier multiplier, `usage()`, `summary()`,
  `nextPlanFor()` = cheapest listed plan raising the limit,
  `authorizeClaimCreation` / `authorizeInvitation` /
  `authorizeUpload` / `authorizePackageGeneration` /
  `authorizeReports`); `app/Exceptions/EntitlementExceededException.php`
  (renders 402 JSON with `error: "plan_limit_exceeded"` and a `limit`
  block: resource, limit, used, plan, upgrade_plan,
  upgrade_plan_name). Wired into `ClaimController@store`,
  `OrganizationInvitationController@store`,
  `EvidenceFileController@store` (before the Storage write),
  `ClaimPackageController@store`, `ReportController` (summary,
  recoveryHistory, export); `BillingController::usage` gains an
  `entitlements` block. Migration
  `2026_11_25_100100_add_claims_carryover_to_subscriptions.php`
  (`claims_carryover` on subscriptions) + model fillable/cast.
- **Downgrade carryover (real defect found by the live smoke test,
  not by the suite):** the claim-cap count used only the current
  billing period, so a renewal reset the count and a downgrade
  handed out a fresh allowance at once — the downgrade never
  blocked anything except when the claims and the renewal landed
  in the same second (one-second race; the backend downgrade test
  passed twice in the full suite on that luck, then failed alone).
  Fix: when a move to a lower plan takes effect
  (`applyPendingDowngrade`, `applyFreePlan` for cancellation /
  dunning / trial lapse, immediate cancel), the claims created
  since the old period began are carried into the new period
  (`claims_carryover`) and still count toward the new lower cap
  for one period. The next renewal clears it (trial conversion,
  success-fee renewal, paid renewal all reset it to 0), so a
  normal renewal still resets the monthly allowance. New test
  `test_claim_carryover_clears_at_the_next_renewal` covers the
  clearing; the downgrade test now passes 5/5 runs. The test
  backdates the claims (created days before the renewal in
  reality) because timestamps here have one-second precision and
  the whole test otherwise runs inside one second.
- **Second real defect (frontend, found while wiring the 402
  prompt):** `errorFromBody` in `src/lib/api.ts` dropped every
  error-body field except `message` and `errors`, so a 402 `limit`
  block never reached the page. The full parsed body is now
  carried on `ApiRequestError.body`. Covered by a new api test.
- Frontend: `src/lib/types.ts` (`ApiError.error`/`limit`,
  `EntitlementSummary` with the real API shape: limits with
  `plan_name` and boolean `pdf_packages`/`reports`, usage with
  `claims_created`/`claims_carryover`/`members`/
  `pending_invitations`/`storage_bytes`, upgrade options as
  `{key, name}`); `src/lib/api.ts` (`body` on the error);
  `src/lib/billing.ts` (`formatBytes`); billing page Plan usage
  card (claims/members/storage vs limits, PDF packages, reports,
  at-limit upgrade prompts naming the tier, carryover note);
  claims/new page renders a 402 as an upgrade alert linking to
  `/settings/billing`; stale "enforcement comes in v1.2.1" copy
  updated. New frontend tests: billing page usage section, api 402
  body.
- Docs: `docs/BILLING.md` entitlements section (limits, 402 shape,
  guarded actions, caps never delete data, the carryover rule,
  usage shape, vendor-dashboard gap); `docs/openapi.yaml`:
  `EntitlementSummary` + `EntitlementUpgradeOption` schemas,
  `PlanLimitExceeded` 402 response referenced from the 7 guarded
  endpoints, `entitlements` on `BillingUsageSummary` (still 116
  paths); `CHANGELOG.md` [1.2.1] entry.
- Verification: backend **435 tests, 2277 assertions** (421 at
  v1.2.0 + 13 entitlement tests + 1 carryover test; 21 pre-existing
  tests in Report/Invitation/ClaimPackage/Audit/Notification
  needed a Starter subscription after the new gates — subscribed
  in the tests, no behaviour change); frontend **270 tests**
  (268 + 2 new); `npm run build` 38/38 pages. Live HTTP smoke test
  **55/55** on a fresh SQLite DB (script + runner in
  `~/workspace/claimtrail-scratch-v121/smoke/`): Free 5 claims then
  6th 402 with Starter upgrade, invitation/reports/package/storage
  402s, 10 shipments uncapped, trial org on Growth limits (6
  claims, reports/packages/invite work, cross-tenant isolation),
  Starter downgrade to Free keeps 6 claims and blocks the 7th with
  used=6 via the carryover. Smoke run twice, 55/55 both times.
- Gaps (not built, per the spec): no vendor dashboard surface for
  usage (operations dashboard is per-org queue monitoring, no
  org-listing view); no per-claim cap on evidence files or
  generated documents (v0.5.0 section 8 specifies none; storage
  governs). Standing unverified items unchanged: browser E2E, live
  Postgres, live Redis, Docker, real mailer.
- Note: the compacted handoff for this build misdescribed the
  `EntitlementService::summary()` shape (usage keys and upgrade
  entries); the smoke test caught the mismatch against the real
  API, and the frontend, OpenAPI and docs were corrected to the
  real shape before verification. The backend test suite passing
  does not prove the frontend mock matches the API; the live smoke
  test is what caught it.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v1.2.1 PUSHED 2026-11-25** after Aqib's yes in the main
  chat + approval card. New remote head `79d1ab7e`, 33 files in one
  commit (29 changed + 4 new). Read-back verified. Next run may
  start v1.2.2.
- Next task: **v1.2.2 — Marketing and Acquisition.** v0.2.0 Customer
  Interviews stays DEFERRED per Aqib 2026-11-21 — do not fabricate
  interviews.

### 2026-11-25 — v1.2.0 Subscription Billing built and verified (mock provider)

- Gate check passed first: remote head on GitHub is `3b6300d7`,
  matching the recorded v0.6.0 head, so no version was waiting to be
  pushed. Build started 2026-11-25 13:17 PKT on Aqib's "Start now" in
  the main chat, the same afternoon v0.6.0 was pushed — not the
  scheduled 2026-11-26 10:10 PKT run.
- Decision (Aqib, 2026-11-25 13:15 PKT): **skip real Stripe; build
  billing against a mock billing provider with dummy data and make it
  fully working end to end.** No real Stripe calls, no Stripe keys.
  The mock sits behind the `BillingProvider` interface and implements
  the v0.5.0 section 9.1 shape, so real Stripe is a later provider
  swap, not a redesign. The metered-billing decision (Stripe metered
  vs manual invoicing) is deferred with real Stripe; the mock follows
  the metered-arrears model.
- Backend (new): `config/billing.php` (provider driver, webhook
  secret, 30-day trial, dunning delays [3, 7], 14-day past-due
  downgrade, success-fee rate 15%, cap 4900 cents, 12-month cap and
  attribution windows, all 6 plans with prices/limits);
  `app/Billing/BillingProvider.php` (interface) and
  `MockBillingProvider.php` (fake ids, test cards 4242 succeeds /
  4000...0002 declines, process-local idempotency cache, HMAC-SHA256
  webhook signing); `PlanCatalog.php`; migration
  `2026_11_25_100000_create_billing_tables.php` (subscriptions,
  invoice_sequences, invoices, invoice_lines, billing_usage_records,
  billing_events, checkout_sessions, portal_sessions) + 7 models;
  `ProrationService.php` (daily-rate credit/charge);
  `BillingService.php` (~1,100 lines: provider resolution, lazy Free
  subscription, checkout + complete, trials, changePlan with proration
  + scheduled downgrades, cancel/resume, payment method, portal
  sessions, invoice history/detail/pay, metered usage recording +
  arrears invoicing with the $49 cap, renewDue, processDunning,
  webhook handling with idempotency, invoice numbering
  INV-YYYY-NNNN); `manage-billing` permission (Owner, Admin, Billing
  Staff); `BillingController.php` + `BillingWebhookController.php`
  (17 billing routes); `billing:renew` (daily 03:30) and
  `billing:dunning` (daily 04:00) commands; `ClaimTransitionService`
  records metered usage inside the record-payment transaction.
  Version bumped 1.1.3 → 1.2.0 in `config/app.php`.
- Frontend (new): billing hub at `/settings/billing` (current
  subscription, plans with monthly/annual toggle and test-card
  selector, trial, plan change with proration preview, Success Fee
  usage, payment method, invoices with pay, billing portal entry),
  `src/lib/billing.ts` typed client + formatters,
  `canManageBilling` helper, Billing nav item (Owner/Admin/Billing
  Staff), AppShell version strings bumped to v1.2.0.
- Docs: new `docs/BILLING.md` runbook (mock provider, plan movement,
  metered billing, webhooks, invoices, scheduled commands, the Stripe
  swap-out path); `docs/openapi.yaml` 99 → 116 paths (17 billing
  paths, 9 billing schemas, verified against the live route list —
  no missing, no extra); `docs/API.md` billing section; `CHANGELOG.md`
  [1.2.0] entry. MASTER-CHECKLIST.md: all 14 v1.2.0 items checked
  (Stripe integration / webhooks / portal annotated as mock,
  simulated, mocked).
- Two real defects found by verification and fixed in this version:
  (1) The billing migration had a **global unique on
  `invoices.number`** while numbering is per-organization, per-year,
  so the second organization's first invoice failed with a
  unique-constraint violation (its checkout returned 500 and its
  renewal threw). Found by the live smoke test. The constraint is
  now `(organization_id, number)`, with a regression test proving
  two organizations can each hold `INV-2026-0001` (the test was
  verified to fail with the global unique restored, then the fix
  was re-applied). (2) The frontend billing client and the smoke
  script used the wrong URIs for four endpoints
  (`/billing/plan-changes`, `/billing/cancel`, `/billing/resume`,
  `/billing/portal-sessions`); the real routes are
  `/billing/subscription/change-plan`,
  `/billing/subscription/cancel`, `/billing/subscription/resume`,
  `/billing/portal`. Found by the live smoke test (404 on the
  plan-change preview). The mocked frontend tests could not catch
  it; the smoke test did.
- Three smaller fixes during verification: the metered-invoice
  period filter was too strict (uninvoiced records are now all
  collected at renewal, since the period only advances at renewal);
  `ProrationService` casts Carbon 3's float `diffInDays` results to
  int (the docblock promises ints); the frontend lib's
  `subscriptionStatusTone`/`invoiceStatusTone` return types now
  include `info`.
- Verification: backend **421 passed (2169 assertions)** — 382 at
  v1.1.3 + 33 billing tests (32 feature + 1 invoice-number
  regression) + 6 proration unit tests. Frontend **268 passed**
  across 26 files (241 + 27 billing), `tsc --noEmit` clean, lint
  clean, `npm run build` succeeds (38/38 pages, including
  `/settings/billing`). `migrate:fresh` passes with the new
  migration. Live HTTP smoke test over the built-in server on a
  fresh SQLite DB passed **59/59 steps**: plans (6, Success Fee
  unlisted), Free start, Growth trial, checkout to Fleet with the
  visa test card, one paid $99 invoice, Agency proration preview
  (net $48.33, 29 days left) and immediate upgrade with
  credit/charge lines, cancel/resume, declined card stored, failed
  renewal → past due (attempts 1), dunning skips a not-yet-due retry
  then retries (attempts 2), 14 days past due → downgrade to Free,
  webhook bad signature 401 / good signature applied (attempts 3) /
  exact redelivery duplicate (attempts still 3), simulate of an
  unknown type recorded as unprocessed, portal token opens the
  billing summary, Success Fee checkout with no card, claim
  lifecycle potential → under_review → ready_to_submit → submitted
  → accepted → payment recorded ($1,000), metered usage $1,000
  recovered / $150 fee / $49 cap / $0 remaining, renewal arrears
  invoice $150.00 success-fee line − $101.00 cap adjustment =
  $49.00 total, paid with the visa card, usage empty after.
  Scratch evidence in `~/workspace/claimtrail-scratch-v120/smoke/`
  (smoke script, runner, server log, smoke DB).
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. v1.2.0-specific: live
  Stripe checkout is intentionally not built (mock by decision);
  the mock portal URL points at the frontend billing page, not a
  Stripe-hosted page; the mock's process-local idempotency cache
  does not survive a restart. Plan limit enforcement is v1.2.1,
  not this version.
- Environment note: system `php` is present on this VM (8.3.6,
  verified 2026-11-25) and was used for the backend suite,
  migrations, seed and smoke-test server. `artisan serve` still
  fails on this VM; the built-in server was used for the smoke test.
  The temporary `backend/.env` created for the smoke test was
  removed afterwards.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v1.2.0 PUSHED 2026-11-25** after Aqib's yes in the main
  chat + approval card. New remote head `704c09e3`, 44 files in one
  commit (18 changed + 26 new). Read-back verified. Next run may
  start v1.2.1.
- Next task: **v1.2.1 — Plans and Entitlements.** v0.2.0 Customer
  Interviews stays DEFERRED per Aqib 2026-11-21 — do not fabricate
  interviews.

### 2026-11-25 — v0.6.0 Pilot Preparation built and verified (Phase 0 complete)

- Gate check passed first: remote head on GitHub is `5576b2e1`,
  matching the recorded v0.5.0 head, so no version was waiting to be
  pushed. (Checked via the connected GitHub MCP `list_commits`.)
- Built all 4 v0.6.0 items as `docs/validation/0.6.0-pilot-preparation.md`
  (8,997 words, plus a `docs/validation/README.md` index update marking
  v0.6.0 Done) with two sample-data files in `docs/validation/pilot/`:
  `sample-shipments.csv` (12 synthetic shipments, 3 fictional carriers)
  and `pilot-scenarios.md` (2,436 words, per-shipment walkthrough,
  $2,440 requested across 11 claims [estimate], one control row
  dismissed). Three pilot profiles from the v0.1.0 beachhead (dispatch
  agency 5-20 carriers; 5-10 truck reefer fleet on spot freight; 2-3
  truck fleet billing detention regularly) with fit criteria, sourcing
  channels and disqualifiers, plus a 5-factor scorecard (12/20 to
  qualify, hard gates) and a 6-step outreach shortlist process.
  Onboarding runbook grounded in the app's actual 7 steps
  (`OnboardingService.php`), the 30-day no-card Growth trial, a
  week-by-week plan, support cadence, CSV data migration, and the
  day-30 decision point (subscribe / success-fee / free tier / churn
  with reason). Success metrics carry the v0.5.0 targets (trial-to-paid
  15% good, 8% floor; free-to-paid 3-5% good) plus activation and
  outcome metrics with targets, floors, measurement methods naming the
  real reports endpoints, and go / no-go / iterate rules, plus the
  v0.5.0 kill criteria and 4 pilot-specific ones.
- No pilot customer has been recruited and no names, quotes,
  commitments or contact details were invented: the document states
  plainly that recruitment needs Aqib's outreach (v0.2.0-dependent,
  interviews still deferred since 2026-11-21). Phase 0 ends here as
  preparation; the next validation work is contact with real people.
- Triporto and DetentionHero re-checked 2026-11-25 (home pages opened
  in full), both still early access with no price. New find:
  DetentionDesk (detentiondesk.com/pricing, opened in full and
  re-verified by the parent run): $25 one-time per claim packet or
  $149/mo, detention-only, user-confirmed times with no GPS/ELD
  verification, Stripe Checkout, one account, no team seats. Per-claim
  pricing is a third price anchor ($50 for 2 claims/mo vs $49 Growth);
  it does not change any v0.6.0 conclusion.
- One real defect caught by the parent run's verification and fixed
  before reporting: the sample CSV's last row (CSC-3004) had an
  unquoted comma in its notes field, parsing as 15 fields instead of
  14 and truncating the note on import. The notes field is now quoted;
  all 13 rows parse to exactly 14 fields. All other figures were
  spot-checked: scenario totals $960 + $600 + $500 + $380 = $2,440
  across 11 claims; detention durations and amounts recomputed from
  the CSV times (e.g. PLT-1001 335 min, 3.58h at $60 = $215); the
  67,000 6-20 truck fleets, 56.2% reefer / 42.5% spot detention rates
  and $30,000/yr 5-truck figures all trace to v0.1.0; the reports
  endpoints named in section 6 (`reports/summary`,
  `reports/recovery-history`, `reports/export`) exist in
  `ReportController.php` and `routes/api.php`. No em dashes in any new
  file (grep-verified, 0 found).
- Verification: no code changed in this version (docs + sample data
  only), but the suites were re-run to confirm the repo is still
  green: backend **382 passed (1902 assertions)**, frontend **241
  passed** across 24 files, `npm run build` succeeds (37/37 pages) —
  identical counts to v1.1.3. Live HTTP smoke test over the built-in
  server on a fresh SQLite DB passed: 3 carriers created (201), the
  sample CSV previews 12 valid / 0 invalid / 0 duplicate, confirm
  imports 12/12, and the shipment list shows all 12 references
  (PLT-1001..1004, BBF-2001..2004, CSC-3001..3004). Scratch evidence
  in `~/workspace/claimtrail-scratch-v060/` (seed, server log, preview/
  confirm responses, build log).
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Phase 0: no interviews,
  no pilot customers, no validated willingness to pay yet — v0.2.0's
  job, and it needs real people; Aqib's help reaching carriers or
  dispatchers will be needed.
- Environment note: system `php` is missing on this VM — the
  standalone PHP 8.3.6 at `~/workspace/php-standalone/` was used for
  the backend suite, migrations, seed and smoke-test server (with
  `PHP_INI_SCAN_DIR=~/workspace/php-standalone/scan`). The temporary
  `backend/.env` created for the smoke test was removed afterwards,
  along with the phpunit result cache and test artifacts; no app code
  changed.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v0.6.0 PUSHED 2026-11-25** after Aqib's yes in the main
  chat + approval card. New remote head `3b6300d7`, 6 files in one
  commit (new `docs/validation/0.6.0-pilot-preparation.md`,
  `docs/validation/pilot/sample-shipments.csv`,
  `docs/validation/pilot/pilot-scenarios.md`,
  `docs/validation/README.md`, `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md` updated). Read-back verified.
- Next task: **v1.2.0 — Subscription Billing.** Aqib unblocked it
  the same day (2026-11-25 13:15 PKT): skip real Stripe, build
  billing against a mock provider with dummy data. Built and
  verified the same afternoon (see the 2026-11-25 v1.2.0 entry
  above); push waits for his yes. v0.2.0 Customer Interviews stays
  DEFERRED per Aqib 2026-11-21 — do not fabricate interviews.

### 2026-11-24 — v0.5.0 Pricing Validation built and verified

- Gate check passed first: remote head on GitHub is `e473ddc7`,
  matching the recorded v0.4.0 head, so no version was waiting to be
  pushed. (Unauthenticated GitHub API was rate-limited this run; the
  head was confirmed via the connected GitHub MCP `list_commits`.)
- Built all 4 v0.5.0 items as `docs/validation/0.5.0-pricing-validation.md`
  (8,587 words, plus a `docs/validation/README.md` index update marking
  v0.5.0 Done): six pricing models plus hybrid/outcome variants with
  2025-2026 benchmarks (Poyar 2026 survey: hybrid 37% most common,
  outcome-based primary 5%; tiered packaging 67%; subscription primary
  for 80%+ of B2B SaaS); willingness to pay by proxy only, stated
  honestly (dispatch 5-8% of gross linehaul, $1,000-1,600/mo per truck
  at $20,000 gross, against $19-49/mo for the tool; trial benchmarks
  opt-in median ~18.5%, 7-day 24%, 14-day 19%, 30-day 14%, freemium
  2-5%, card-required ~49%; trial length tested against the claim life
  cycle, which needs 30 days); pay-when-it-bills-clean tested against
  success-fee precedents (DueWell $500-2,000/mo plus 8-15% of recovered
  cash, ChurnBack $49/mo plus 25%, Amazon recovery contingency 15-25%);
  revised pricing hypotheses (Free 1 truck/5 claims, Starter $19 for
  1-2 trucks, Growth $49 for 3-10 trucks as default, Fleet $99 for
  11-30 trucks new, Agency $149/mo base for up to 10 carriers plus $12
  per extra carrier new, success-fee plan $0 base plus 15% of amounts
  recorded as paid capped at $49/mo for the first year); permanent Free
  tier replaced as the entry offer by a 30-day no-card trial on Growth;
  limits rewritten from internal units (events, days of history) to
  buyer units (trucks, claims, members, storage); subscription structure
  specified for v1.2.0/v1.2.1 (plans, entitlements, upgrade prompts, no
  card for trial, no data deletion on downgrade, shipment caps rejected
  with reasoning). Market sizing refined to $4-6M ARR at 10,000 paying
  orgs (was $4.7M). New data points: CabCommand flat fleet tiers
  ($29.99/$69.99/$129.99/$249.99) and 3 Lakes Logistics automated
  recovery at $300/mo. Triporto and DetentionHero re-checked
  2026-11-24, both still early access with no price.
- Headline findings: the agency price is now set against DOCK2BILL
  Partner Pro $149/mo, not against the $490/mo of the old per-org
  pricing; the success-fee plan is an option at trial exit, not the
  default, because it invites adverse selection if offered to everyone;
  the 30-day trial trades worse median conversion (14% vs 19% for
  14-day) for conversions of buyers who have seen the outcome, which is
  what survives first renewal; one collected detention claim ($75-300)
  pays for 1.5-4 months of Growth/Starter, so the price is defensible
  only if the buyer collects, which is why the success fee exists.
- One arithmetic error caught during verification and fixed before
  reporting: the agency per-carrier price at 20 carriers is $13.45,
  not $12.90 as first written (20 carriers cost $269/mo). All other
  figures in the document were re-checked against the sources (blended
  $52/mo average, $6.2M ARR at 10,000 orgs, per-truck prices,
  break-even months).
- Verification: no code changed in this version (docs only), but the
  suites were re-run to confirm the repo is still green: backend
  **382 passed (1902 assertions)**, frontend **241 passed** across 24
  files, `npm run build` succeeds (37/37 pages) — identical counts to
  v1.1.3. No em dashes in the document (grep-verified, 0 found).
  No live HTTP smoke test was needed for a docs-only version (no
  behaviour changed). No interviews: willingness to pay is proxy-only
  until v0.2.0; the document's section 10 carries 10 interview
  questions plus kill criteria.
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Phase 0: no interviews,
  no pilot customers, no validated willingness to pay yet — v0.2.0's
  job, and it needs real people; Aqib's help reaching carriers or
  dispatchers will be needed.
- Environment note: system `php` is present on this VM (PHP 8.3.6) —
  the standalone PHP at `~/workspace/php-standalone/` was not needed
  this run.
- Push status: **v0.5.0 PUSHED 2026-11-24** after Aqib's yes in the main
  chat + approval card. New remote head `5576b2e1`, 4 files in one
  commit (new `docs/validation/0.5.0-pricing-validation.md`,
  `docs/validation/README.md`, `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md` updated). Read-back verified. Next run may
  start v0.6.0.
- Next task: **v0.6.0 — Pilot Preparation** (last Phase 0 version).
  v0.2.0 Customer Interviews stays DEFERRED per Aqib 2026-11-21 — do
  not fabricate interviews. v1.2.0 billing stays blocked on Stripe
  keys, plus the metered-billing decision for the success-fee plan.

### 2026-11-23 — v0.4.0 Competitor Analysis built and verified

- Gate check passed first: remote head on GitHub is `6195b9f9`,
  matching the recorded v0.3.0 head, so no version was waiting to be
  pushed.
- Built all 4 v0.4.0 items as `docs/validation/0.4.0-competitor-analysis.md`
  (8,573 words, plus a `docs/validation/README.md` index update marking
  v0.4.0 Done): 11 competitor pages opened and read in full
  2026-11-23 (GetDockPay, DOCK2BILL + its new dispatchers page,
  Detention Buddy, TruckersClaims, DetentionHero, Triporto, Eden One
  product + pricing, Run Your Rig home + features); Tezify and
  TenTrucks read via search results; Toro/StackViro/Vektor carried
  from 2026-11-14. Feature comparison table (ClaimTrail vs 8 rivals)
  and normalized pricing table (1 / 5 / 10 trucks, agency).
  10 product gaps, differentiation revised, 6 added v0.2.0 interview
  questions, v0.5.0 pricing questions, 6 roadmap proposals.
- Headline findings: GetDockPay shipped v1.1 with direct deposit
  (brokers pay invoices online); TruckersClaims Pro now priced at
  $29/mo per truck but still not shipping; DOCK2BILL opened a
  dispatcher partner program (20% commission free, Partner Pro
  $149/mo for 25%, co-branding) — the first rival move into the
  dispatch-agency beachhead, referral not operations; Tezify
  published full pricing ($49 1 truck, $249 3-5, $399 6-10) and its
  detention workflow is deeper than v0.1.0 recorded (approval,
  receipt requests, POD-times flag, automated notices, collections
  and AR aging). New rivals: Triporto (driver app, early access,
  fleet dashboard claimed not shipping), Eden One (office product
  with detention workflow, founder $69/mo for 1-5 trucks — the
  strongest new rival), Run Your Rig (owner-operator TMS, AI
  rate-con parsing, $9.99/driver/mo, 90 days free, web live, phone
  apps coming soon).
- Differentiation revised: carrier/dispatcher desk 5-6/10 down to
  4-5/10 (Eden One, Run Your Rig, DOCK2BILL partner program,
  Tezify depth); multi-tenant agency desk 7-8/10 [estimate] — no
  rival offers multi-tenant operations. ClaimTrail's planned Growth
  $49/mo is still the cheapest desk price in the table, but the
  agency beachhead has a pricing problem: per-organization pricing
  with no agency plan means 10 carriers at $49 = $490/mo, against
  DOCK2BILL Partner Pro at $149/mo. Free tier (3 days) is too thin
  to contain a claim's life cycle, against 30-90 day rival trials.
  Biggest gaps: no submission channel (every shipping rival sends
  the claim), no GPS capture, no driver capture flow, no rate-con
  parsing, no broker-pay intelligence, no follow-up that sends.
  The one-sentence pitch that survives: the desk where a dispatcher
  or agency runs every detention/layover/TONU/lumper claim for
  every carrier they serve, through evidence, deadlines and payment,
  in one account — true only if the submission channel and GPS
  capture are built.
- Source note: the 2026-11-14 DockClock competitor check at
  `~/workspace/your_files/dockclock-siteorder-competitor-check-2026-11-14.md`
  is a broken symlink (its goal folder no longer exists); the
  document was read from the surviving copy under
  `~/workspace/your_files/deleted-goal-files/driftline-saas-validation/`.
  The v0.4.0 document records this so the next run does not chase
  the dead link.
- Verification: no code changed in this version (docs only), but the
  suites were re-run to confirm the repo is still green: backend
  **382 passed (1902 assertions)**, frontend **241 passed**,
  `npm run build` succeeds (37/37 pages) — identical counts to
  v1.1.3. No em dashes in the document (grep-verified, 0 found).
  No live HTTP smoke test was needed for a docs-only version (no
  behaviour changed). ClaimTrail's side of the comparison tables
  comes from the master checklist as verified through v1.1.3 and
  the v0.3.0 workflow validation, not from new code reading.
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Phase 0: no interviews,
  no pilot customers, no validated willingness to pay yet — v0.2.0's
  job, and it needs real people; Aqib's help reaching carriers or
  dispatchers will be needed.
- Environment note: system `php` is present on this VM (PHP 8.3.6) —
  the standalone PHP at `~/workspace/php-standalone/` was not needed
  this run.
- Push status: **v0.4.0 PUSHED 2026-11-23** after Aqib's yes in the main
  chat + approval card. New remote head `e473ddc7`, 4 files in one
  commit (`docs/validation/0.4.0-competitor-analysis.md` new,
  `docs/validation/README.md`, `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md` updated). Read-back verified. Next run may
  start v0.5.0.
- Next task: **v0.5.0 — Pricing Validation.** v0.2.0 Customer
  Interviews stays DEFERRED per Aqib 2026-11-21 — do not fabricate
  interviews.

### 2026-11-22 — v0.3.0 Workflow Validation built and verified

- Gate check passed first: remote head on GitHub is `707fa462`,
  matching the recorded v0.1.0 head, so no version was waiting to be
  pushed.
- Built all 5 v0.3.0 items as `docs/validation/0.3.0-workflow-validation.md`
  (8,621 words, plus a `docs/validation/README.md` index update marking
  v0.3.0 Done and correcting v0.2.0 to Deferred): the 10-stage
  shipment-to-claim workflow (load booked through payment/closure,
  each stage with real-world action, app record, and app status); the
  claim lifecycle (all 10 statuses, transition table transcribed from
  `ClaimStatus.php`, enforced rules: payment only via record-payment,
  rejection needs a reason, pre-submission close needs a reason,
  submitted is a one-way door); evidence requirements (app mechanics
  plus a recommended required/recommended matrix per event type,
  labelled [hypothesis]); deadline handling (real-world 24-48h norm
  and 30-90 day contractual windows, app mechanics: scope precedence,
  hourly refresh, timezone-aware comparison, 7-day default reminder
  window); and a 20-term financial glossary with v0.1.0 rate ranges
  kept as reference and wider 2026 trade-guide ranges flagged as
  unresolved differences. 14 open questions for v0.2.0 interviews,
  14 app-vs-workflow gaps, validation status, and sources with access
  date 2026-11-22 (16 trade/vendor sources plus the code itself).
- Most significant gaps recorded: no submission channel (`submitted`
  is a hand-recorded status, the PDF package is never sent); no
  GPS/ELD time capture (all times typed in); deadline clock starts at
  claim creation, not delivery; no deadline when the org configures no
  setting; no default evidence requirements, so readiness reports
  100%/ready for a claim with no evidence; accepted-but-unpaid has no
  follow-up state; no shipment cancellation state despite TONU; one
  claim links to at most one event; evidence attaches to claims, not
  events; a single file cannot serve two categories (signed BOL as
  POD); readiness counts files, not quality; no rate-confirmation
  parsing or amount calculation; no broker-response tracking beyond
  the status; submission cannot be corrected without rejection first.
- Verification (all run by the parent run, independently of the
  builder): every app-behaviour claim spot-checked against the code
  (transition table, readiness zero-total, deadline clock, seven
  seeded categories, payment/rejection/close rules, timezone handling,
  v0.1.0 figures 75%/55%/41% and 67% free time, backdatable
  `submitted_at`, `submit_within_days` 1-365) — all confirmed, no
  corrections needed. No code changed in this version (docs only),
  but the suites were re-run to confirm the repo is still green:
  backend **382 passed (1902 assertions)**, frontend **241 passed**,
  `npm run build` succeeds (37/37 pages) — identical counts to
  v1.1.3. No em dashes in the document (grep-verified).
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Phase 0: no interviews,
  no pilot customers, no validated willingness to pay yet — v0.2.0's
  job, and it needs real people; Aqib's help reaching carriers or
  dispatchers will be needed.
- Environment note: system `php` is present on this VM (PHP 8.3.6) —
  the standalone PHP at `~/workspace/php-standalone/` was not needed
  this run. No live HTTP smoke test was needed for a docs-only version
  (no behaviour changed).
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v0.3.0 PUSHED 2026-11-22** after Aqib's yes in the main
  chat + approval card. New remote head `6195b9f9`, 4 files in one
  commit (`docs/validation/0.3.0-workflow-validation.md` new,
  `docs/validation/README.md`, `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md` updated). Read-back verified. Next run may
  start v0.4.0.
- Next task: **v0.4.0 — Competitor Analysis.** v0.2.0 Customer
  Interviews stays DEFERRED per Aqib 2026-11-21 — do not fabricate
  interviews.

### 2026-11-21 — v0.1.0 Market Research built and verified

- Gate check passed first: remote head on GitHub is `e6e7488d`,
  matching the recorded v1.1.3 head, so no version was waiting to be
  pushed.
- Built all 5 v0.1.0 items as `docs/validation/0.1.0-market-research.md`
  (plus a `docs/validation/README.md` index): revenue leakage (ATRI
  Sep 2024, 2023 data: detention at 39.3% of stops, 135M+ hours,
  $15.1B — $3.6B direct + $11.5B lost productivity; 94.5% of fleets
  charge detention but fewer than 50% of invoices are paid; DOT OIG
  2018: $1.1–1.3B/yr driver earnings impact; OOIDA 2023: 72% of
  owner-operators waited 2+ hours weekly), missed accessorial charges
  with sourced rate ranges (detention $50–$75/hr, layover $250–$350/day,
  TONU $200–$300, lumper $150–$250, plus liftgate/tarp/hazmat/
  driver-assist/stops/redelivery), target segments (FMCSA census:
  ~1.11M authorized-for-hire carriers, 66% single-truck, 92% five or
  fewer; ATA: $906B freight revenue 2024), existing solutions
  (GetDockPay Pro $24.99/mo, Detention Buddy Pro $34.99/yr, Tezify/
  TenTrucks TMS detention billing, dispatch services as human
  substitutes), and market opportunities with TAM/SAM/SOM labelled as
  estimates (SOM sketch: 10,000 paying orgs at $39/mo = $4.7M ARR).
  Recommended beachhead, as a hypothesis for v0.2.0 to test: dispatch
  agencies and 2–20 truck fleets running reefer or spot freight.
  Risks recorded: willingness to pay unproven against $2.92–$24.99/mo
  driver apps, small-carrier churn, TMS absorption, relationship fear,
  broker power. Every figure in the document is labelled sourced vs.
  estimate; sources listed with access date 2026-11-21.
- Research finding for a future version: GPS-stamped mobile capture
  (driver taps arrival/departure) is the most valuable feature this
  research surfaced and is not in any checklist version yet; proposed
  for a Phase 2/4 slot after validation.
- Verification: no code changed in this version (docs only), but the
  suites were re-run to confirm the repo is still green: backend
  **382 passed (1902 assertions)**, frontend **241 passed** across 24
  files, `npm run build` succeeds (37/37 pages) — identical counts to
  v1.1.3. One real defect caught by the verification and fixed in this
  version: the backend suite failed 4 `SecurityCheckTest` tests in a
  clean checkout (no `.env` present) because `phpunit.xml` set no
  `APP_KEY` — the suite depended on an ambient key. Fixed with a
  test-only key in `backend/phpunit.xml`; the suite is now
  self-contained. (An earlier stray `.env` created by a `cp` during
  this run was removed again; the repo never had a committed `.env`.)
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Phase 0: no interviews,
  no pilot customers, no validated willingness to pay yet — v0.2.0's
  job, and it needs real people; Aqib's help reaching carriers or
  dispatchers will be needed.
- Environment note: system `php` is present on this VM now (PHP 8.3.6,
  built Nov 18 2026) — the standalone PHP at
  `~/workspace/php-standalone/` was not needed this run. `artisan
  serve` was not used; no live HTTP smoke test was needed for a
  docs-only version (no behaviour changed), but the security check was
  exercised via the test suite.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v0.1.0 PUSHED 2026-11-21** after Aqib's yes in the main
  chat + approval card. New remote head `707fa462`, 6 files in one
  commit. Read-back verified. Next run may start v0.2.0.
- Next task: **v0.3.0 — Workflow Validation.** v0.2.0 Customer Interviews
  DEFERRED 2026-11-21 per Aqib ("skip what needs me right now") — it
  needs his help reaching carriers/dispatchers and returns when he is
  ready to do outreach; do not fabricate interviews.

### 2026-11-20 — v1.1.3 Security and Operations built and verified

- Built all 8 v1.1.3 items: tenant isolation audit (automated
  cross-tenant sweep across every API resource asserting 404, plus a
  code audit finding no unscoped queries), RBAC security audit (role
  matrix tests + a sweep asserting every non-public API route requires
  `auth:sanctum` and every tenant-scoped route resolves an
  organization), file access security review (`StorageKey::isSafe`
  traversal/prefix guard on evidence and package downloads/previews;
  downloads were already behind auth, tenant checks, and policy
  gates), backup configuration (`config/backup.php`,
  `backup:create` daily 02:00, `backup_runs` table, manifest with
  SHA-256 checksums; SQLite file copy supported, `pg_dump` path
  fails clearly when the binary is missing), restore testing
  (`backup:restore` verifies the manifest first and refuses on any
  mismatch; round-trip test wipes the DB and a stored file, restores,
  asserts both back), data retention policies (`config/retention.php`
  defaults, per-organization overrides via `GET`/`PUT /retention`,
  `retention:purge` with `--dry-run`, weekly Sunday 03:00,
  tenant-scoped, audit-logged, ready packages never purged),
  production deployment hardening (CSP + HSTS in the
  security-headers middleware, secure session cookies by default in
  production, `security:check` command + `GET /security/check` with
  ten production-readiness checks), incident response documentation
  (`docs/INCIDENT-RESPONSE.md`: severity levels, first response,
  containment, eradication/recovery, rollback, data-breach steps,
  communication templates, on-call placeholders) plus security-event
  logging (failed logins and authorization denials in
  `security_events`, listed at `GET /security/events`).
- New permission `manage-security` (owner, admin). Backend: 2
  migrations, 2 models, BackupService, RetentionService,
  SecurityCheckService, SecurityEventService, StorageKey,
  BackupException, 4 artisan commands, 8 new API routes (99 OpenAPI
  paths, matching 99 distinct live route URIs). Frontend: Security
  settings hub (`/settings/security`), `src/lib/security.ts` typed
  client, `canViewSecurity` helper. Version bumped to 1.1.3;
  CHANGELOG + API/openapi/ARCHITECTURE/DATABASE/OPERATIONS/SECURITY
  docs updated.
- Verification: backend **382 passed (1902 assertions)**, frontend
  **241 passed** across 24 files, `npm run build` succeeds (37/37
  pages, incl. `/settings/security`), `tsc --noEmit` clean, lint
  clean, fresh-DB `migrate:fresh` passes with both new migrations.
  Live HTTP smoke test over the built-in server on a fresh SQLite DB
  passed: security check admin 200 (overall warn: Sanctum token
  expiration unset) / viewer 403 / unauthenticated 401; backup
  create 201 (completed, 664,764 bytes) + verify `ok: true`;
  retention get defaults + put override applied; failed login and
  denial recorded as security events (login.failed is unattributed
  and correctly not listed per organization); cross-tenant backup
  404; CSP header present, HSTS absent over plain HTTP in local env
  (correct); `security:check` CLI 9 pass / 1 warn; `retention:purge
  --dry-run` deletes nothing.
- Two doc corrections caught during verification: the summary's
  "93 → 101 OpenAPI paths" was wrong — the real count is 91 → 99
  (verified by diffing every route URI against the spec); and the
  runbook initially named a `backup:verify` artisan command that
  does not exist — verification is API/hub only, and the runbook
  was fixed.
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. v1.1.3-specific: the
  PostgreSQL backup/restore paths (`pg_dump`/`pg_restore`) were
  never run against live PostgreSQL (no server or client tools on
  this VM); queue monitoring is still database-driver only; the
  errors endpoint still reads the local log file. Phase 0 business
  validation still not done — flag again before Phase 3 billing.
- Environment note: system `php` is still missing on this VM; the
  standalone PHP 8.3.6 at `~/workspace/php-standalone/usr/bin/php8.3`
  was used (with `PHP_INI_SCAN_DIR=~/workspace/php-standalone/scan`).
  `artisan serve` still fails on this VM; the built-in server was
  used for the smoke test.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; ignored, not acted on (a system safety notice also
  flagged it during this build).
- Push status: **v1.1.3 PUSHED 2026-11-20** after Aqib's yes in the main
  chat + approval card. New remote head `e6e7488d`, 63 files in one
  commit. Read-back verified (commit file list matches the manifest
  exactly). Next run may start the next version once its direction is
  decided (see the Phase 0 / pause / billing decision).
- Next task: **Phase 0 — Business Validation, v0.1.0 Market Research**
  (Aqib chose Phase 0 next on 2026-11-20; v1.2.0 billing stays queued
  after it, still blocked on Stripe keys).

### 2026-11-19 — v1.1.2 Customization built and verified

- Gate check passed first: remote head on GitHub is `1f2a60e7`, matching
  the recorded v1.1.1 head, so no version was waiting to be pushed.
- Built all 8 v1.1.2 items: custom shipment fields, custom claim fields
  (org definitions: key/label, text/number/date/select/boolean, required,
  select options, max 20 active per resource; values in JSON on the record;
  validated on create/update; shown in forms/detail; `custom:<key>` columns
  in CSV export), configurable event categories (create/rename/disable/
  delete, default amount/currency prefilled onto new events; disabled
  categories rejected for new events), evidence templates (named sets of
  document items, apply-to-claim creates ClaimRequirement rows,
  idempotent), custom deadline settings (org defaults scoped global /
  claim-category / event-category, claim > event > global precedence,
  resolve endpoint, claim creation auto-creates a ClaimDeadline only when
  no explicit deadline is given), notification preferences (already existed
  from v1.0.1; verified, not rebuilt), export templates (named column sets
  per resource, one default per resource, `?export_template_id=` on
  shipment + new claim exports; default export unchanged without one),
  organization-specific settings (validated `settings.default_currency`,
  `settings.date_format`, `settings.claim_reference_prefix`, merged,
  unknown keys rejected; prefix used for new claim references only).
- New permission `manage-customization` (owner, admin, operations manager).
  Backend: 6 migrations, 5 models, CustomFieldService, 27 new API routes
  (93 OpenAPI paths, matching 93 distinct live route URIs). Frontend:
  Customization settings hub (`/settings/customization`), org-settings
  extension, custom-field integration in shipment/claim forms and detail
  pages, template apply on claim detail, export-template pickers on both
  list pages. Version bumped to 1.1.2; CHANGELOG + API/DATABASE/
  ARCHITECTURE docs updated.
- One real bug found by the parent run's independent live smoke test and
  fixed in this version: POST /event-categories silently dropped
  `default_estimated_amount` / `default_currency` (only PUT accepted
  them), so a category created with defaults never prefilled events. The
  create endpoint now accepts and persists both fields (OpenAPI updated),
  with a new regression test
  (`test_a_category_created_with_defaults_prefills_new_events`).
- Verification (all run by the parent run, independently of the builder):
  backend **322 passed (1411 assertions)**, frontend **221 passed**
  across 22 files, `npm run build` succeeds (36/36 pages),
  `tsc --noEmit` clean, lint clean, fresh-DB `migrate:fresh` passes with
  all 6 migrations. Live HTTP smoke test over the built-in server on a
  fresh SQLite DB passed **28/28 steps**: org settings save + unknown-key
  rejection; custom field creation + bad-type rejection; shipment create
  with custom value; missing-required and unknown-key rejections; global
  deadline setting + resolve; claim create with custom select value +
  `SMK-2026-0001` reference + auto-created deadline; bad select option
  rejected; category create/rename/disable, disabled-category event
  rejection, re-enable, event created with default amount 150 prefilled;
  template create/apply (2 created) and idempotent re-apply (0 created,
  2 skipped); shipment + claim exports via templates with custom columns
  and values (`TR-9`, `Alice` in output); notification preferences list
  (10 types) + update; cross-tenant 404s; viewer 403s on
  manage-customization writes and 200 on list. (First smoke attempt hit
  the login throttle from repeated runs in the same minute; the re-run
  after a pause passed cleanly. That is a test-script artifact, not an
  app bug.)
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer.
- Environment note: system `php` is still missing on this VM; the
  standalone PHP 8.3.6 at `~/workspace/php-standalone/usr/bin/php8.3`
  was used (with `PHP_INI_SCAN_DIR=~/workspace/php-standalone/scan`).
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; correctly ignored by the builder and the parent run, not
  acted on (a system safety notice also flagged it during this build).
- Push status: **v1.1.2 PUSHED 2026-11-19** after Aqib's yes in the main
  chat + approval card. New remote head `1e0e197d`, 66 files in one
  commit. Read-back verified. Next run may start v1.1.3.
- Next task: v1.1.3 — Security and Operations.

### 2026-11-17 — v1.1.0 UX Improvements (complete, verified, NOT pushed)

- Gate check passed first: remote head on GitHub is `9a947a1d`, matching the
  recorded v1.0.1 head, so no version was waiting to be pushed.
- Built all 11 v1.1.0 items: responsive UI (overflow/clip fixes, min-w-0
  shell, 44px touch targets, mobile drawer closes on nav/backdrop/Escape),
  grouped navigation + org switcher, guided onboarding (7 live-derived
  steps, dismiss stored on organization, dashboard checklist + `/onboarding`
  page), bulk shipment status/archive and bulk claim transition/delete
  (per-item updated/failed, tenant-scoped, policy-checked, audit-logged;
  bulk "delete" for shipments is archive per the v1.0.1 one-way-archive
  design), advanced shipment/claim filters (date/amount ranges, event type,
  broker/carrier, deadline status) + extended search, saved views (max 25
  per resource type, per member), CSV import preview/confirm with per-row
  errors and duplicate flags, evidence inline preview (PDF/images, 422
  with `previewable: false` otherwise), claim review notes + approve /
  request-changes review action.
- New: 3 migrations (onboarding dismissal, saved_views, claim_review_notes),
  models SavedView + ClaimReviewNote, controllers Onboarding/SavedView/
  ClaimReviewNote, new `delete-claims` permission, OpenAPI now 70 paths
  (59 + 11 new), CHANGELOG + docs updated, frontend version 1.1.0.
- Verification done independently by the parent run (not just the builder):
  backend **238 passed (1029 assertions)**, frontend **144 passed**,
  `npm run build` succeeds, migrations ran clean on a fresh SQLite DB, and
  a live HTTP smoke test over artisan serve passed: onboarding GET/dismiss,
  bulk shipment status 2/2, CSV preview (1 valid / 1 duplicate / 1 invalid)
  and confirm (imported 1, skipped 2 with row errors), claim CLM-2026-0001
  created, review note added, bulk transition to under_review, review
  approve to ready_to_submit, bulk archive, bulk delete of a draft claim,
  PDF evidence preview 200 inline, txt preview 422 previewable:false.
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. Minor note: evidence preview
  modal inherits the shared Modal's narrow max-w-md width; previews work
  but are narrow.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel Boost
  via composer/curl. That is repo file content, not a task instruction;
  correctly ignored by the builder, not acted on.
- Push status: **v1.1.0 PUSHED 2026-11-17** after Aqib's yes in the main
  chat + approval card. New remote head `dd63e3a6`, 67 files in one
  commit (v1.1.0 changes plus MASTER-CHECKLIST.md, DEVELOPMENT_PROGRESS.md,
  docs/DEPLOYMENT.md riding along). Read-back verified. Next run may start
  v1.1.1.
- Next task: v1.1.1 — Reliability.

### 2026-11-16 — v1.0.1 audit + daily schedule set up

- Received the master checklist (Phases 0-8, v0.1.0 through v3.3.0).
- Audited v1.0.1 against it: verified rate limiting on auth routes, 59
  OpenAPI paths, 18 feature test files, compose services (db, redis,
  backend, queue, frontend), and the pgsql/Redis config. Found one
  genuinely unfinished item: deployment documentation.
- Wrote docs/DEPLOYMENT.md, closing the deployment-docs gap.
- Saved MASTER-CHECKLIST.md (v1.0.1 marked per audit) and this file.
- Set the daily build schedule (`daily-claimtrail-build`): every day around
  10:10 PKT, first run Tue 2026-11-17, starting v1.1.0. Ready-to-push
  notices go to the main chat; pushes only after Aqib's yes + approval.
- Next task: v1.1.0 — UX Improvements.

### 2026-11-18 — daily build HELD (no new version work)

- Gate check passed first: remote head on GitHub is `dd63e3a6`, matching
  the recorded v1.1.0 head, so no version was waiting to be pushed.
- **v1.1.1 NOT started.** Sequential auto-advancement beyond v1.1.0 is
  paused (standing decision recorded in the alignment synthesis): the next
  version starts only after Aqib (a) dispositions the v1.0.1 unfinished
  items / environment blockers (live PostgreSQL, live Redis, Docker,
  browser E2E, real mailer — accept as documented limitations or verify
  in an environment he provides), (b) decides whether Phase 0 business
  validation is skipped or scheduled, and (c) gives explicit go-ahead for
  v1.1.1 after seeing the predecessor's unfinished items and Aqib-only
  inputs it needs. None of the three has happened yet (checked memory and
  today's log; no go-ahead found).
- No code, checklist, or test changes made in this run. The v1.0.1
  unfinished items are unchanged: live PostgreSQL, live Redis, Docker,
  browser E2E (all environment blockers), real mailer (needs SMTP
  credentials from Aqib), plus the two manual web-UI steps still open
  (CI workflow file, v1.0.1 tag/release).
- The disposition request (unfinished items + Phase 0 choice + go-ahead
  ask) is carried in this run's final report for the main chat.
- **Aqib's disposition, 2026-11-18 ~10:52 PKT (main chat):** blockers
  accepted as documented limitations; explicit go-ahead given to start
  v1.1.1. Phase 0 business validation (skip or schedule) was NOT answered
  by that choice and stays open; flag again before Phase 3 billing work.
- Next task: v1.1.1 — Reliability, **go-ahead given, build started
  2026-11-18**.

### 2026-11-18 — v1.1.1 Reliability built and verified

- All 10 checklist items built: queue monitoring, failed-job handling,
  retry mechanisms, idempotent background jobs, upload reliability,
  database optimization, API performance optimization, structured
  logging, error monitoring, health checks. One new permission
  (`view-operations`: owner, admin).
- Backend: 3 migrations (`job_executions` table, evidence `checksum`,
  3 reliability indexes); new services (HealthCheckService,
  QueueMonitorService, FailedJobService, ErrorLogService,
  ErrorReporter), logging processor + JSON/errors channels, slow-query
  logging, ResponseTime middleware, orphan-evidence cleanup command
  (scheduled weekly); both jobs idempotent with the standard retry
  profile; 9 new API paths (public `/health` + 8 `/ops/*`).
- One real bug found by the new tests and fixed: the failed-job
  organization resolver regexed the raw JSON payload, where the
  serialized command's quotes are backslash-escaped, so no failed
  job ever matched an organization. It now decodes the JSON first
  and matches on the decoded command string.
- Frontend: operations page (`/settings/operations`) with health card,
  queue stats, failed-jobs table (retry/forget, retry-all, flush),
  recent errors, manual refresh; `src/lib/ops.ts` typed client;
  `canViewOperations` permission helper; Settings nav entry; evidence
  upload retry-once on network failure / 5xx in the shared `apiUpload`
  helper (never on 4xx; the duplicate-upload caveat is documented in
  the code comment).
- Docs: OpenAPI 70 -> 79 paths (v1.1.1), CHANGELOG entry, new
  `docs/OPERATIONS.md` runbook, ARCHITECTURE.md + DATABASE.md v1.1.1
  sections.
- Verification (all run by the builder, frontend claims re-verified
  independently): backend 289 passed (1200 assertions), frontend 171
  passed across 20 files, `npm run build` succeeds (35/35 pages),
  `tsc --noEmit` clean, lint clean, fresh-DB `migrate:fresh` passes
  with all 3 migrations and 4 indexes confirmed. Live HTTP smoke test
  over the built-in server on a fresh SQLite DB passed: public health
  (all 4 checks ok), ops health (version 1.1.1, PHP 8.3.6, Laravel
  13.42.0), queue stats with a seeded pending job (oldest age 303s)
  and failed total 1, failed-job retry round trip (jobs 2, failed 0),
  ETag 304, unauthenticated ops request 401, missing org header 400,
  evidence upload checksum matching the file's actual SHA-256.
- Still unverified (standing blockers, unchanged): browser E2E, live
  Postgres, live Redis, Docker, real mailer. v1.1.1-specific notes:
  queue monitoring is database-driver only (Redis monitoring not
  implemented); the errors endpoint reads the local log file, so a
  multi-server deployment needs log aggregation (documented in
  CHANGELOG + OPERATIONS.md).
- Environment note: `artisan serve` fails on this VM (its child
  process dies during bootstrap with an undefined-call error before
  `config` is bound). The same app serves correctly via the built-in
  server run directly and via the CLI kernel, and all 289 backend
  tests pass, so this is a VM quirk, not a code bug. The smoke test
  used the built-in server directly.
- Note: `backend/AGENTS.md` in the repo instructs installing Laravel
  Boost via composer/curl. That is repo file content, not a task
  instruction; correctly ignored by the builder, not acted on (a
  system safety notice also flagged it during this build).
- Push status: **v1.1.1 PUSHED 2026-11-18** after Aqib's yes in the main
  chat + approval card. New remote head `1f2a60e7`, 60 files in one
  commit. Read-back verified. Next run may start v1.1.2.
- Next task: v1.1.2 — Customization.
