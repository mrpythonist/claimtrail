# ClaimTrail Operations Runbook

How to tell whether ClaimTrail is healthy, what to do when it is
not, and where the evidence lives. Written for the person running
the deployment, not for end users.

## Health endpoint

`GET /api/v1/health` is public (no authentication) and exists for
load balancers and uptime monitors. It checks four things and
returns one overall status:

| Check | What it does | Failure means |
| --- | --- | --- |
| database | Runs `select 1` | The database is unreachable. Overall status becomes `down` and the endpoint returns 503. |
| cache | Writes and reads a probe key | The cache store is unreachable. Overall status becomes `degraded` (the app still works, slower). |
| queue | Counts pending jobs (database driver only) | Reports `skipped` on any other driver. A failed count check degrades the status. |
| storage | Writes, reads, and deletes a probe file on the evidence disk | The evidence disk is unreachable. Overall status becomes `down` and the endpoint returns 503. |

Response shape:

```json
{
  "status": "ok",
  "checks": {
    "database": { "status": "ok", "latency_ms": 2.4, "detail": null },
    "cache": { "status": "ok", "latency_ms": 0.8, "detail": null },
    "queue": { "status": "ok", "latency_ms": 1.1, "detail": "3 pending jobs" },
    "storage": { "status": "ok", "latency_ms": 3.2, "detail": null }
  },
  "checked_at": "2026-11-18T10:00:00+00:00"
}
```

A `detail` string is only present when a check has something to
say (for example the queue check reports the pending count, and a
failed check reports the error message).

The operations page (`/settings/operations`, organization admins
only) shows the same checks plus the application version, PHP and
Laravel versions, and queue totals. The underlying endpoint is
`GET /api/v1/ops/health`.

## Production monitoring (v1.2.3)

The health endpoint answers one question at one moment. The
monitoring check answers it every hour and keeps the answer:

```sh
php artisan monitoring:check
```

It runs the same four health checks, counts failed jobs, and
counts queue depth, then stores the result as a `monitoring_checks`
row. The operations page shows the latest run and the recent
history under "Production monitoring", with a "Run check now"
button. The API is `GET /api/v1/ops/monitoring` (latest + last
24 runs) and `POST /api/v1/ops/monitoring/check` (run now);
both need view-operations (owner and admin). The command is
scheduled hourly; a failing run writes a warning to the log and
exits 1.

A failing check means: look at the operations page first (which
check failed and what its detail says), then the recent errors
below it, then the queue. There is **no alerting channel yet**:
nobody is paged or emailed when a check fails. The failure is
visible on the operations page and in the log, and that is all.
Choosing an alerting channel (email, chat, phone) is a launch
decision; see `docs/PRODUCTION-LAUNCH-CHECKLIST.md`.

## Queue monitoring

`GET /api/v1/ops/queue` reports, per queue, how many jobs are
pending, reserved (picked up by a worker and running), and delayed
(scheduled for later), plus the age in seconds of the oldest
pending job. Totals include the failed-job count.

Queue monitoring only works on the **database** queue driver. On
any other driver the response is `supported: false` with empty
counts. Redis queue monitoring is not implemented.

What to look for:

- **Pending jobs growing and oldest pending age rising**: the
  worker is down or cannot keep up. Check the worker process
  first (`php artisan queue:work` on the database connection),
  then the recent errors (below).
- **Reserved jobs stuck for a long time**: a worker died while a
  job was running. The database driver releases reserved jobs
  after `retry_after` (default 90 seconds); they then run again.
  Claim package generation and notification emails are
  idempotent, so a re-run after a crash does not send twice or
  render twice.
- **Delayed jobs**: normal. Retries are delayed by the backoff
  schedule (see below).

## Failed jobs

When a job exhausts its retries it lands in the `failed_jobs`
table. Organization admins see only their own organization's
failed jobs on the operations page (or `GET
/api/v1/ops/failed-jobs`). A failed job is attributed to an
organization from its payload: notification emails carry the
organization id, claim package jobs resolve it through the
generated document. A job that cannot be attributed to any
organization is never listed and cannot be retried through the
API; inspect it directly in the database if one appears.

