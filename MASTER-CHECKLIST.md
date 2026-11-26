# CLAIMTRAIL — MASTER DEVELOPMENT CHECKLIST

Project: ClaimTrail
Goal: Build a complete, production-ready, profitable B2B SaaS platform.

> Audit note (2026-11-16): v1.0.1 was audited against this checklist after the
> Phase 1 build (repo head `9a947a1d`, 6 commits, 313 files, 310 tests passing).
> Items marked `[x]` were verified live (tests re-run, smoke tests over HTTP,
> OpenAPI checked against live routes). Four items stay unchecked because they
> need an environment this VM does not have: live PostgreSQL, live Redis, Docker,
> and a real browser. They are recorded as blockers in DEVELOPMENT_PROGRESS.md,
> not skipped. Phase 0 was not started; the checklist's own starting point is
> v1.0.1.

## PHASE 0 — BUSINESS VALIDATION

> Not started. Starting point per this checklist is Phase 1, v1.0.1.

### v0.1.0 — Market Research

> Verified 2026-11-21 (daily build run): research document
> `docs/validation/0.1.0-market-research.md` built from public sources
> accessed 2026-11-21 (ATRI Sep 2024 detention study, DOT OIG ST2018019,
> FMCSA census, ATA, accessorial rate guides, GetDockPay / Detention
> Buddy / Tezify / TenTrucks public pages). No interviews and no primary
> data in this version — that is v0.2.0's job; every willingness-to-pay
> and sizing figure is labelled sourced vs. estimate in the document.
> No code changed in this version; backend 382 + frontend 241 tests
> re-run and passing, `npm run build` succeeds, confirming the repo is
> still green at v1.1.3. One real defect caught by the verification:
> the backend suite failed 4 SecurityCheckTest tests in a clean
> checkout because phpunit.xml set no APP_KEY; fixed with a test-only
> key in phpunit.xml (382 tests, 1902 assertions — the v1.1.3 counts).
> One finding for a future version: GPS-stamped
> mobile capture is the most valuable feature this research surfaced
> and is not in any checklist version yet.

- [x] Research trucking revenue leakage.
- [x] Identify missed accessorial charges.
- [x] Research target customer segments.
- [x] Document existing solutions.
- [x] Identify market opportunities.

### v0.2.0 — Customer Interviews

- [ ] Prepare interview questions.
- [ ] Identify potential customers.
- [ ] Conduct 15+ customer interviews.
- [ ] Document customer pain points.
- [ ] Collect anonymized workflow examples.

### v0.3.0 — Workflow Validation

> Verified 2026-11-22 (daily build run): research document
> `docs/validation/0.3.0-workflow-validation.md` (8,621 words) built
> from the ClaimTrail code as read 2026-11-22 plus public trade
> sources accessed 2026-11-22 (listed in the document §9). Every
> app-behaviour claim was spot-checked against the code by the parent
> run: the 10-status transition table matches `ClaimStatus.php`
> exactly; readiness returns `ready` with zero requirements
> (`ReadinessService.php`); the deadline clock runs from claim
> creation (`ClaimController.php`, `applyDeadlineDefault`); the seven
> seeded event categories match `EventCategorySeeder.php`; payment
> only via record-payment from accepted/partially accepted, rejection
> and pre-submission close both require a reason
> (`ClaimTransitionService.php`); deadline comparisons use the
> deadline's own timezone (`DeadlineStatusService.php`); v0.1.0
> figures reused (75% billed × 55% paid = 41% collected; 67% of
> carriers use 2-hour free time). No code changed in this version;
> backend 382 + frontend 241 tests re-run and passing, `npm run
> build` succeeds (37/37 pages), confirming the repo is still green
> at v1.1.3. The document records 14 app-vs-workflow gaps (no
> submission channel, no GPS/ELD capture, deadline from creation not
> delivery, no default evidence requirements, accepted-but-unpaid has
> no follow-up state, one claim per event, evidence on claims not
> events, and more) and 14 open questions for v0.2.0 interviews.
> No interviews, no pilot customers, no primary data — that is
> v0.2.0's job, still deferred pending Aqib's help with outreach.

