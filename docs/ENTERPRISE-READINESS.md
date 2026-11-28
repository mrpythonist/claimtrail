# Enterprise Readiness (v2.0.3)

The last version of Phase 6 (Intelligent Automation). Enterprise
readiness is the tooling a larger customer asks for before they
trust the app with their operation: audit controls, security
policies, retention controls, disaster recovery drills, load
tests, monitoring and alerting, service-level objectives, and a
security self-assessment. Everything here is gated by the
`manage-enterprise` permission (owner, admin, operations
manager) and the Growth plan or better: the Free and Starter
plans are refused with a 402 on every enterprise endpoint.

The frontend surface is the `/enterprise` page, with tabs for
Overview, Audit, Security policies, Retention, Disaster
recovery, Monitoring, Load tests, and Assessment.

## What is measured, what is configured

The distinction matters, and every endpoint and page states it:

- **Measured** values come from this instance's own recorded
  data: the audit rows, the monitoring check history, the local
  error log, the queue and failed-job tables, the backup run
  history, and the evidence disk. They carry their sample size,
  or report that there is not enough data yet.
- **Configured** values are objectives and defaults an owner
  sets: SLO targets, RPO/RTO, alert thresholds, retention
  days, security policy settings. A configured target is not a
  guarantee and not a measured result.

## Advanced audit controls

The audit trail was already append-only (v1.0.1): the model
cancels updates and deletes, the table has no `updated_at`
column, and there are no update or delete API routes. v2.0.3
adds the export:

- `GET /audit-logs/export?format=csv|json` with the same
  filters as the list endpoint (`entity_type`, `action`,
  `actor_id`, `date_from`, `date_to`), capped at 5,000 rows.
  CSV is a file download (`audit-logs-YYYY-MM-DD.csv`); JSON
  returns the rows in the standard envelope.
- The export needs `manage-enterprise` and Growth or better.
  The plain list stays under `view-audit-logs` for every plan.

The only sanctioned deletion path for audit rows remains the
retention purge (below), which writes one `retention.purged`
audit row with the counts after the deletions, so the purge
itself is on the record.

## Security policies

`GET /security/policies` and `PUT /security/policies`. One
policy row per organization; until a row exists the config
defaults apply (minimum length 8, no number or symbol
requirement, 120-minute session timeout, no concurrent-session
limit, no IP ranges, two-factor not required).

What each setting can and cannot do — the API returns these
notes with the policy, so no consumer has to guess:

- **Password rules (enforced).** The minimum length and the
  number/symbol requirements are enforced on password change
  (`PUT /auth/password`) and on password reset
  (`POST /auth/reset-password`, against the strictest policy
  across the user's organizations, checked before the reset
  token is consumed). A failing password is refused with a
  422 listing the reasons. `POST /security/policies/check-password`
  checks a password without changing anything.
- **Allowed IP ranges (check-only).** `POST /security/policies/check-ip`
  reports whether an address is inside the ranges (IPv4 CIDR
  and single addresses; IPv6 matches exact strings only).
  Nothing at the edge enforces the ranges: an address outside
  them can still sign in. Enforcing them would need edge or
  middleware work that is not built.
- **Two-factor (policy only).** `require_two_factor` can be
  set, but no second factor exists yet, so it changes nothing
  at login.
- **Session timeout and max concurrent sessions (recorded).**
  Both are stored and reported; the session lifetime itself
  stays a deployment setting, and concurrent sessions are not
  limited by the app yet.

## Data retention controls

The v1.1.3 retention machinery is unchanged: per-kind
retention in days (config defaults, overridable per
organization through `PUT /retention`), 0 keeps forever, and
the weekly purge (`retention:purge`, Sundays 03:00). v2.0.3
adds the controls around it:

- `GET /retention/preview`: the effective settings plus, per
  kind, how many rows the purge would delete and the cutoff
  it would use. Nothing is deleted by a preview. The
  `job_executions` kind is counted globally, because the
  scheduled run purges it globally, not per organization.
- `POST /retention/purge` with `dry_run` (default true):
  run the purge for this organization now. A real purge
  deletes the expired rows and writes one `retention.purged`
  audit row with the counts, the same as the scheduled run.

Ready claim packages are never purged: only failed generated
documents are deleted, with their stored object when one
exists.

## Disaster recovery

RPO and RTO are configured objectives from
`config/enterprise.php` (defaults: RPO 24 hours, RTO 4 hours),
not measured values. The daily backup schedule (02:00) is what
makes the 24-hour RPO plausible; the 4-hour RTO is a target
for the restore drill, not a timed production restore.

- `GET /enterprise/disaster-recovery`: the objectives, the
  latest completed backup (the organization's own run, else
  the latest scheduled run), the last verified backup (from
  the drill history), the latest drill, and the drill count.
