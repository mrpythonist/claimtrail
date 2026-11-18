# ClaimTrail — Development Progress

Daily log and checkpoint file. The master checklist is `MASTER-CHECKLIST.md`.
One version per day. Nothing is pushed without Aqib's explicit yes in the main
chat (see "Push rule" below).

## Current checkpoint

- **Last completed version:** v1.1.1 (Phase 2, Reliability).
- **Repo:** https://github.com/mrpythonist/claimtrail — branch `main`, remote
  head `dd63e3a6` (v1.1.0, pushed 2026-11-17 after Aqib's yes + approval).
  v1.1.1 is built and verified locally, **not pushed** — waiting for
  Aqib's yes.
- **Tests at v1.1.1:** 289 backend (1200 assertions) + 171 frontend, all
  passing. `npm run build` succeeds (35/35 pages). `tsc --noEmit` clean,
  lint clean.
- **Local-only files now pushed with v1.1.0:** `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md`, `docs/DEPLOYMENT.md` rode with the v1.1.0
  push. They are repo files now; keep them updated in place.

## Next task

**v1.1.2 — Customization** (Phase 2): custom shipment fields, custom claim
fields, configurable event categories, evidence templates, custom deadline
settings, notification preferences, export templates, organization-specific
settings. (Notification preferences already exist from M4; v1.1.2 should
verify before building that item.)

## Blockers (recorded, not skipped)

**Disposition 2026-11-18:** Aqib accepted blockers 1-5 as documented
limitations (main chat, ~10:52 PKT). They stay listed here as known
unverified items, not as gates. Blocker 6 (Phase 0) is still undecided.

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
6. **Phase 0 validation never done.** No customer interviews, no pilot
   customers, no validated willingness to pay. The checklist's starting
   point is v1.0.1, so building continues, but this is a business risk
   before Phase 3 billing work. Aqib's call.

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
- Push status: **v1.1.1 ready to push, NOT pushed.** Waiting for Aqib's
  yes in the main chat + approval card. Nothing was pushed, tagged, or
  added under `.github/workflows` in this build.
- Next task: v1.1.2 — Customization.
