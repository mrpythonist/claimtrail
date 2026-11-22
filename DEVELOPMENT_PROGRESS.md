# ClaimTrail — Development Progress

Daily log and checkpoint file. The master checklist is `MASTER-CHECKLIST.md`.
One version per day. Nothing is pushed without Aqib's explicit yes in the main
chat (see "Push rule" below).

## Current checkpoint

- **Last completed version:** v0.3.0 (Phase 0, Workflow Validation).
  App version stays 1.1.3 — Phase 0 work is research/docs, not code.
- **Repo:** https://github.com/mrpythonist/claimtrail — branch `main`, remote
  head `707fa462` (v0.1.0, pushed 2026-11-21 after Aqib's yes + approval).
  **v0.3.0 is complete and verified but NOT yet pushed** — it is ready
  to push pending Aqib's yes in the main chat.
- **Tests at v0.3.0:** 382 backend (1902 assertions) + 241 frontend, all
  passing — same counts as v1.1.3. `npm run build` succeeds (37/37 pages).
- **Local-only files now pushed with v1.1.0:** `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md`, `docs/DEPLOYMENT.md` rode with the v1.1.0
  push. They are repo files now; keep them updated in place.

## Next task

**Phase 0 — Business Validation, v0.4.0 Competitor Analysis**
(research competing products, compare features and pricing, identify
product gaps, define ClaimTrail differentiation; the 2026-11-14
DockClock competitor check is a head start). **v0.2.0 Customer
Interviews stays DEFERRED, not dropped:** Aqib said 2026-11-21 to
skip what needs him right now, and interviews need his help reaching
carriers/dispatchers. It returns to the front of the queue when he is
ready to do outreach. Then v0.5.0 Pricing Validation.
**v1.2.0 — Subscription Billing** (Phase 3) stays queued after Phase 0:
still blocked on Stripe keys from Aqib, and the checklist requires the
Phase 0 outcome before billing work.

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
- Push status: **v0.3.0 COMPLETE and VERIFIED, NOT pushed.** Ready to
  push pending Aqib's yes in the main chat. 2 files in one commit
  (`docs/validation/0.3.0-workflow-validation.md` new,
  `docs/validation/README.md` updated). Next run must gate-check the
  remote head first; if v0.3.0 is still unpushed, it must not start
  v0.4.0.
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
