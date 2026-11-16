# Phase 1 Scope (v1.0.1)

Phase 1 is the foundation of ClaimTrail: accounts, organizations, and the
full claim workflow for trucking revenue recovery. This document tracks
which of the 14 specified modules are done and which are still to come.

## Status

| # | Module | Milestone | Status |
| - | ------ | --------- | ------ |
| 1 | Authentication | M1 | Done |
| 2 | Multi-tenant organizations | M1 | Done |
| 3 | Role-based access control | M1 | Done |
| 4 | Dashboard | M1 (overview) / M2 (shipments) / M3 (claims, deadlines) / M4 (full metrics) | Done: full counts, upcoming deadlines, per-currency financials, date and status filters |
| 5 | Shipment management | M2 | Done |
| 6 | Shipment events | M2 | Done |
| 7 | Evidence management | M2 | Done |
| 8 | Claim management | M3 | Done |
| 9 | Evidence requirements and readiness | M3 | Done |
| 10 | Deadline management | M3 | Done (deadline notifications delivered in M4) |
| 11 | Claim package generation (PDF) | M3 | Done |
| 12 | Claim history and audit trail | M4 | Done |
| 13 | Notifications | M4 | Done |
| 14 | Basic reporting | M4 | Done |

## Milestone 0: environment probe (done)

- PHP 8.3.6, Composer 2.7.1, Laravel 13.42.0, Sanctum 4.4.
- Next.js 16.4.6, React 19.3.0, Tailwind CSS 4.3.3, Vitest 5.1.0.
- Note: the original brief said Laravel 11, but `composer create-project`
  installs the current stable release, which is Laravel 13. The project
  uses Laravel 13.

## Milestone 1: foundation (done)

- Auth API: register, login, logout, forgot password, reset password,
  email verification, profile and password update. Rate limited.
- Organizations: create, profile, settings, member list, invitations,
  accept invitation, activate/deactivate/remove members, switch orgs.
- Tenancy enforced on the backend: header resolution middleware, global
  query scope, policy and gate authorization, 404 for cross-tenant access.
- Six roles, one central permission map, owner protections.
- Design system and app shell: sidebar, org switcher, user menu,
  breadcrumbs, page headers, reusable table and form components.
- Pages: landing, login, register, forgot/reset password, verify email,
  invitation accept, privacy/terms placeholders, dashboard, organization
  settings, members and invitations, profile settings, create organization.
- Docker Compose setup (backend, queue worker, frontend, PostgreSQL,
  Redis) and Dockerfiles.
- Documentation: README, architecture, database, API, OpenAPI, security,
  development, scope, roadmap, changelog.
- Tests: 51 backend feature tests, 16 frontend tests, production build
  verified, lint clean.

## Milestone 2: shipments, events, evidence (done)

- Carriers: list, create, update. Shipments reference a carrier
  optionally. No carrier delete endpoint yet.
- Shipment CRUD with forward-only status transitions
  (draft, planned, in transit, delivered, completed), archiving
  (one-way, archived shipments are read-only), search, filters, sorting,
  pagination.
- CSV import in two steps: preview with column mapping, per-row
  validation errors, and duplicate detection (against the database and
  within the file), then confirm from the server-side preview. Only
  valid, non-duplicate rows are created, and the summary reports what
  happened to every selected row.
- CSV export streaming the filtered list.
- Shipment events: seven system categories (detention, layover, TONU,
  lumper, additional stop, additional miles, other) plus custom
  organization categories. Multiple events per shipment. Duration
  computed from start and end times. Statuses: potential, under review,
  dismissed. Events are potential opportunities only.
- Evidence: upload with type and size validation (20 MB max), private
  local storage in development with S3 configured for production,
  authorized download and inline preview, categorization, verification
  status. No permanent public URLs. Evidence deletion writes a log line;
  the full audit trail arrives in Milestone 4.
- Dashboard extended with shipment, event, and evidence counts.
- Pages: shipments list, create, detail (with events and evidence),
  edit, CSV import flow, evidence library.
- Tests: 51 new backend feature tests (102 total), 21 new frontend
  tests (37 total), production build verified, lint clean.
