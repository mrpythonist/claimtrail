# ClaimTrail

Every shipment. Every entitlement. Every dollar accounted for.

ClaimTrail is a multi-tenant SaaS product for trucking carriers, freight
dispatching agencies, and transportation operations teams. It helps them
identify potentially recoverable additional shipment charges, capture the
evidence needed to support those charges, manage submission deadlines,
generate claim documentation, and track financial recovery.

ClaimTrail never guarantees that a charge is recoverable. Contractual
eligibility and submission requirements are configured and verified by the
customer.

## Current status: Phase 1 complete (v1.0.1)

This repository is at Phase 1, v1.0.1. All six milestones (0 to 5) are
complete. What exists today:

- User registration, login, logout, password reset, and email verification
  (Laravel Sanctum token auth for the Next.js frontend).
- Multi-tenant organizations with strict data isolation, member invitations,
  member activation and deactivation, and organization switching.
- Role-based access control with six roles and one central permission map.
- Shipments: full CRUD, forward-only status transitions, archiving, search,
  filters, sorting, pagination, CSV import with preview and duplicate
  detection, and CSV export.
- Shipment events: the seven system categories plus organization custom
  categories (creatable from the shipment detail page), duration
  computed from start and end times, and review statuses. Events are
  potential opportunities only, never automatic qualifications.
- Evidence: secure upload with type and size validation, private storage,
  authorized download and inline preview, categorization, and verification
  status.
- Claims: full CRUD with per-organization references (CLM-YYYY-NNNN), the
  10-status workflow with controlled transitions, append-only status
  history, evidence attach/detach, and separate requested, accepted, and
  recovered amounts. Payment is recorded separately from acceptance.
- Evidence readiness: configurable per-organization requirements,
  live readiness calculation with snapshots, and overrides that record
  the reviewer and reason. Readiness is informational only and never
  certifies a claim.
- Deadlines: customer-supplied submission deadlines with timezones and
  reminder schedules, recomputed hourly (upcoming, due soon, overdue,
  met, missed). Deadlines are never inferred from event types.
- Claim packages: queued PDF generation (dompdf) with an evidence index
  and timeline, authorized download, and regeneration. Internal notes
  are excluded unless explicitly requested.
- Audit trail: append-only audit log for shipments, events, evidence,
  claims, deadlines, membership, invitations, and package generation,
  with correlation ids, actor (user or system), and before/after
  values for changed fields only. No update or delete endpoints exist.
- Notifications: in-app and email notifications for claim events,
  evidence gaps, deadlines, payments, and invitations, with per-member
  per-event preferences, read state, and dedupe keys so a repeated job
  run cannot notify twice about the same event.
- Reports: claims by status and event type, potential, submitted,
  accepted, recovered, rejected, and outstanding value grouped by
  currency (never mixed), recovery history by month, and CSV export.
- Full dashboard: opportunity, evidence, readiness, submission, and
  deadline counts, upcoming deadlines, and per-currency financials,
  with date and status filters. Potential money is labeled estimated,
  never recovered.
- An application shell with sidebar navigation, organization switcher,
  user menu, and an unread notification badge, plus dashboard,
  shipments, claims, deadlines, evidence library, notifications,
  reports, audit log, organization settings, members, invitations, and
  profile pages.
- A Docker Compose development setup (backend, frontend, PostgreSQL, Redis,
  queue worker).
- Security headers on API responses, and JSON 401 responses for
  unauthenticated API requests with or without a JSON Accept header.
- 200 backend feature tests (783 assertions) and 110 frontend tests,
  all passing, plus a scripted end-to-end scenario pass (scenarios A
  to I from the specification) run live over HTTP against a fresh
  database: 9 of 9 pass.

Not verified on the build machine (no Docker, PostgreSQL, Redis, or
browser was available there): Docker builds, migrations against live
PostgreSQL, the queue worker against live Redis, S3 storage paths,
invitation and verification emails through a real mailer, and the
frontend in a real browser. See `docs/PHASE-1-SCOPE.md` for the full
milestone plan, the feature inventory, and the known limitations.

## Stack

- Frontend: Next.js 16 (App Router), React 19, TypeScript, Tailwind CSS 4.
- Backend: Laravel 13, PHP 8.3, Sanctum token authentication.
- Database: PostgreSQL 16 (SQLite for local development and tests).
- Queue and cache: Redis 7 (database queue and cache drivers in development).
- Infrastructure: Docker and Docker Compose.

## Quickstart

Prerequisites: Docker and Docker Compose, or PHP 8.3 with Composer and
Node 24 with npm for a local setup. Full instructions are in
`docs/DEVELOPMENT.md`.

```sh
# Docker (recommended)
cp .env.example .env
# Set APP_KEY in .env (see the comment in the file)
docker compose up --build
# In another terminal:
docker compose exec backend php artisan db:seed   # demo data, development only
```

- Frontend: http://localhost:3000
- Backend API: http://localhost:8000/api/v1
- Demo login (after seeding): `owner@demo.claimtrail.test` / `password`

```sh
# Local, without Docker
cd backend && composer install && cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # uses SQLite by default in .env
php artisan serve            # http://localhost:8000

cd frontend && npm install && cp .env.example .env.local
npm run dev                  # http://localhost:3000
```

## Documentation

- `docs/ARCHITECTURE.md`: system layout, module boundaries, request flow.
- `docs/DATABASE.md`: schema for the tables that exist today.
- `docs/API.md`: REST endpoints, auth, tenancy headers, error format.
- `docs/openapi.yaml`: machine-readable API definition for the same endpoints.
- `docs/SECURITY.md`: controls in place and what still needs review.
- `docs/DEVELOPMENT.md`: setup, demo credentials, test commands, conventions.
- `docs/PHASE-1-SCOPE.md`: the 14 Phase 1 modules and their status.
- `docs/ROADMAP.md`: what comes after Phase 1 (not implemented).
- `CHANGELOG.md`: release notes.

## Tests

```sh
cd backend && php artisan test     # 200 feature tests, SQLite in-memory
cd frontend && npm test            # 110 component and validation tests
cd frontend && npm run build       # production build, includes type check
```

## Repository layout

```text
backend/        Laravel API (app, config, database, routes, tests)
frontend/       Next.js application (src/app, src/components, src/lib)
docs/           Markdown documentation and the OpenAPI definition
docker-compose.yml
backend/Dockerfile
frontend/Dockerfile
```

## License

Proprietary. All rights reserved.
