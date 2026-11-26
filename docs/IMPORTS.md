# Advanced Imports

Saved CSV mappings, import history with row-level error
correction, scheduled imports, import notifications, and
synchronization workflows. Imports target shipments only.
See docs/API.md for the endpoint table and
docs/openapi.yaml for the machine-readable contract (tag
`Imports`, 20 operations).

## Saved CSV mappings

A mapping names exact CSV header text and the shipment field
each header fills, for example:

```json
{
  "name": "Broker CSV",
  "target": "shipments",
  "mapping": {
    "Load Ref": "internal_reference",
    "Broker": "broker_or_customer_name",
    "External Ref": "external_load_reference"
  },
  "is_default": true
}
```

Every target field must be one a mapping may target: the
shipment fields from the importer's header aliases
(`internal_reference`, `external_load_reference`,
`broker_or_customer_name`, `driver_name`, `driver_contact`,
`pickup_location`, `delivery_location`,
`pickup_appointment`, `delivery_appointment`,
`actual_arrival_at`, the `carrier` pseudo-field that resolves
a carrier by name) plus `carrier_id`. An unknown field is
refused with 422. Names are unique per organization.

Only one mapping per target is the default. Saving or
updating a mapping with `is_default: true` unsets the
previous default for that target. A mapping is used by
passing its id as `mapping_id` to the shipment import
preview and confirm endpoints (`POST
/shipments/import/preview`, `POST /shipments/import/confirm`),
or by attaching it to a schedule or a sync workflow. A
mapping with no `mapping_id` on the request falls back to
the built-in header aliases, which only cover the standard
header spellings; a header like "Load Ref" only works
through a saved mapping. Deleting a mapping nulls the link
on runs, schedules, and sync workflows that used it; the
runs stay in the history.

Managing mappings needs the `manage-imports` permission
(owner, admin, operations manager, dispatcher). Any member
may list or read mappings.

## Import history

Every import records an import run, whatever started it:
an upload confirm (`source: upload`), a schedule
(`source: schedule`), or a sync workflow (`source: sync`).
`GET /imports/runs` lists runs newest first, filterable by
`status` (`pending`, `running`, `completed`, `partial`,
`failed`) and `source`. `GET /imports/runs/{run}` returns
the run with its rows. `GET /imports/runs/{run}/rows`
lists rows in file order, filterable by row `status`
(`imported`, `skipped`, `duplicate`, `failed`, `corrected`).

Run status is computed from the rows:

- `completed`: every row imported.
- `partial`: at least one row imported and at least one
  row not imported.
- `failed`: no row imported (including a file whose rows
  are all duplicates, and a file that never parsed).

The counts on a run are `total_rows`, `imported_rows`,
`skipped_rows`, `duplicate_rows`, and `error_rows`.
Corrected rows count as imported. A run that never parsed
(for example a file without the required columns) has
`total_rows: 0` and the reason in `error_message`.

## Duplicate detection

A row is a duplicate when its `internal_reference` or its
`external_load_reference` already exists in the
organization, either in the database or earlier in the same
file. Duplicate rows are recorded with status `duplicate`
and the reference that collided, and no shipment is
created for them. Re-importing the same file therefore
imports nothing and records a failed run with all rows
duplicate. Duplicate detection is exact match only; there
is no fuzzy matching.

## Error correction

A row that fails validation is stored with status
`skipped` and its field errors (for example a missing
broker name). A row whose correction still fails validation
is stored with status `failed`. `POST
/imports/runs/{run}/rows/{row}/correct` takes `data`, a set
of field values merged over the stored row data, and
retries the row: the merged data is re-validated with the
importer's rules and the reference is re-checked against
the database. A valid row creates the shipment, becomes
`corrected`, and the run counts refresh (the corrected row
counts as imported, and a run whose last failed row was
corrected flips to `completed`). A correction that still
fails stores the new values and errors and returns 422. A
row that already imported or was already corrected cannot
be corrected again (422). Correcting rows needs
`manage-imports`.

## Scheduled imports

A schedule is a named stored file plus a saved mapping
(optional) and a frequency: `daily`, `weekly`, or
`manual`. The file is uploaded once with the schedule
(CSV or TXT, max 5120 KB) and stored on the private disk
under `imports/{organization}/schedules/{uuid}.csv`; it is
re-run as-is. There is no live SFTP, URL, or provider
source: a schedule re-imports a file the organization
already uploaded. `POST /imports/schedules/{schedule}/run`
runs it on demand and returns the import run. The artisan
command `imports:run-scheduled` runs due schedules (daily
or weekly, active, `next_run_at` reached) and is scheduled
in `routes/console.php` at 04:30 daily. It takes
`--schedule` (one schedule now) and `--organization`
(UUID or slug). A daily schedule advances `next_run_at`
by one day after each run, a weekly schedule by one week;
a manual schedule has no `next_run_at` and only runs on
demand. When `next_run_at` is omitted at creation, daily
defaults to tomorrow and weekly to next week. The command
runs as the organization's owner. Deleting a schedule
deletes its stored file; runs it produced stay in the
history. Managing schedules needs `manage-imports`.

## Import notifications

When a run finishes, every member who can manage imports
except the actor gets an in-app notification:
`import.completed` when the run completed or partially
imported, `import.failed` when it failed. The notification
names the file and the counts (imported, duplicates,
errors). Notifications appear in `GET /notifications`
and count toward the unread count. A single-member
organization has no one else to notify, so no
notification is created for its own runs.

## Synchronization workflows

A sync workflow is a named stored file plus a saved
mapping (optional). `POST /imports/sync-runs/{syncRun}/run`
moves it through `running` to `completed` or `failed`
and links the import run that did the work
(`import_run_id`, plus `summary` and `error_message` on
the workflow). The file is stored on the private disk
under `imports/{organization}/sync/{uuid}.csv` (CSV or
TXT, max 5120 KB). Deleting a workflow deletes its stored
file; runs it produced stay in the history. Managing sync
workflows needs `manage-imports`.

A sync workflow is not a live connection to another
system. No TMS or other external system is involved, and
no data is pulled from anywhere: it re-imports a stored
file with a saved mapping, the same as a scheduled import,
but with workflow states instead of a calendar. TMS
integrations are v1.3.2.

## What is not built yet

- No live source for schedules or sync workflows: no
  SFTP, no URL fetch, no email-attachment pickup, no
  provider API.
- No TMS integration (v1.3.2).
- No per-mapping or per-schedule plan limits: imports
  are not metered against any plan.
- No reprocess endpoint for a failed run: correct the
  rows, or re-upload the file.
- No import of claims, carriers, or evidence: shipments
  only.
- Duplicate detection is exact reference match only.