- `GET /enterprise/disaster-recovery/drills`: drill history,
  newest first.
- `POST /enterprise/disaster-recovery/drills` with
  `kind: verify | restore_dry_run` and an optional
  `backup_run_id` (default: the latest completed backup).
  - **verify** re-hashes the backup against its manifest
    (the same check as `backup:verify`).
  - **restore_dry_run** verifies first, then copies the
    backup's SQLite database to a temporary file, opens it
    read-only, and counts its rows per table, then deletes
    the temporary file. The live database is never touched.
  - PostgreSQL backups cannot be dry-run restored here: that
    needs a live server and `pg_restore`, so the drill records
    a failure with the reason instead of pretending.
- `dr:drill --kind=verify|restore_dry_run` is the same drill
  from the console.

There is no failover. No standby host exists, no traffic can
move anywhere, and a drill proves a backup can be read back —
nothing more. The runbook below is the procedure for a real
loss; the restore step is the operator action
`backup:restore`, which replaces the live database and is
deliberately not exposed through the API.

### DR runbook

1. **Confirm the loss.** Check the operations health endpoint
   and the latest monitoring check. If the database file is
   gone or unreadable, proceed; if only the app is down, fix
   the app first — a restore does not fix a broken deploy.
2. **Pick the backup.** List backup runs (`GET /backups`) and
   take the latest completed one. If its checksum is unknown,
   run `backup:verify --run=<id>` (or a verify drill) before
   trusting it.
3. **Prove it reads back.** Run a restore dry-run drill
   (`POST /enterprise/disaster-recovery/drills` with
   `kind: restore_dry_run`, or `dr:drill
   --kind=restore_dry_run`). It must complete with a row
   count for every table. If it fails, take the previous
   completed backup and drill that one.
4. **Stop the app.** Nothing may write while the restore
   runs: stop the queue worker and the web server.
5. **Restore.** Run `backup:restore --run=<id>` from the
   console. It re-verifies every checksum first and refuses
   on any mismatch, leaving the live data untouched.
6. **Verify.** Start the app, check the operations health
   endpoint, and confirm the claim and shipment counts match
   the drill's row counts. Check the audit trail for the
   latest rows before the loss.
7. **Record it.** Write what happened, which backup was used,
   and how long the restore took in the incident log. The
   RTO target is 4 hours; the actual time is whatever it took,
   and it is the number that improves the next drill.

## Load testing

`POST /enterprise/load-tests` runs a bounded local synthetic
load pass: N sequential in-process requests through the HTTP
kernel (at most 200, default 50), against the public health
endpoint (`target: health`) or the authenticated shipment list
with the caller's own credentials (`target: shipments`). The
run stores the timing summary: total, success and error
counts, average, p95 and max latency, and requests per second.
`GET /enterprise/load-tests` lists past runs. `load:test`
is the same pass from the console (health target only;
`--requests`, `--organization`).

What it is not: a production load test. Requests run one
after another (concurrency 1), on this machine, against this
instance, with no real traffic mix. It measures the app's
local response shape and catches gross regressions; it says
nothing about production capacity. No external service is
ever touched.

## Monitoring and alerting

Alert rules watch one local metric each, measured from this
instance's own data:

| Metric | Measured from |
| ------ | ------------- |
| `queue_depth` | Pending jobs in the database queue |
| `failed_jobs` | Failed jobs attributed to the organization |
| `error_rate` | 5xx errors in the local error log, as a percentage of (errors + hourly health probes) in the window |
| `disk_usage_percent` | Used share of the evidence disk |
| `backup_age_hours` | Hours since the latest completed backup (the organization's own, else the latest scheduled one) |

The error rate deserves its definition in full, because the
denominator is unusual: the app records no per-request
counter, so the rate is errors divided by (errors + health
probes) in the window. Zero errors reads 0%. It is a
probe-relative rate, not a request rate, and the doc states
that wherever the number appears.

- `GET/POST /enterprise/alert-rules`,
  `GET/PUT/DELETE /enterprise/alert-rules/{rule}`:
  create, read, change, and delete rules. Operator `gt` or
  `gte`; `window_minutes` is the lookback for windowed
  metrics; `enabled: false` pauses evaluation without
  deleting the rule; `severity` is `warning` or `critical`.
  Deleting a rule keeps its alerts (their rule id becomes
  null).
- `POST /enterprise/alert-rules/{rule}/evaluate`: evaluate
  one rule now, outside the hourly schedule. Returns the
  observed value, whether it breached, and the alert when
  one was recorded. A metric that cannot be measured (no
  backup yet, unreadable disk) reports a null observed value
  and does not breach.
- `GET /enterprise/alerts` (filters `status`, `metric`),
  `POST /enterprise/alerts/{alert}/acknowledge`,
  `POST /enterprise/alerts/{alert}/resolve`.

A breach opens one active alert per rule. A repeat breach
while one is active updates the observed value and message
instead of opening a second row, and notifies only when the
alert is first opened: the enterprise managers get an
in-app notification (`enterprise.alert_triggered`), the actor
excluded. Resolving an alert lets a later breach open a fresh
one. `alerts:evaluate` runs every enabled rule of every
organization from the console, and is scheduled hourly. A
breach is a finding, not a command failure: the command exits
0 when alerts fire.

## Service-level objectives

`GET /enterprise/slos` returns the three SLOs, seeded from
config on first read, each with its measured value, sample
size, and status. `PUT /enterprise/slos/{key}` changes the
target, the window, or whether the SLO is in force. A disabled
SLO still reports its measurement; the enabled flag is what
says it is not in force.

| SLO | Target (default) | Window | Measured from |
| --- | --------------- | ------ | ------------- |
| Availability | 99.5% | 30 days | Share of monitoring checks in the window with status ok. Sample size is the check count. |
| Error rate | 1.0% | 30 days | Probe-relative error rate (see above). Sample size is errors + probes. |
| Latency (p95) | 500 ms | 7 days | 95th percentile of the per-check latency values stored on the monitoring check rows. Sample size is the latency sample count. |

Monitoring checks are deployment-wide, not per organization,
so every organization reads the same probe history. A
measurement with no underlying data reports null with the
status `insufficient_data`. Status bands: availability is at
risk within 1 point below target; error rate within 0.5
points above; latency within 10% above; beyond that,
breaching. Targets are objectives, not guarantees.

## Scalability improvements

Found by inspection in this version, with the query counts
measured before and after on the missing-evidence endpoint
(`GET /operations/missing-evidence`):

- **Readiness N+1 (fixed).** `ReadinessService::compute()`
  issued three queries per claim — the requirements lookup,
  the attached-evidence pluck, and the snapshot read — so the
  endpoint's query count grew with the claim count: 26
  queries for 5 claims, 41 for 10. The requirements are now
  cached per event category for the life of the service
  instance, and `compute()` reuses the eager-loaded evidence
  and snapshot relations when the caller loaded them (the
  operations endpoint now loads `requirementSnapshots` too).
  The count is constant: 12 queries for 5 claims and 12 for
  10. Pinned by `tests/Feature/ScalabilityTest.php`, which
  also pins that the returned numbers are unchanged.
- **Index audit.** The hot tables were checked: audit logs,
  claims, shipments, shipment events, evidence requirements,
  claim requirements, claim status histories, claim payments,
  reconciliation reports, security events, notifications, and
  monitoring checks all already carry the indexes their
  queries need. No index was added in this version, because
  none was missing.

## Enterprise security assessment

`POST /enterprise/assessments` generates and stores a
self-assessment; `GET /enterprise/assessments/latest` returns
the latest, `GET /enterprise/assessments` the history.
`enterprise:assess` is the same generation from the console.

The report runs the ten production-readiness checks
(`SecurityCheckService`) plus six configuration checks:
permission coverage (every permission assigned to at least
one role), audit trail completeness (recent audit rows for
the organization), retention configured, backup freshness,
alert rules configured, and SLOs configured. Each finding
carries an id, label, status (`pass`, `warn`, `fail`, `open`),
detail, and category.

This is a **self-assessment, not a penetration test and not
an external audit**. The standing unverified items are
recorded as open findings in every report:

- Live PostgreSQL verification
- Live Redis verification
- Docker verification
- Browser end-to-end tests
- Real mailer verification
- Penetration test
- Dependency audit

`open_findings` counts findings with status `fail` or
`open`. The overall result is `fail` when any check failed,
`warn` when any check warned or any open finding stands, and
`pass` only when nothing warns, nothing fails, and nothing
stands open — which, while the seven items above are open,
means the overall result is never `pass`. That is deliberate:
the report must never be read as a clean bill of health.

## What is still not verified

The standing blockers, unchanged by this version: live
PostgreSQL, live Redis, Docker, browser E2E, and a real
mailer. v2.0.3-specific limits:

- No real failover exists; the disaster recovery drills prove
  a backup can be read back.
- Load tests are local and synthetic (sequential, at most 200
  requests, in-process); they are not production load tests.
- The error rate (alerts and SLOs) is probe-relative, not a
  request rate, because the app records no per-request
  counter.
- IP ranges are check-only and two-factor is policy-only (see
  Security policies).
- The assessment is a self-assessment, not a pen test or an
  external audit.
- Monitoring checks are deployment-wide; the availability and
  latency SLOs read the same probe history for every
  organization.