- Known gaps: no carrier deletion, no unarchive, no evidence link to
  claims yet (claims arrive in Milestone 3), no browser end-to-end tests.

## Milestone 3: claims, readiness, deadlines, PDF (done)

- Claim CRUD with per-organization references (CLM-YYYY-NNNN) and the
  10-status workflow. Transitions are enforced by one service with
  per-transition data rules: submission stamps submitted_at, acceptance
  needs an explicit accepted amount, partial acceptance needs an amount
  below requested, rejection needs a reason, closing early needs a
  reason, and paid is reachable only through payment recording.
- Append-only claim status history (every transition and payment writes
  a row). Evidence attach/detach through a pivot, same-shipment only.
- Requested, accepted, and recovered amounts kept separate. Accepted is
  never defaulted from requested; recovered is set only by payment
  recording. Submitted claims freeze amount, currency, category, and
  event link.
- Evidence requirements per organization (global or per event category,
  required or optional) with seeded illustrative defaults. Live
  readiness calculation (required, collected, missing, percentage,
  status) with snapshots on review and on marking ready. Overrides
  record the reviewer and reason, plus a history entry. Readiness is
  informational only and never certifies a claim.
- Deadlines: customer-supplied only, with timezone and reminder
  schedule. One active row per claim; superseded rows kept. Hourly
  refresh command recomputes upcoming, due soon, overdue, met, and
  missed in the deadline's own timezone. No notification is sent in
  this milestone: the command logs the counts and the API exposes
  them; delivery is Milestone 4 work.
- PDF claim packages: queued generation (dompdf) onto the private disk,
  with organization and shipment info, event details and timeline,
  amounts, an evidence index (originals referenced, not embedded), and
  a footer disclaimer. Authorized download, regeneration, and an
  explicit include-notes option (default off).
- Claim role mapping corrected to the specification: dispatchers do not
  create claims, billing staff do not review claims, operations
  managers submit claims. Two new permissions: `attach-claim-evidence`,
  `manage-evidence-requirements`.
- Pages: claims list, create, detail, edit, review, deadlines overview,
  evidence requirements management. Dashboard extended with claim and
  deadline counts.
- Tests: 54 new backend feature tests (156 total), production build
  verified, lint clean.
- Known gaps: no carrier deletion, no unarchive, no create-category UI
  (all Milestone 2 gaps, unchanged; the create-category UI was added
  in Milestone 5); S3 package download is code-only with no live
  bucket; deadline notifications are not wired yet.

## Milestone 4: audit, notifications, reports (done)

- Append-only audit trail for shipments, events, evidence, claims,
  deadlines, membership, invitations, and package generation. Model
  observers write created/updated/deleted rows with changed fields
  only; the claim transition service writes explicit status and
  payment rows; the package job writes a generation row. A correlation
  id middleware honors or generates `X-Correlation-Id` and records it
  on audit rows. One new permission: `view-audit-logs` (owner, admin,
  operations manager). No update or delete routes exist, and the model
  cancels updates and deletes in code.
- In-app and email notifications with per-member per-event
  preferences, read state, unread count, and dedupe keys so repeated
  runs cannot notify twice. Triggers: claim created, evidence missing
  (once per claim per day), ready for review, submitted, accepted,
  rejected, payment recorded, deadline upcoming and overdue from the
  refresh command (once per deadline per day per type), and invitation
  (existing users only, in-app). Email goes through a queued mailable
  with 3 tries and backoff. No SMS or WhatsApp.
- Reports with explicit financial definitions (see `docs/API.md`):
  potential, submitted, accepted, recovered, rejected, and
  outstanding, every figure grouped by currency and never mixed.
  Summary, recovery history by month, and streaming CSV export, all
  with date filters. The `view-reports` permission (owner, admin,
  operations manager, billing staff, viewer) and the
  `export-reports` permission (owner, admin, operations manager,
  billing staff) are now enforced.
