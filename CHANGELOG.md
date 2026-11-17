# Changelog

All notable changes to ClaimTrail are documented here. The format follows
Keep a Changelog, and the project follows semantic versioning.

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