Recovery actions:

| Action | Endpoint | Effect |
| --- | --- | --- |
| Retry one | `POST /api/v1/ops/failed-jobs/{uuid}/retry` | Re-queues the original payload and removes the failed row. |
| Forget one | `POST /api/v1/ops/failed-jobs/{uuid}/forget` | Deletes the failed row without retrying. |
| Retry all | `POST /api/v1/ops/failed-jobs/retry-all` | Retries every failed job of the organization. Returns `{ retried, failed }`; `failed` lists UUIDs that could not be re-queued. |
| Flush old | `POST /api/v1/ops/failed-jobs/flush` with `{ "hours": 168 }` | Deletes the organization's failed jobs older than the given hours (default 168, range 1-8760). |

Before retrying, read the exception on the failed job: retrying a
job whose input is permanently broken just fails again. Fix the
underlying cause first (for example a mail service outage or a
missing file), then retry.

## Retries and idempotency

Both queued jobs retry up to 3 times with a 120 second timeout
and give up after 24 hours:

| Job | Backoff between tries |
| --- | --- |
| Claim package generation | 30s, 120s, 600s |
| Notification email | 60s, 300s, 900s |

After the final failure the job logs an error with its ids
("Claim package generation failed after retries." /
"Notification email failed after retries.") and moves to the
failed-jobs table.

Both jobs are idempotent through the `job_executions` table:

- Claim package generation keys on the document id, so a
  redelivered job does not render the PDF twice.
- Notification emails key on the notification id (or a hash of
  the payload for ad-hoc emails), so a redelivered job does not
  send twice.
- A failed run releases its key, so the next retry can claim it.
- A key left `running` for over 60 minutes (a worker that died
  mid-run) can be re-claimed by the next attempt.

## Upload reliability

Evidence uploads store a SHA-256 checksum of the uploaded file
alongside the file. Downloads and previews verify the stored
object against the checksum and return a 500 if the object no
longer matches (for example a partially written file or storage
corruption). Files uploaded before v1.1.1 have no checksum and
skip verification.

If a download fails with a checksum mismatch, the stored object
is corrupt: the user should re-upload the file. There is no
automatic repair.

Orphaned evidence objects (a file on disk with no database row,
left behind by an interrupted upload) are cleaned up weekly by
the scheduled command:

```sh
php artisan claimtrail:cleanup-orphan-evidence [--dry-run]
```

Only objects older than one hour are deleted, so an upload still
in flight is never removed. `--dry-run` lists what would be
deleted without deleting anything.

## Logs

| File | Contents |
| --- | --- |
| `storage/logs/laravel.log` | Default text log (unchanged). |
| `storage/logs/laravel.json.log` | Structured JSON, one object per line. Every record carries `correlation_id`, `app`, and `environment` in `extra`. |
| `storage/logs/errors.json.log` | JSON, error level and above only. The source for the recent-errors list on the operations page. |

Server errors (5xx) are reported with the exception class, file
and line, route, method, user id, organization id, and
correlation id. 4xx responses are not recorded as errors.

Slow queries are logged as warnings ("Slow query detected.")
when a query exceeds `DB_SLOW_LOG_MS` (default 500 ms). The log
entry includes the SQL and the duration; bindings are never
logged.

The recent-errors endpoint reads the local `errors.json.log`
file and is capped at 512 KB per read. On a multi-server
deployment each server only shows its own errors; ship the JSON
log to a central store (or mount a shared log volume) if a
single view is needed.

## Performance notes

- Every API response carries an `X-Response-Time` header
  (for example `12.34ms`), useful when tracing a slow request.
- The event-categories list supports ETags: a client sending
  `If-None-Match` with the current ETag gets a 304 with no body.
- The v1.1.1 indexes cover the hot list queries: queue polling
  (`jobs`: queue, available_at, reserved_at), shipments by
  delivery appointment, and claims by requested amount. If a
  list endpoint is slow, check the slow-query log before adding
  more indexes.