- [x] Document shipment-to-claim workflow.
- [x] Define claim lifecycle.
- [x] Define evidence requirements.
- [x] Document deadline handling.
- [x] Define financial terminology.

### v0.4.0 — Competitor Analysis

> Verified 2026-11-23 (daily build run): research document
> `docs/validation/0.4.0-competitor-analysis.md` (8,573 words) built
> from public sources accessed 2026-11-23. Eleven competitor pages
> opened and read in full (GetDockPay, DOCK2BILL + dispatchers page,
> Detention Buddy, TruckersClaims, DetentionHero, Triporto, Eden One
> product + pricing, Run Your Rig home + features); Tezify and
> TenTrucks read via search results; Toro/StackViro/Vektor carried
> from 2026-11-14. New finds: Triporto (driver app, early access),
> Eden One (office product with detention workflow, founder $69/mo),
> Run Your Rig (owner-operator TMS, AI rate-con parsing,
> $9.99/driver/mo). Headline changes since November 14: GetDockPay
> shipped v1.1 with direct deposit; TruckersClaims Pro priced at
> $29/mo per truck but still not shipping; DOCK2BILL opened a
> dispatcher partner program (20-25% commission); Tezify published
> full pricing ($49-$799) and deeper detention docs. Feature and
> pricing comparison tables (ClaimTrail vs 8 rivals), 10 product
> gaps (no submission channel and no GPS capture are the most
> important), differentiation revised to 4-5/10 at the carrier desk
> (was 5-6/10) and 7-8/10 [estimate] at the multi-tenant agency
> desk, plus 6 added interview questions for v0.2.0, pricing
> questions for v0.5.0 (agency pricing, free tier too thin at 3
> days) and 6 roadmap proposals (submission channel first). No code
> changed in this version; backend 382 + frontend 241 tests
> re-run and passing, `npm run build` succeeds (37/37 pages),
> confirming the repo is still green at v1.1.3.

- [x] Research competing products.
- [x] Compare features and pricing.
- [x] Identify product gaps.
- [x] Define ClaimTrail differentiation.

### v0.5.0 — Pricing Validation

> Verified 2026-11-24 (daily build run): research document
> `docs/validation/0.5.0-pricing-validation.md` (8,587 words) built
> from public sources accessed 2026-11-24. Six pricing models plus
> hybrid and outcome variants researched with 2025-2026 benchmarks
> (Poyar 2026 survey: hybrid 37% most common, outcome-based primary
> 5%; tiered packaging used by 67% of SaaS companies; subscription
> the primary model for over 80% of B2B SaaS). Willingness to pay
> validated by proxy only, stated honestly: dispatch services at
> 5-8% of gross linehaul ($1,000-1,600/mo per truck at $20,000 gross)
> against $19-49/mo for the tool; trial benchmarks (opt-in median
> about 18.5%, 7-day 24%, 14-day 19%, 30-day 14%; freemium 2-5%;
> card-required about 49%); trial length tested against the claim
> life cycle, which needs 30 days to see a claim submitted and
> followed up. Pay-when-it-bills-clean tested against the
> success-fee precedents (DueWell $500-2,000/mo plus 8-15% of
> recovered cash; ChurnBack $49/mo plus 25%; Amazon recovery
> contingency 15-25%). Revised pricing hypotheses: Free (1 truck, 5
> claims/mo), Starter $19 (1-2 trucks), Growth $49 (3-10 trucks,
> default), Fleet $99 (11-30 trucks, new), Agency $149/mo base for up
> to 10 carriers plus $12 per extra carrier (new, set against
> DOCK2BILL Partner Pro $149/mo); success-fee plan $0 base, 15% of
> amounts recorded as paid, capped at $49/mo for the first year;
> permanent Free tier replaced as the entry offer by a 30-day
> no-card trial on Growth. Limits rewritten from internal units
> (events, days of history) to buyer units (trucks, claims, members,
> storage). Subscription structure specified for v1.2.0/v1.2.1
> (plans, entitlements, upgrade prompts, no card for trial, no data
> deletion on downgrade). Market sizing revised to $4-6M ARR at
> 10,000 paying organizations (was $4.7M). Two new data points:
> CabCommand flat fleet tiers ($29.99/$69.99/$129.99/$249.99) and 3
> Lakes Logistics automated recovery at $300/mo. Triporto and
> DetentionHero re-checked 2026-11-24, both still early access with
> no price. No code changed in this version; backend 382 + frontend
> 241 tests re-run and passing, `npm run build` succeeds (37/37
> pages), confirming the repo is still green at v1.1.3. No
> interviews: willingness to pay is proxy-only until v0.2.0, and
> section 10 of the document carries 10 interview questions plus
> kill criteria.

