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