- Full dashboard: opportunity, evidence, readiness, submission,
  approval, rejection, payment, and deadline counts, upcoming
  deadlines, and per-currency financials, with date and status
  filters. The `claims_submitted` count now means status `submitted`
  only; accepted and partially accepted claims are reported under the
  new `claims_approved` count.
- Pages: notifications, reports, audit log, rebuilt dashboard, unread
  badge in the app shell (polled on navigation), and notification
  preferences in profile settings.
- Tests: 40 new backend feature tests (196 total, 765 assertions)
  and 31 new frontend tests (105 total), production build verified,
  lint clean.
- Known gaps: no carrier deletion, no unarchive, no create-category UI
  (all Milestone 2 gaps, unchanged; the create-category UI was added
  in Milestone 5); S3 paths are code-only with no live bucket; no
  browser end-to-end tests.

## Milestone 5: hardening and release (done)

- Fixed the unauthenticated API 500: requests without an
  `Accept: application/json` header returned 500 ("Route [login] not
  defined") because Laravel tried to redirect guests to a login route
  the API-only app does not have. Guests are never redirected now, and
  authentication failures on `/api/*` render as JSON 401. Regression
  tests cover the request with and without the header.
- Audited every frontend id type against the backend and fixed all
  mismatches (UUID strings for carriers, shipments, events,
  categories, evidence, claims, and the rest; integers only for users,
  members, and pivot rows). This fixed two real bugs: the shipment
  form and the event form sent `Number(...)` of a UUID, which is NaN
  and silently dropped the selection. Both forms now send the string.
- Added the create-event-category UI on the shipment detail page
  (closes the Milestone 2 gap), wired to the existing
  `POST /event-categories` endpoint with validation and error states.
- Security review of the whole tree against `docs/SECURITY.md`:
  tenancy, authorization, mass assignment, SQL, files, auth, headers,
  CORS, secrets, error leakage, and audit sanitizing. Two fixes
  landed: a security headers middleware on all API responses, and an
  allowlist guard on the report money-column helper. Remaining items
  are documented in `docs/SECURITY.md`, not silently dropped.
- Scripted end-to-end scenario pass (specification scenarios A to I)
  run live over HTTP against `php artisan serve` with a fresh SQLite
  database: 9 of 9 pass. The runner lives outside the repository (it
  is verification tooling, not product code).
- OpenAPI validation: `docs/openapi.yaml` parses, all 52 `$ref`s
  resolve, and all 59 documented paths match the live route list
  exactly.
- Tests: 4 new backend feature tests (200 total, 783 assertions) and
  5 new frontend tests (110 total). Lint clean, production build
  passes (30 pages).
- Documentation refresh and v1.0.1 release (2026-11-16).

## Phase 1 feature inventory

What v1.0.1 contains, in one place:

- Authentication: register, login, logout, forgot and reset password,
  email verification, profile and password update. Sanctum token auth,
  rate limited.
- Multi-tenant organizations: create, profile, settings, member
  invitations (hashed tokens, 7-day expiry), accept invitation,
  activate, deactivate, and remove members, organization switching.
  Strict data isolation: unknown or foreign organizations return 404.
- Role-based access control: six roles, one central permission map in
  `config/permissions.php`, gates and policies, owner protections.
- Shipments: CRUD, forward-only status transitions, one-way archiving
  with read-only archived shipments, search, filters, sorting,
  pagination, two-step CSV import (preview with per-row errors and
  duplicate detection, confirm from the server-side preview), and
  streaming CSV export.
- Shipment events: seven system categories plus custom organization
  categories (creatable from the UI), duration computed from start and
  end times, potential, under review, and dismissed statuses.
- Evidence: upload with content-based MIME and 20 MB size validation,
  private storage (local disk in development, S3 in production),
  authorized download and inline preview, categorization, verification
  status. No public URLs; storage keys never exposed.
- Claims: CRUD, per-organization references (CLM-YYYY-NNNN) from a
  locked sequence counter, the 10-status workflow enforced by one
  transition service, append-only status history, evidence
  attach/detach (same shipment only), separate requested, accepted,
  and recovered amounts, payment recording as the only route to paid.
- Evidence readiness: per-organization requirements (global or per
  event category, required or optional) with seeded illustrative
  defaults, live readiness calculation with snapshots, overrides that
  record the reviewer and reason. Informational only.
- Deadlines: customer-supplied only, with timezone and reminder
  schedule, one active row per claim, hourly refresh command
  (upcoming, due soon, overdue, met, missed), notifications on due
  soon and overdue with per-day dedupe.
- Claim packages: queued PDF generation (dompdf) with an evidence
  index and timeline, authorized download, regeneration, internal
  notes excluded unless explicitly requested.
- Audit trail: append-only log for shipments, events, evidence,
  claims, deadlines, membership, invitations, and package generation,
  with correlation ids, actor, and changed fields only. Secrets are
  stripped from payloads. No update or delete endpoints.
- Notifications: in-app and email for claim events, evidence gaps,
  deadlines, payments, and invitations, with per-member per-event
  preferences, read state, unread count, and dedupe keys.
- Reports: claims by status and event type, potential, submitted,
  accepted, recovered, rejected, and outstanding value grouped by
  currency (never mixed), recovery history by month, CSV export.
- Dashboard: opportunity, evidence, readiness, submission, approval,
  rejection, payment, and deadline counts, upcoming deadlines, and
  per-currency financials, with date and status filters.
- Frontend: 30 pages behind an app shell with sidebar navigation,
  organization switcher, user menu, and an unread notification badge.
- API: 59 endpoints under `/api/v1`, documented in `docs/API.md` and
  `docs/openapi.yaml`.
- Docker Compose development setup (backend, queue worker, frontend,
  PostgreSQL 16, Redis 7) and Dockerfiles.
- Tests: 200 backend feature tests (783 assertions), 110 frontend
  tests, lint clean, production build passes.

## Known limitations

Deliberate or accepted gaps in v1.0.1. None of these is a secret; each
is either a spec exclusion, a documented follow-up, or a verification
gap.

- No carrier deletion endpoint. Carriers can be listed, created, and
  updated only.
- No unarchive for shipments. Archiving is one-way by design.
- Sanctum tokens never expire by default. Set
  `SANCTUM_TOKEN_EXPIRATION` and add a refresh flow before production.
- The frontend stores the token in `localStorage`, which is readable
  by any script on the page. An httpOnly cookie session (Sanctum SPA
  mode) should be evaluated before production.
- S3 storage paths are code-only: no live bucket was used during
  development, so signed-URL downloads and S3 uploads are untested
  against real S3.
- Docker builds were never run (no Docker on the build machine).
- Migrations were never run against live PostgreSQL; tests and local
  development use SQLite.
- The queue worker was never run against live Redis; local runs use
  the database queue driver.
- Invitation and verification emails were never sent through a real
  mailer; local runs use the log driver.
- The frontend was never exercised in a real browser; there are no
  browser end-to-end tests. Component and validation tests cover the
  UI logic, and the API scenario pass covers the workflows.
- Uploaded files are type-checked by content but not scanned for
  malware.
- No penetration test and no automated dependency scanning are wired
  up yet.
- The privacy policy and terms of service are placeholders marked for
  legal review.
- No backup, retention, or account-deletion procedures exist yet.

## Outstanding bugs

None known as of the v1.0.1 release. The two issues carried from
Milestones 3 and 4 (the unauthenticated API 500 and the frontend id
type mismatches) were both fixed in Milestone 5, and the end-to-end
scenario pass found no product bugs: the three failures during the
pass were all in the verification script itself (invitation token
matching, missing seeder data, and a binary-response parsing bug),
and each was fixed in the script, not the product.

## Explicit exclusions (not in Phase 1)

Subscription billing, Stripe, paid plan enforcement, public pricing
checkout, TMS integrations, EDI, automated broker communication,
WhatsApp, SMS, live GPS tracking, driver mobile apps, automated claim
submission or negotiation, AI agents, AI eligibility decisions, automatic
broker rule discovery, complex analytics, predictive reporting,
multi-currency conversion, white-label, reseller portals, enterprise SSO.
See `docs/ROADMAP.md`.
