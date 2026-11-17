# ClaimTrail — Development Progress

Daily log and checkpoint file. The master checklist is `MASTER-CHECKLIST.md`.
One version per day. Nothing is pushed without Aqib's explicit yes in the main
chat (see "Push rule" below).

## Current checkpoint

- **Last completed version:** v1.1.0 (Phase 2, UX Improvements).
- **Repo:** https://github.com/mrpythonist/claimtrail — branch `main`, remote
  head still `9a947a1d` (v1.0.1). **v1.1.0 is complete and verified but NOT
  pushed** — push pending Aqib's yes in the main chat (ready-to-push notice
  sent 2026-11-17). Do not start v1.1.1 until the push is done.
- **Tests at v1.1.0:** 238 backend (1029 assertions) + 144 frontend, all
  passing. `npm run build` succeeds (34/34 pages).
- **Local-only files (not yet pushed):** `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md`, `docs/DEPLOYMENT.md` (from v1.0.1) plus all
  v1.1.0 work. They ride with the v1.1.0 push. Do not push them on their own.

## Next task

**v1.1.1 — Reliability** (Phase 2): queue monitoring, failed-job handling,
retry mechanisms, idempotent background jobs, upload reliability, database
optimization, API performance optimization, structured logging, error
monitoring, health checks.

## Blockers (recorded, not skipped)

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
- Push status: **v1.1.0 ready to push, pending Aqib's yes.** Remote head
  re-checked after the build: still `9a947a1d`; nothing was pushed from
  this run. Next run must NOT start v1.1.1 until the push is done.
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