## Backups and restores (v1.1.3)

Backups run on a schedule (daily 02:00, `backup:create`) and can
be started on demand from the Security settings hub or with the
artisan command:

```sh
php artisan backup:create            # all organizations (no attribution)
php artisan backup:create --organization=<uuid>
```

A backup copies the whole database plus every evidence and
package file into `storage/app/private/backups/<timestamp>`,
with a `manifest.json` of SHA-256 checksums, and records a
`backup_runs` row (status, size, checksum). The backup directory
lives on the private disk and is never served publicly. The API
(`GET /backups`, `POST /backups`, `GET /backups/{backup}`, `POST
/backups/{backup}/verify`) is for owners and admins; the file
path is never exposed.

- SQLite: the database file is copied. An in-memory database
  cannot be backed up and fails with a clear message.
- PostgreSQL: the backup shells out to `pg_dump` (custom format).
  `pg_dump` must be installed on the server; if it is missing the
  command fails with a clear message instead of a fake success.
  This path was never run against live PostgreSQL on the
  development VM.

Verify a backup before relying on it: `POST
/backups/{backup}/verify` from the Security settings hub
re-hashes the database copy and every stored file against the
manifest and returns any mismatches. The same check is available
from the command line (v1.2.3) and runs weekly on Sundays at
04:00:

```sh
php artisan backup:verify            # latest completed run
php artisan backup:verify --run=<uuid>
```

Exit code 0 means the backup is intact; 1 means it is missing,
corrupt, or not completed. A failed weekly verification writes
to the log.

Restore procedure (operator action only, no API endpoint):

```sh
php artisan backup:restore <run-id>      # confirms first; --force skips the prompt
```

The command verifies every manifest checksum before touching
live data and refuses on any mismatch, on a failed run, or on an
unknown run id. For SQLite it replaces the database file and
reconnects; for PostgreSQL it shells out to `pg_restore`.
Restoring replaces the live database: confirm the run id, stop
the queue worker first, and take a fresh backup of the current
state before restoring. A successful restore sets
`restored_at` on the run. The PostgreSQL restore path was never
run against live PostgreSQL on the development VM.

Retention for backups themselves is `config/backup.php`
(`keep_days`, default 30), but that value is **not enforced by
any command yet**: backup runs accumulate until an operator
deletes them. A backup retention policy (how long runs are kept,
who deletes them) is an open operator decision; see
`docs/PRODUCTION-LAUNCH-CHECKLIST.md`. Backups are
deployment-wide: a restore restores every organization at once,
so it is not a per-tenant operation.

## Retention (v1.1.3)

Retention defaults live in `config/retention.php` (audit logs
365 days, notifications 90, job executions 30, failed documents
30, security events 180; 0 keeps forever). Owners and admins can
override per organization from the Security settings hub or
`PUT /retention`. The purge runs weekly (Sundays 03:00):

```sh
php artisan retention:purge            # all organizations
php artisan retention:purge --dry-run  # report only, delete nothing
php artisan retention:purge --organization=<uuid>
```

The purge deletes audit logs, notifications, failed generated
documents (with their stored object), and security events older
than the cutoff, per organization, and writes one
`retention.purged` audit row per organization with the counts.
Job executions and unattributed security events (failed logins
have no organization) are purged by the all-organizations run
only. Ready claim packages are never deleted by retention.

## Security checks (v1.1.3)

```sh
php artisan security:check        # table output, exit 1 if any check fails
php artisan security:check --json # machine-readable output
```

The same ten checks are available to owners and admins at
`GET /security/check` and on the Security settings hub: APP_DEBUG
off in production, APP_KEY set, APP_URL HTTPS in production,
session cookies secure in production, Sanctum token expiration
configured, evidence disk private, CORS origin not `*` with
credentials, a completed backup in the last 7 days, a real mail
driver in production, and a real queue driver in production.
Fix every `fail` before a production deploy; a `warn` is
acceptable only with a recorded reason.