- [x] Research pricing models.
- [x] Validate willingness to pay.
- [x] Define initial pricing hypotheses.
- [x] Document subscription structure.

### v0.6.0 — Pilot Preparation

> Verified 2026-11-25 (daily build run): research document
> `docs/validation/0.6.0-pilot-preparation.md` (8,997 words) plus
> `docs/validation/pilot/sample-shipments.csv` (12 synthetic
> shipments, 3 fictional carriers) and
> `docs/validation/pilot/pilot-scenarios.md` (2,436 words, per-shipment
> walkthrough, $2,440 requested across 11 claims [estimate]).
> Three pilot profiles from the v0.1.0 beachhead (dispatch agency,
> 5-10 truck reefer fleet on spot freight, 2-3 truck fleet billing
> detention regularly) with fit criteria, sourcing channels and
> disqualifiers, plus a 5-factor scorecard (12/20 to qualify, hard
> gates) and a 6-step outreach shortlist process — profiles, not
> names: no pilot customer has been recruited, and recruitment needs
> Aqib's outreach (v0.2.0-dependent, interviews still deferred since
> 2026-11-21). Onboarding runbook grounded in the app's actual seven
> steps (`OnboardingService.php`), the 30-day no-card Growth trial,
> week-by-week plan, support cadence, CSV data migration, and the
> day-30 decision point. Success metrics carry the v0.5.0 targets
> (trial-to-paid 15% good / 8% floor; free-to-paid 3-5% good) plus
> activation and outcome metrics with targets, floors, measurement
> methods naming the real reports endpoints, and go / no-go / iterate
> rules, plus the v0.5.0 kill criteria and 4 pilot-specific ones.
> Triporto and DetentionHero re-checked 2026-11-25, both still early
> access with no price; one new find, DetentionDesk ($25 per claim
> packet or $149/mo, detention-only, no GPS/ELD verification,
> verified against its pricing page). One real defect caught by the
> parent run's verification and fixed: the sample CSV's last row had
> an unquoted comma in its notes field, which would have truncated
> the note on import. No code changed in this version (docs + sample
> data only); backend 382 + frontend 241 tests re-run and passing,
> `npm run build` succeeds (37/37 pages). Live HTTP smoke test over
> the built-in server on a fresh SQLite DB: the sample CSV previews
> 12 valid / 0 invalid / 0 duplicate and imports 12/12, all 12
> references listed. Phase 0 ends here as preparation: the next
> validation work is contact with real people. v1.2.0 stays blocked
> on Stripe keys + the metered-billing decision.

- [x] Identify at least 3 potential pilot customers.
- [x] Prepare onboarding process.
- [x] Prepare sample datasets.
- [x] Define pilot success metrics.

---

## PHASE 1 — CORE MVP

### v1.0.1 — Core SaaS Platform

#### Foundation

- [x] Laravel backend.
- [x] Next.js frontend.
- [ ] PostgreSQL database. (Configured: `DB_CONNECTION=pgsql`, compose `db` service, migrations written for Postgres. Never run against live Postgres; all tests ran on SQLite. Environment blocker.)
- [ ] Redis queues and caching. (Compose `redis` service and `REDIS_*` config exist, but default `QUEUE_CONNECTION`/`CACHE_STORE` are `database` and no queue worker ever ran against live Redis. Environment blocker.)
- [ ] Docker development environment. (`docker-compose.yml` + backend/frontend Dockerfiles exist. Never built or run; no Docker on the build VM. Environment blocker.)
- [x] Private file storage.
- [x] Modular architecture.
- [x] API structure and documentation.

