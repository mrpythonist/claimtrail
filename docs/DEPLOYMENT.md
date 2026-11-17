# Deployment Guide

How to run ClaimTrail in production. Stack: Laravel 13 (PHP 8.3) backend,
Next.js 16 frontend, PostgreSQL 16, Redis 7, Docker Compose.

> Honest caveat: this guide follows the repo's `docker-compose.yml`,
> Dockerfiles, and `.env.example`, but it has not been run end to end on a
> live server yet. The build VM has no Docker. The first production deploy
> is the verification step for this document; expect small fixes.

## Requirements

- A Linux server (Ubuntu 24.04 works) with at least 2 GB RAM and 20 GB disk.
- Docker Engine + Docker Compose v2.
- A domain name pointing at the server (for the app and the API).
- An SMTP account for transactional mail (password resets, invitations,
  notifications). The repo default mailer is `log`, which discards mail.

## Services

`docker-compose.yml` defines five services:

| Service  | What it runs |
|----------|--------------|
| `db`     | PostgreSQL 16, data in the `pgdata` volume |
| `redis`  | Redis 7, data in the `redisdata` volume |
| `backend`| Laravel API (PHP-FPM + artisan serve in dev shape; see note below) |
| `queue`  | Laravel queue worker (`queue:work`) |
| `frontend` | Next.js app |

The queue worker processes PDF generation, email notifications, and other
queued jobs. The scheduler runs the deadline refresh command
(`claimtrail:refresh-deadline-statuses`) hourly; it needs the Laravel
scheduler cron entry (below) to fire.

## First deploy

1. Clone the repo and check out the release tag:
   `git clone https://github.com/mrpythonist/claimtrail.git`
2. Copy the environment file and edit it:
   `cp backend/.env.example backend/.env`
3. Set the production values (see "Production environment" below). At a
   minimum: `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `DB_PASSWORD`,
   `REDIS_PASSWORD`, and the `MAIL_*` settings.
4. Build and start: `docker compose up -d --build`
5. Run migrations: `docker compose exec backend php artisan migrate --force`
6. Cache config for production:
   `docker compose exec backend php artisan config:cache`
   `docker compose exec backend php artisan route:cache`
7. Add the scheduler cron entry on the host (runs every minute; Laravel
   decides which scheduled commands are due):
   `* * * * * docker compose -f /path/to/claimtrail/docker-compose.yml exec -T backend php artisan schedule:run >> /dev/null 2>&1`
8. Put a reverse proxy (nginx or Caddy) in front of the frontend and
   backend, and terminate TLS there. The compose file does not include a
   proxy or TLS.
9. Verify: open the frontend, register an account, create an organization,
   create a shipment and a claim, and confirm a PDF package downloads.

## Production environment

Change these from the repo defaults before going live:

- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL` and `FRONTEND_URL` to the real domains (https)
- `APP_KEY`: generate with `php artisan key:generate` (never reuse a dev key)
- `DB_PASSWORD`, `REDIS_PASSWORD`: long random values
- `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` (repo defaults are
  `database`; Redis is the production choice)
- `MAIL_MAILER=smtp` with real `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
  `MAIL_PASSWORD`, and a real `MAIL_FROM_ADDRESS`
- `SANCTUM_TOKEN_EXPIRATION`: set a value in minutes (the default is empty,
  which means tokens never expire)

## Backups

Back up three things, daily at minimum:

1. The database: `docker compose exec -T db pg_dump -U claimtrail claimtrail > backup.sql`
2. The storage volume: evidence files and claim packages live on the
   private disk under the backend container's `storage/` directory.
   Back it up with the container stopped or from a volume snapshot.
3. The `backend/.env` file (store it encrypted, never in git).

Test restores on a spare machine before you need one. Restore testing is a
Phase 2 item (v1.1.3); until it is done, treat backups as unproven.

## Updates and rollback

1. Pull the new tag: `git fetch --tags && git checkout vX.Y.Z`
2. Rebuild and restart: `docker compose up -d --build`
3. Run migrations: `docker compose exec backend php artisan migrate --force`
4. Rebuild the caches (step 6 above).
5. Rollback: check out the previous tag, rebuild, restart. Migrations are
   one-way unless a `down()` method exists and is safe to run; check before
   rolling a migration back.

## Troubleshooting

- **Queue jobs not running:** check the worker with
  `docker compose logs queue`. Confirm `QUEUE_CONNECTION=redis` and that
  the `redis` service is healthy.
- **Deadline statuses not updating:** the scheduler cron entry (step 7) is
  the usual cause. Run the command by hand to check:
  `docker compose exec backend php artisan claimtrail:refresh-deadline-statuses`
- **Mail not arriving:** confirm `MAIL_MAILER=smtp` and the credentials.
  With the default `log` mailer, mail is written to the Laravel log and
  goes nowhere.
- **500 errors after deploy:** check `docker compose logs backend` and
  confirm `APP_KEY` is set and config cache was rebuilt after env changes.

## Known production gaps (as of v1.0.1)

- No health-check endpoint yet (planned in v1.1.1).
- No CSP/HSTS headers; the reverse proxy should add them until the app does.
- No upload malware scanning.
- No backup/restore automation; the steps above are manual.
- Privacy policy and terms of service are placeholders and need legal review.
- See `docs/SECURITY.md` for the full security review and remaining items.
