# ClaimTrail — Development Progress

Daily log and checkpoint file. The master checklist is `MASTER-CHECKLIST.md`.
One version per day. Nothing is pushed without Aqib's explicit yes in the main
chat (see "Push rule" below).

## Current checkpoint

- **Last completed version:** v1.2.2 (Phase 3, Marketing and
  Acquisition). App version is now **1.2.2**. Phase 0 is complete
  (v0.1.0, v0.3.0, v0.4.0, v0.5.0, v0.6.0 done; v0.2.0 interviews
  deferred, still open).
- **Repo:** https://github.com/mrpythonist/claimtrail — branch `main`, remote
  head `79d1ab7e` (v1.2.1, pushed 2026-11-25 after Aqib's yes + approval).
  v1.2.2 is built and verified locally, NOT PUSHED.
- **Tests at v1.2.2:** 451 backend (2340 assertions) + 293 frontend, all
  passing. `npm run build` succeeds (55/55 pages). Live HTTP smoke test:
  27/27 backend steps + 13/13 marketing pages on a fresh SQLite DB,
  plus the v1.2.1 regression smoke 55/55 (see the 2026-11-26 v1.2.2 log
  entry).
- **Local-only files now pushed with v1.1.0:** `MASTER-CHECKLIST.md`,
  `DEVELOPMENT_PROGRESS.md`, `docs/DEPLOYMENT.md` rode with the v1.1.0
  push. They are repo files now; keep them updated in place.

## Next task

**v1.2.3 — Production Launch** (MASTER-CHECKLIST.md): customer
support workflows, help documentation, subscription administration,
refund and cancellation processes, production monitoring, backup
verification, privacy policy review, terms of service review, data
processing documentation review, production launch checklist.
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
- Push status: **COMPLETE AND VERIFIED, NOT PUSHED.**
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
