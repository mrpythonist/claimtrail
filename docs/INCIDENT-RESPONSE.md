# ClaimTrail Incident Response

What to do when something goes wrong: a security event, an
outage, data loss, or a suspected breach. This is the runbook for
the on-call operator. Keep it next to `docs/OPERATIONS.md` and
`docs/SECURITY.md`.

## Severity levels

| Level | Meaning | Examples | Response |
| ----- | ------- | -------- | -------- |
| SEV-1 | Critical: data at risk or service down | suspected breach, data loss, database corruption, full outage | Drop everything. Page the on-call. |
| SEV-2 | Major: one feature broken or degraded | queue stuck, backups failing, a tenant cannot reach their data | Same-day fix. Notify affected tenants. |
| SEV-3 | Minor: a single user or a cosmetic problem | one failed job, one failed email, a slow page | Fix in the normal queue. |
| SEV-4 | Low: no user impact | a warning in `security:check`, a single failed login | Log it and fix it with the next deploy. |

When in doubt, escalate one level. A SEV-1 is declared by the
on-call operator, not by committee.

## First-response steps (any severity)

1. **Confirm it is real.** Check the source: the error log
   (`storage/logs/laravel-errors.log` or the `errors` JSON
   channel), `GET /api/v1/ops/health`, `GET /ops/errors`, and the
   security event log (`GET /security/events` or the Security
   settings hub). A single log line is not an incident; a pattern
   is.
2. **Write it down.** Open a dated entry in the incident log
   (a file in the deployment repo, or the team tracker) with: time
   found, who found it, what is affected, and the severity.
   Everything after this is recorded in the same place.
3. **Contain first, diagnose second.** Stop the bleeding before
   understanding it fully: see Containment below.
4. **Tell the team.** Post in the team channel with the severity
   and the affected area. For SEV-1 and SEV-2, also tell affected
   tenants (see Communication below).

## Containment

- **Suspected breach or stolen credentials:** rotate the
  affected secret immediately (`APP_KEY` rotation invalidates
  all encrypted values — plan for it; revoke the affected
  user's Sanctum tokens with `DELETE /auth/logout` for that
  user or by deleting their tokens in the database). If the
  database credential leaked, rotate it and restart the app.
- **Runaway queue or bad deploy:** stop the queue worker
  (`php artisan queue:pause` or stop the worker process), then
  roll back (see Rollback below).
- **Corrupted or deleted data:** do not run migrations and do
  not write new data. Take a snapshot of the current state first,
  then restore from backup (see Recovery below).
- **A tenant's data visible to another tenant:** treat as SEV-1.
  Disable the affected endpoint (maintenance mode or a route
  block) until the isolation failure is understood. This has
  never happened; the isolation audit test exists to catch it.
- **Suspicious logins:** check `GET /security/events` for
  `login.failed` bursts from one IP. Block the IP at the firewall
  or load balancer. If one account shows repeated failures,
  force a password reset for that account.

## Eradication and recovery

1. **Find the root cause.** Read the logs, the audit trail
   (`GET /audit-logs`), and the security events. Reproduce on a
   copy of the data, never on production.
2. **Fix it.** Deploy the fix, or roll back to the last known
   good version (see Rollback).
3. **Verify the fix.** Run the full test suites, then `php artisan
   security:check` and `php artisan backup:create` followed by a
   verify. Confirm the symptom is gone and the health endpoint is
   green.
4. **Restore data if needed.** See the restore procedure in
   `docs/OPERATIONS.md` (`backup:restore`, checksums verified
   first). Restoring replaces the whole database for every tenant:
   confirm the run id, stop the queue worker, and back up the
   current state before restoring.
5. **Watch.** Monitor the health endpoint, the queue, and the
   error log for at least 24 hours after a SEV-1 or SEV-2.

## Rollback procedure

1. Stop the queue worker.
2. Check out the previous tagged release and deploy it.
3. If the bad deploy included a migration that changed a table,
   roll it back with `php artisan migrate:rollback --step=1`
   only if the migration is reversible and no data written by
   the bad version would be lost. When in doubt, restore from
   backup instead of rolling back a migration.
4. Restart the queue worker and confirm the health endpoint is
   green.
5. Record the rollback in the incident log.

## Data-breach steps

A breach means customer data was accessed, copied, or exposed by
someone who should not have it. This is always SEV-1.

1. **Contain** (above): rotate credentials, block the attacker,
   disable the affected endpoint.
2. **Scope it.** From the audit trail and security events, list
   exactly what was accessed: which organizations, which
   records, and when. Preserve the logs — do not delete or rotate
   them during an investigation.
3. **Notify.** Tell affected tenants what was accessed, when,
   and what you are doing about it. Use the communication
   template below. Notify within 72 hours of confirming a breach
   involving personal data; sooner is better.
4. **Report.** File the incident with the relevant regulator if
   the affected data requires it (check with legal; the privacy
   policy and terms are still placeholders pending legal review —
   see `docs/SECURITY.md`).
5. **Review.** After recovery, write a post-incident review: what
   happened, why, what changed (tests, checks, runbooks), and
   who owns each follow-up.

## Communication templates

**Tenant notice (SEV-1/SEV-2, during the incident):**

> Subject: ClaimTrail incident — [brief description]
>
> We are investigating an incident affecting [area]. It started
> at [time, with timezone]. [What users may see]. We will update
> you within [interval]. No action is needed from you right now.
> — The ClaimTrail team

**Tenant notice (after resolution):**

> Subject: ClaimTrail incident resolved — [brief description]
>
> The incident affecting [area] is resolved as of [time]. The
> cause was [one sentence]. [What changed to prevent a repeat].
> If you saw anything unusual, reply to this email.

**Internal update (team channel):**

> [SEV-n] [area] — [one-line status]. Started [time]. Owner:
> [name]. Next update in [interval].

## On-call

- **On-call operator:** [name, phone, email — fill in before
  production]
- **Escalation:** [name, phone, email — fill in before
  production]
- **Security contact:** [email — fill in before a public launch;
  see `docs/SECURITY.md`]

Until these are filled in, the maintainer is the on-call
operator. Do not ship to production with the placeholders still
in place.

## Useful commands

```sh
php artisan security:check            # production-readiness checks
php artisan backup:create             # backup now
php artisan backup:restore <run-id>   # restore (verifies checksums first)
php artisan retention:purge --dry-run # what retention would delete
php artisan queue:retry <id>          # retry one failed job
php artisan queue:retry --all         # retry all failed jobs
```
