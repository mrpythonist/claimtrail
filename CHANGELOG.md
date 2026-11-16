# Changelog

All notable changes to ClaimTrail are documented here. The format follows
Keep a Changelog, and the project follows semantic versioning.

## [1.0.1] - Unreleased

Phase 1, in progress. Milestones 0, 1, 2, and 3 are complete.

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

### Fixed

- Milestone 2: the organization middleware now clears the resolved
  organization at the start of every request, and membership lookups
  bypass the tenant scope, so a stale organization cannot leak into a
  later request in long-running processes or tests.

### Notes

- The original brief specified Laravel 11. `composer create-project`
  installs the current stable release, so the project uses Laravel 13.
- Docker builds were not verified on the build machine (no Docker
  available there). See `docs/DEVELOPMENT.md`.
- The CI workflow is not in the repository yet; workflow files cannot be
  pushed through the GitHub API and must be added through the web UI.
