# Development

## Prerequisites

- Docker and Docker Compose (recommended path), or
- PHP 8.3 with the extensions Laravel needs (`mbstring`, `xml`, `curl`,
  `zip`, `sqlite3`, `pgsql`, `bcmath`, `intl`), Composer 2, and Node 24
  with npm.

## Setup with Docker

```sh
cp .env.example .env
# Set APP_KEY in .env. Generate one with:
#   cd backend && php artisan key:generate --show
docker compose up --build
```

Services: `db` (PostgreSQL 16, port 5432), `redis` (Redis 7, port 6379),
`backend` (Laravel, port 8000), `queue` (queue worker), `frontend`
(Next.js dev server, port 3000).

```sh
# Seed demo data (development only)
docker compose exec backend php artisan db:seed

# Run backend tests
docker compose exec backend php artisan test

# Run frontend tests
docker compose exec frontend npm test
```

Note: the backend container mounts `./backend` over the image, so the
container uses the host's `vendor/` directory. Run `composer install` in
`backend/` on the host first if `vendor/` does not exist yet.

Docker builds were not verified on the build machine (no Docker there).
If a build fails, the Dockerfiles and compose file are the first place to
look.

## Setup without Docker

```sh
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Default .env uses SQLite (database/database.sqlite). For PostgreSQL,
# set DB_CONNECTION=pgsql and point DB_HOST at a running server.
php artisan migrate --seed
php artisan serve        # http://localhost:8000

# Frontend (another terminal)
cd frontend
npm install
cp .env.example .env.local
npm run dev              # http://localhost:3000
```

## Demo credentials (development only)

After seeding, one demo organization exists (Acme Trucking Co.) with one
user per role, two carriers, three sample shipments, and two sample
events (detention and lumper on the delivered shipment), plus five
default evidence requirements, two sample claims (one under review, one
potential), and one sample deadline. No evidence rows are seeded: an
evidence record without a real file behind it would be a broken promise.
Upload evidence through the UI or the API instead. No generated
documents are seeded either, for the same reason.
All demo users share the password `password`.

| Role | Email |
| ---- | ----- |
| Owner | owner@demo.claimtrail.test |
| Admin | admin@demo.claimtrail.test |
| Operations Manager | ops@demo.claimtrail.test |
| Dispatcher | dispatcher@demo.claimtrail.test |
| Billing Staff | billing@demo.claimtrail.test |
| Viewer | viewer@demo.claimtrail.test |

Never seed demo data into a production database. Never use these
credentials outside local development.

## Tests

```sh
cd backend && php artisan test     # PHPUnit, SQLite in-memory
cd frontend && npm test            # Vitest (component + validation tests)
cd frontend && npm run lint        # ESLint
cd frontend && npm run build       # Production build with type check
```

Backend tests live in `backend/tests/Feature/`. Frontend tests live next
to the code they cover (`__tests__` directories).

## Useful commands

```sh
# Backend
php artisan migrate              # run migrations
php artisan migrate:fresh --seed # rebuild the dev database with demo data
php artisan queue:work           # process queued mail and claim package jobs locally
php artisan claimtrail:refresh-deadline-statuses   # recompute deadline statuses
php artisan route:list --path=api

# Frontend
npm run dev                      # dev server with hot reload
npm run build && npm run start   # production build and server
```

## Mail in development

`MAIL_MAILER=log` writes emails to `backend/storage/logs/laravel.log`,
including verification links and invitation links with their raw tokens.
That is enough to test every email flow locally without a mail server.
The queue worker (or `QUEUE_CONNECTION=sync`) delivers queued mail.

## Conventions

- Backend: follow Laravel conventions. New API endpoints go in
  `routes/api.php` under `/v1`, with a controller in
  `App\Http\Controllers\Api\V1`, a policy or gate check, and a feature
  test.
- Permissions: add the permission to `App\Permission` and map it in
  `config/permissions.php`. Never compare role names in controllers.
- Tenancy: tenant-owned models use the `BelongsToOrganization` trait and
  carry `organization_id`. Tenant routes use the `organization`
  middleware and read the organization from the request attributes.
- Frontend: compose pages from `src/components/ui/`. Keep data fetching
  in `lib/api.ts` calls. Do not add buttons that do nothing.
- Writing: docs and user-facing copy use plain, direct language. No
  em dashes, no marketing phrases.
- Never commit `.env`, `vendor/`, `node_modules/`, or
  `database/database.sqlite`.