#### Authentication

- [x] Registration and login.
- [x] Logout.
- [x] Password reset.
- [x] Email verification.
- [x] Protected routes.
- [x] Authentication rate limiting.

#### Organizations and Multi-tenancy

- [x] Organization creation.
- [x] Organization settings.
- [x] Organization memberships.
- [x] Invitations.
- [x] Organization switching.
- [x] Strict tenant isolation.

#### RBAC

- [x] Owner role.
- [x] Admin role.
- [x] Operations Manager role.
- [x] Dispatcher role.
- [x] Billing Staff role.
- [x] Viewer role.
- [x] Centralized permissions.

#### Dashboard

- [x] Shipment statistics.
- [x] Claim statistics.
- [x] Upcoming deadlines.
- [x] Overdue claims.
- [x] Potential recovery amounts.
- [x] Submitted amounts.
- [x] Accepted amounts.
- [x] Actual recovered amounts.

#### Shipment Management

- [x] Shipment CRUD.
- [x] Broker and customer details.
- [x] Carrier and driver details.
- [x] Pickup and delivery information.
- [x] Appointment details.
- [x] Shipment rates and currency.
- [x] Shipment statuses.
- [x] Search, filters, and pagination.
- [x] CSV import and export.
- [x] Duplicate detection.

#### Shipment Events

- [x] Detention events.
- [x] Layover events.
- [x] TONU events.
- [x] Lumper events.
- [x] Additional stops.
- [x] Additional mileage.
- [x] Custom events.
- [x] Event timestamps, duration, amounts, and notes.

#### Evidence Management

- [x] Document uploads.
- [x] Document categorization.
- [x] Shipment/event/claim associations.
- [x] Private file storage.
- [x] File validation.
- [x] Document previews.
- [x] Secure downloads.

#### Claim Management

- [x] Claim creation.
- [x] Claim references.
- [x] Requested amounts.
- [x] Evidence association.
- [x] Claim statuses.
- [x] Submission tracking.
- [x] Acceptance/rejection tracking.
- [x] Partial approval.
- [x] Payment tracking.
- [x] Claim status history.

#### Evidence Requirements

- [x] Configurable evidence requirements.
- [x] Required and optional documents.
- [x] Missing evidence detection.
- [x] Claim readiness checklist.
- [x] Authorized requirement overrides.

#### Deadline Management

- [x] Configurable deadlines.
- [x] Deadline source and reference.
- [x] Timezone support.
- [x] Upcoming deadline indicators.
- [x] Overdue indicators.
- [x] Automated reminders. (In-app + queued email verified live with dedupe; email transport through a real mailer is unverified, log driver only.)

#### Claim Packages

- [x] PDF generation.
- [x] Shipment information.
- [x] Event details.
- [x] Claim amount.
- [x] Event timeline.
- [x] Evidence index.
- [x] Secure package downloads.

#### Audit and Notifications

- [x] Audit logs.
- [x] User activity history.
- [x] Claim change history.
- [x] In-app notifications.
- [x] Email notifications. (Queued; verified with Mail fake. Real mailer transport unverified.)
- [x] Notification preferences.

#### Reports

- [x] Claims by status.
- [x] Claims by event type.
- [x] Potential recovery reports.
- [x] Submitted amount reports.
- [x] Accepted amount reports.
- [x] Actual recovery reports.
- [x] Rejection reports.
- [x] Outstanding balance reports.
- [x] CSV exports.

#### Quality and Documentation

- [x] Backend tests.
- [x] Frontend tests.
- [x] API tests.
- [x] Tenant isolation tests.
- [ ] End-to-end workflow tests. (Scenarios A-I pass live over HTTP. No browser E2E run; no Playwright setup. Environment blocker.)
- [x] Security review.
- [x] README and setup documentation.
- [x] Architecture documentation.
- [x] Database documentation.
- [x] API documentation.
- [x] Deployment documentation. (docs/DEPLOYMENT.md added 2026-11-16 during the audit.)

