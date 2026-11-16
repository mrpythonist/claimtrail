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

## Current status: Milestone 1 (foundation)

This repository is at Phase 1, v1.0.1, Milestone 1. What exists today:

- User registration, login, logout, password reset, and email verification
  (Laravel Sanctum token auth for the Next.js frontend).
- Multi-tenant organizations with strict data isolation, member invitations,
  member activation and deactivation, and organization switching.
- Role-based access control with six roles and one central permission map.
- An application shell with sidebar navigation, organization switcher, and
  user menu, plus dashboard, organization settings, members, invitations,
  and profile pages.
- A Docker Compose development setup (backend, frontend, PostgreSQL, Redis,
  queue worker).
- 51 backend feature tests and 16 frontend tests, all passing.

What is not built yet: shipments, events, evidence uploads, claims, evidence
readiness, deadlines, PDF claim packages, audit trail, notifications, and
reports. See `docs/PHASE-1-SCOPE.md` for the full milestone plan.

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
cd backend && php artisan test     # 51 feature tests, SQLite in-memory
cd frontend && npm test            # 16 component and validation tests
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