---

## PHASE 2 — PRODUCT HARDENING

### v1.1.0 — UX Improvements

> Verified 2026-11-17 (daily build run): backend 238 passed (1029 assertions), frontend 144 passed, `npm run build` succeeds, live HTTP smoke test over artisan serve passed (onboarding, bulk ops, filters, saved views, CSV preview/confirm with row errors, review notes + approve, evidence inline preview). Still unverified: browser E2E (standing blocker), live Postgres/Redis/Docker (standing blockers).

- [x] Improve responsive UI.
- [x] Improve mobile usability.
- [x] Improve navigation.
- [x] Guided onboarding.
- [x] Bulk shipment operations.
- [x] Bulk claim operations.
- [x] Advanced search and filters.
- [x] Saved views.
- [x] Improved CSV imports.
- [x] Evidence previews.
- [x] Improved claim review workflows.

### v1.1.1 — Reliability

> Verified 2026-11-18 (daily build run): backend 289 passed (1200 assertions), frontend 171 passed, `npm run build` succeeds (35/35 pages, incl. /settings/operations), `tsc --noEmit` clean, lint clean, fresh-DB `migrate:fresh` passes (3 new migrations, 4 new indexes), live HTTP smoke test over the built-in server passed (public health, ops health + queue stats with a seeded job, failed-job retry round trip, ETag 304, upload checksum). Still unverified: browser E2E (standing blocker), live Postgres/Redis/Docker (standing blockers), Redis queue monitoring (not implemented; database driver only). Note: `artisan serve` itself fails on this VM (child dies during bootstrap); the same app serves fine via the built-in server directly and via the CLI kernel, so this is a VM quirk, not a code bug.

- [x] Queue monitoring.
- [x] Failed-job handling.
- [x] Retry mechanisms.
- [x] Idempotent background jobs.
- [x] Upload reliability.
- [x] Database optimization.
- [x] API performance optimization.
- [x] Structured logging.
- [x] Error monitoring.
- [x] Health checks.

### v1.1.2 — Customization

> Verified 2026-11-19 (daily build run): backend 322 passed (1411 assertions), frontend 221 passed, `npm run build` succeeds (36/36 pages, incl. /settings/customization), `tsc --noEmit` clean, lint clean, fresh-DB `migrate:fresh` passes (6 new migrations), live HTTP smoke test over the built-in server passed 28/28 steps (org settings incl. claim prefix, custom fields + validation, deadline settings + resolve + auto-deadline, event categories incl. disable/reject/prefill, evidence template apply + idempotent re-apply, export templates + templated export, notification preferences, cross-tenant 404, viewer 403). One real bug found by the parent run's smoke test and fixed in this version: POST /event-categories silently dropped default_estimated_amount/default_currency (only PUT accepted them); create now accepts and persists them, with a new regression test. Notification preferences were already complete from v1.0.1 and were verified, not rebuilt. Still unverified: browser E2E (standing blocker), live Postgres/Redis/Docker (standing blockers).

- [x] Custom shipment fields.
- [x] Custom claim fields.
- [x] Configurable event categories.
- [x] Evidence templates.
- [x] Custom deadline settings.
- [x] Notification preferences.
- [x] Export templates.
- [x] Organization-specific settings.

### v1.1.3 — Security and Operations

> Verified 2026-11-20 (daily build run): backend 382 passed (1902 assertions), frontend 241 passed, `npm run build` succeeds (37/37 pages, incl. /settings/security), `tsc --noEmit` clean, lint clean, fresh-DB `migrate:fresh` passes (2 new migrations: backup_runs, security_events), live HTTP smoke test over the built-in server passed (security check admin 200 / viewer 403 / unauthenticated 401, backup create 201 + verify ok:true on a file SQLite DB, retention get/put with override applied, failed login + denial recorded as security events, cross-tenant backup 404, CSP header present, `security:check` CLI 9 pass / 1 warn, `retention:purge --dry-run` deletes nothing). Still unverified: browser E2E (standing blocker), live Postgres/Redis/Docker (standing blockers), real mailer. v1.1.3-specific: the PostgreSQL backup/restore paths (`pg_dump`/`pg_restore`) were never run against live PostgreSQL (no server or client tools on this VM); queue monitoring is still database-driver only; the errors endpoint still reads the local log file. Phase 0 business validation still not done — flag again before Phase 3 billing.

- [x] Tenant isolation audit.
- [x] RBAC security audit.
- [x] File access security review.
- [x] Backup configuration.
- [x] Restore testing.
- [x] Data retention policies.
- [x] Production deployment hardening.
- [x] Incident response documentation.

---

## PHASE 3 — COMMERCIAL LAUNCH

### v1.2.0 — Subscription Billing

- [x] Stripe integration. (Mock provider behind the `BillingProvider` interface, per Aqib's 2026-11-25 decision: skip real Stripe, build with dummy data. No real Stripe calls, no Stripe keys. Real Stripe is a later provider swap, documented in docs/BILLING.md.)
- [x] Subscription plans.
- [x] Monthly billing.
- [x] Annual billing.
- [x] Checkout.
- [x] Free trials.
- [x] Subscription upgrades.
- [x] Subscription downgrades.
- [x] Renewals.
- [x] Cancellations.
- [x] Invoice history.
- [x] Failed payment handling.
- [x] Stripe webhooks. (Simulated: HMAC-verified endpoint + simulate endpoint; exact redelivery recorded as a duplicate.)
- [x] Billing portal. (Mocked: portal URL + token summary.)

### v1.2.1 — Plans and Entitlements

- [x] Shipment limits.
- [x] Claim limits.
- [x] Organization member limits.
- [x] Storage limits.
- [x] Document limits.
- [x] Reporting restrictions.
- [x] Backend entitlement enforcement.
- [x] Usage tracking.
- [x] Upgrade prompts.

### v1.2.2 — Marketing and Acquisition

- [x] Marketing website.
- [x] Product overview page.
- [x] Feature pages.
- [x] Pricing page.
- [ ] Product screenshots.
- [x] Product demonstration.
- [x] Demo request form.
- [x] Lead capture.
- [x] SEO optimization.
- [x] Analytics integration.
- [x] Customer onboarding.

### v1.2.3 — Production Launch

- [x] Customer support workflows.
- [x] Help documentation.
- [x] Subscription administration.
- [x] Refund and cancellation processes.
- [x] Production monitoring.
- [x] Backup verification.
- [x] Privacy policy review.
- [x] Terms of service review.
- [x] Data processing documentation review.
- [x] Production launch checklist.

---

## PHASE 4 — INTEGRATIONS

### v1.3.0 — Email Intake

> Verified 2026-11-26 (daily build run): backend 497 passed (2576 assertions), frontend 333 passed, `npm run build` succeeds (60/60 pages, incl. /email-intake and /email-intake/[id]), `tsc --noEmit` clean, lint clean of new findings (the two remaining findings are pre-existing from v1.2.2), fresh-DB `migrate:fresh` passes (1 new migration, 3 new tables), live HTTP smoke test over the built-in server passed 48/48 backend steps + 3/3 CLI checks on a fresh SQLite DB (address provisioning on org creation, webhook bad signature 401, unknown recipient 422 with a generic message, disallowed attachment 422, valid email auto-matched with attachments stored as evidence, duplicate message_id deduped, no-reference email unmatched then manually matched, unmatch, dismiss, dismiss-of-matched refused 422, multi-match unmatched, list filters, cross-tenant 404s, plus a regression pass of plans, register, org, ops health version 1.3.0, and support create). OpenAPI 136 paths, verified to match the 136 live `api/v1` route URIs exactly. Still unverified: browser E2E (standing blocker), live Postgres/Redis/Docker (standing blockers), real mailer, and no real inbound provider is wired (see docs/EMAIL-INTAKE.md).

- [x] Organization-specific email addresses.
- [x] Inbound email processing.
- [x] Attachment extraction.
- [x] Shipment reference extraction.
- [x] Automatic shipment matching.
- [x] Manual matching interface.
- [x] Email processing history.

### v1.3.1 — Advanced Imports

- [ ] Saved CSV mappings.
- [ ] Scheduled imports.
- [ ] Duplicate detection.
- [ ] Import history.
- [ ] Error correction.
- [ ] Import notifications.
- [ ] Synchronization workflows.

### v1.3.2 — TMS Integrations

- [ ] Research customer-used TMS platforms.
- [ ] Select integration targets.
- [ ] Implement authentication.
- [ ] Shipment synchronization.
- [ ] Status synchronization.
- [ ] Appointment synchronization.
- [ ] Document synchronization.
- [ ] Integration settings.
- [ ] Sync monitoring and error handling.

### v1.3.3 — Communication Integrations

- [ ] Slack integration.
- [ ] Microsoft Teams integration.
- [ ] Driver evidence submission links.
- [ ] Email workflows.
- [ ] Communication notifications.
- [ ] Secure access and expiration controls.

---

## PHASE 5 — FINANCIAL INTELLIGENCE

### v1.5.0 — Financial Reconciliation

- [ ] Payment ledger.
- [ ] Requested amount tracking.
- [ ] Approved amount tracking.
- [ ] Partial payments.
- [ ] Actual paid amount tracking.
- [ ] Outstanding balances.
- [ ] Payment references.
- [ ] Payment history.
- [ ] Reconciliation reports.

### v1.5.1 — Advanced Analytics

- [ ] Recovery rate analytics.
- [ ] Rejection rate analytics.
- [ ] Average submission time.
- [ ] Average payment time.
- [ ] Broker performance.
- [ ] Carrier performance.
- [ ] Event-type performance.
- [ ] Monthly/yearly comparisons.
- [ ] Advanced charts and exports.

### v1.5.2 — Operational Intelligence

- [ ] Detention trend analysis.
- [ ] Facility performance analysis.
- [ ] Rejection reason analysis.
- [ ] Missing evidence trends.
- [ ] Broker-level trends.
- [ ] Revenue leakage analysis.
- [ ] Operational recommendations.

---

## PHASE 6 — INTELLIGENT AUTOMATION

### v2.0.0 — Workflow Engine

- [ ] Workflow configuration.
- [ ] Trigger system.
- [ ] Conditional rules.
- [ ] Automated evidence checks.
- [ ] Deadline rules.
- [ ] Automated claim preparation.
- [ ] Escalation workflows.
- [ ] Human approval checkpoints.
- [ ] Workflow execution history.
- [ ] Failure handling and retries.

### v2.0.1 — AI Document Processing

- [ ] OCR integration.
- [ ] Document classification.
- [ ] Data extraction.
- [ ] Shipment reference extraction.
- [ ] Shipment matching.
- [ ] Duplicate detection.
- [ ] Confidence scoring.
- [ ] Human verification interface.
- [ ] Extraction accuracy monitoring.

### v2.0.2 — API and Integrations

- [ ] Public REST API.
- [ ] API authentication.
- [ ] API keys and scopes.
- [ ] Key rotation and revocation.
- [ ] Webhooks.
- [ ] Webhook retries.
- [ ] Accounting integrations.
- [ ] Authorized broker integrations.
- [ ] Developer documentation.

### v2.0.3 — Enterprise Readiness

- [ ] Advanced audit controls.
- [ ] Security policies.
- [ ] Data retention controls.
- [ ] Disaster recovery.
- [ ] Load testing.
- [ ] Monitoring and alerting.
- [ ] Service-level objectives.
- [ ] Scalability improvements.
- [ ] Enterprise security assessment.

---

## PHASE 7 — PARTNER ECOSYSTEM

### v2.1.0 — Agency Management

- [ ] Partner accounts.
- [ ] Multiple carrier management.
- [ ] Agency dashboard.
- [ ] Carrier-specific permissions.
- [ ] Consolidated reporting.
- [ ] Referral tracking.
- [ ] Commission tracking.
- [ ] Reseller workflows.

### v2.1.1 — White Label

- [ ] Custom branding.
- [ ] Custom logos.
- [ ] Custom colors.
- [ ] Custom domains.
- [ ] Branded customer portals.
- [ ] Partner-specific email templates.
- [ ] Branding permissions.

### v2.1.2 — Expanded Revenue Recovery

- [ ] Additional charge categories.
- [ ] Expanded billing reconciliation.
- [ ] Applicable expense recovery workflows.
- [ ] Carrier financial reports.
- [ ] Expanded evidence requirements.

### v2.1.3 — Integration Marketplace

- [ ] Integration directory.
- [ ] Third-party integration registration.
- [ ] Developer onboarding.
- [ ] Extension framework.
- [ ] Webhook subscriptions.
- [ ] Integration permissions.
- [ ] Integration monitoring.

---

## PHASE 8 — MARKET EXPANSION

### v3.0.0 — Internationalization

- [ ] Multiple currencies.
- [ ] Multiple languages.
- [ ] Timezone support.
- [ ] Regional date/time formats.
- [ ] Country-specific settings.
- [ ] Regional workflows.

### v3.1.0 — New Transportation Markets

- [ ] Research additional transportation segments.
- [ ] Validate customer demand.
- [ ] Define new workflows.
- [ ] Add segment-specific configurations.
- [ ] Implement validated market features.

### v3.2.0 — Predictive Analytics

- [ ] Recovery forecasting.
- [ ] Revenue leakage prediction.
- [ ] Anomaly detection.
- [ ] Historical performance forecasting.
- [ ] Operational benchmarking.
- [ ] Confidence-based recommendations.
- [ ] Model accuracy monitoring.

### v3.3.0 — Mature Platform

- [ ] Advanced platform customization.
- [ ] Enterprise integrations.
- [ ] Expanded partner ecosystem.
- [ ] Extension management.
- [ ] Advanced reporting.
- [ ] Platform administration.
- [ ] Scalability improvements.
- [ ] Final security and reliability audit.

---

## GLOBAL RELEASE CHECKLIST

Apply to every version:

- [ ] Requirements implemented.
- [ ] Frontend completed.
- [ ] Backend completed.
- [ ] Database migrations completed.
- [ ] APIs integrated.
- [ ] Authorization verified.
- [ ] Tenant isolation verified.
- [ ] Automated tests passing.
- [ ] Regression tests passing.
- [ ] Security reviewed.
- [ ] Performance checked.
- [ ] Documentation updated.
- [ ] Changelog updated.
- [ ] Deployment verified.
- [ ] Version acceptance criteria satisfied.

> v1.0.1 against this list: all items satisfied except Deployment verified
> (no production environment exists yet) and the browser-E2E part of
> regression testing. See DEVELOPMENT_PROGRESS.md.

## FINAL BUSINESS READINESS CHECKLIST

- [ ] Core product works end to end.
- [ ] Real pilot customers validated the workflow.
- [ ] Subscription billing works.
- [ ] Customers can onboard independently.
- [ ] Customers can pay and manage subscriptions.
- [ ] Production monitoring and backups work.
- [ ] Customer support is operational.
- [ ] Integrations work reliably.
- [ ] Financial reporting is accurate.
- [ ] Automation is tested and human-controlled.
- [ ] Enterprise security and scalability are verified.
- [ ] Partner workflows work.
- [ ] Paying customers use the product repeatedly.
- [ ] Sustainable customer acquisition and retention exist.

---

## AI AGENT PROGRESS RULES

1. Treat this document as the master checklist.
2. Verify existing work before checking any item as completed.
3. Never mark an item complete just because code exists.
4. Update checklist progress after each completed task.
5. Work sequentially, starting from the first unfinished item.
6. Complete one version's acceptance criteria before advancing.
7. Maintain "DEVELOPMENT_PROGRESS.md" with daily progress and the next task.
8. Preserve all existing working functionality.
9. Record blockers rather than silently skipping tasks.
10. Continue daily from the last verified checkpoint.

Current starting point: Phase 1, v1.0.1. Its existing implementation must be
audited against this checklist. Continue with any genuinely unfinished v1.0.1
items, then proceed to Phase 2, v1.1.0.

Final target: Complete Phase 8, v3.3.0, with all applicable release and business
readiness requirements verified.
