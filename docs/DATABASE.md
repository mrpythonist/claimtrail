# Database

PostgreSQL is the production database. SQLite is used for local development
without Docker and for the test suite (in-memory). Migrations are written
to run on both. PostgreSQL-specific features are avoided in Milestone 1 so
the two stay compatible.

## Tables in Milestone 1

### users (Laravel default)

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | bigint, PK | Auto-increment |
| name | string | |
| email | string, unique | |
| email_verified_at | timestamp, nullable | |
| password | string | bcrypt hashed |
| remember_token | string, nullable | |
| created_at, updated_at | timestamps | |

### personal_access_tokens (Sanctum default)

Sanctum API tokens. One row per login session. Revoked on logout.

### organizations

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | Public identifier, used in URLs and headers |
| name | string | |
| slug | string, unique, indexed | URL-safe, random suffix to avoid collisions |
| timezone | string, default `UTC` | IANA timezone |
| settings | json, nullable | Organization settings (reserved for later use) |
| onboarding_dismissed_at | timestamp, nullable | Set when a manager dismisses the guided onboarding checklist (v1.1.0) |
| created_at, updated_at | timestamps | |

### organization_members

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | bigint, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| user_id | bigint, FK to users, cascade on delete | |
| role | string | One of the six `OrganizationRole` values |
| status | string, default `active` | `active` or `inactive` |
| invited_by | bigint, FK to users, nullable, null on delete | |
| joined_at | timestamp, nullable | |
| created_at, updated_at | timestamps | |

Constraints and indexes:

- Unique `(organization_id, user_id)`: a user has at most one membership
  per organization.
- Index `(organization_id, status)` for member listings.
- Index `(user_id, status)` for "my organizations" lookups.

### organization_invitations

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| email | string, indexed | Lowercased before storage |
| role | string | Role granted on acceptance |
| token | string(64), unique | SHA-256 hash of the raw token; the raw token only exists in the email |
| expires_at | timestamp | 7 days after creation |
| accepted_at | timestamp, nullable | Set on acceptance |
| invited_by | bigint, FK to users, nullable, null on delete | |
| created_at, updated_at | timestamps | |

Constraints and indexes:

- Unique `(organization_id, email)`: one invitation row per email per
  organization. Accepting marks the row; sending a fresh invite after an
  accepted one deletes the old accepted row first.
- Index `(organization_id, accepted_at)` for pending invitation listings.

### password_reset_tokens, cache, jobs (Laravel defaults)

Standard Laravel tables. `jobs` backs the database queue driver used in
development (invitation and verification emails are queued).

## Tables in Milestone 2

### carriers

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| name | string | Required |
| contact_name | string, nullable | |
| contact_email | string, nullable | |
| contact_phone | string, nullable | |
| dot_number | string, nullable | US DOT number |
| notes | text, nullable | |
| created_at, updated_at | timestamps | |

Index `(organization_id, name)` for carrier listings and search.

### event_categories

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, nullable, cascade on delete | Null means a system category, visible to every organization |
| slug | string | URL-safe, from the label |
| label | string | Display name |
| description | text, nullable | |
| created_at, updated_at | timestamps | |

Unique `(organization_id, slug)`. The seven system categories (detention,
layover, tonu, lumper, additional_stop, additional_miles, other) are
seeded by `EventCategorySeeder`. Note: SQLite and PostgreSQL both treat
nulls as distinct in unique indexes, so system slugs are protected by
the seeder using `firstOrCreate`, not by the constraint.

### shipments

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| internal_reference | string | Required, unique per organization |
| external_load_reference | string, nullable | Broker load number |
| broker_or_customer_name | string | Required |
| carrier_id | uuid, FK to carriers, nullable, null on delete | |
| driver_name | string, nullable | |
| driver_contact | string, nullable | |
| pickup_location | string, nullable | |
| delivery_location | string, nullable | |
| pickup_appointment | datetime, nullable | |
| delivery_appointment | datetime, nullable | |
| actual_arrival_at | datetime, nullable | |
| actual_departure_at | datetime, nullable | |
| base_rate | decimal(12,2), nullable | Agreed base rate, never a float |
| currency | string(3), default `USD` | ISO currency code |
| status | string, default `draft` | `draft`, `planned`, `in_transit`, `delivered`, `completed`, `archived` |
| notes | text, nullable | |
| created_by | bigint, FK to users, nullable, null on delete | |
| created_at, updated_at | timestamps | |

Constraints and indexes:

- Unique `(organization_id, internal_reference)`.
- Index `(organization_id, status)` for status filters.
- Index `(organization_id, pickup_appointment)` for date range filters.
- Index `(organization_id, carrier_id)` for carrier filters.

Status transitions are enforced in the application (`ShipmentStatus`):
forward one step at a time, any non-archived status to archived, and
archived is terminal. The database stores the status as a plain string;
the enum is the authority on allowed values and transitions.

### shipment_events

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| shipment_id | uuid, FK to shipments, cascade on delete | |
| event_category_id | uuid, FK to event_categories, restrict on delete | A category in use cannot be deleted |
| event_date | date | |
| start_time | time, nullable | HH:MM |
| end_time | time, nullable | HH:MM, must be after start_time when both are present |
| duration_minutes | integer, nullable | Computed from start and end when both are present |
| location | string, nullable | |
| description | text, nullable | |
| estimated_amount | decimal(12,2), nullable | Estimated additional amount, never a float |
| currency | string(3), default `USD` | |
| notes | text, nullable | |
| reported_by | bigint, FK to users, nullable, null on delete | |
| status | string, default `potential` | `potential`, `under_review`, `dismissed` |
| created_at, updated_at | timestamps | |

Indexes `(organization_id, shipment_id)`, `(organization_id, event_date)`,
and `(organization_id, status)`.

### evidence_files

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| shipment_id | uuid, FK to shipments, cascade on delete | |
| shipment_event_id | uuid, FK to shipment_events, nullable, null on delete | Deleting an event keeps the evidence on the shipment |
| document_category | string | One of the `EvidenceDocumentCategory` values |
| original_filename | string | Metadata only, never used as a path |
| storage_key | string | Disk path. Never exposed through the API |
| disk | string | `private` locally, `s3` in production |
| mime_type | string | Guessed from file contents at upload |
| file_size | unsigned bigint | Bytes |
| uploaded_by | bigint, FK to users, nullable, null on delete | |
| description | text, nullable | |
| verification_status | string, default `pending` | `pending`, `verified`, `rejected` |
| created_at, updated_at | timestamps | |

Indexes `(organization_id, shipment_id)`,
`(organization_id, shipment_event_id)`,
`(organization_id, document_category)`, and
`(organization_id, verification_status)`.

Evidence links to claims through the `claim_evidence` pivot (Milestone
3), not through a `claim_id` column: one file can support several
claims, which a single foreign key could not represent.

## Tables in Milestone 3

### claim_sequences

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | bigint, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| year | smallint | Calendar year of the sequence |
| last_number | unsigned int, default 0 | Last issued number for that year |
| created_at, updated_at | timestamps | |

Unique `(organization_id, year)`. `ClaimReferenceService` locks the row
(`lockForUpdate`) inside a transaction before incrementing, so two
concurrent claims never share a reference. References look like
`CLM-2026-0001`.

### claims

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_reference | string | Unique per organization, generated |
| shipment_id | uuid, FK to shipments, cascade on delete | |
| shipment_event_id | uuid, FK to shipment_events, nullable, null on delete | |
| claim_category | string | One of the `ClaimCategory` values |
| description | text, nullable | |
| requested_amount | decimal(12,2) | Required, greater than zero |
| currency | string(3), default `USD` | |
| accepted_amount | decimal(12,2), nullable | Set only on acceptance, never defaulted |
| recovered_amount | decimal(12,2), nullable | Actual cash received, set only by payment recording |
| internal_notes | text, nullable | Excluded from claim packages unless explicitly requested |
| external_broker_reference | string, nullable | |
| submission_deadline_at | datetime, nullable | Simple field on the claim; the full deadline record is in `claim_deadlines` |
| submitted_at | timestamp, nullable | Stamped on submission |
| approved_at | timestamp, nullable | Stamped on acceptance |
| rejected_at | timestamp, nullable | Stamped on rejection |
| paid_at | timestamp, nullable | Set by payment recording |
| rejection_reason | text, nullable | Required when rejecting |
| payment_reference | string, nullable | |
| status | string, default `potential` | The ten `ClaimStatus` values |
| created_by | bigint, FK to users, nullable, null on delete | |
| updated_by | bigint, FK to users, nullable, null on delete | |
| created_at, updated_at | timestamps | |

Constraints and indexes:

- Unique `(organization_id, claim_reference)`.
- Index `(organization_id, status)`, `(organization_id, shipment_id)`,
  `(organization_id, claim_category)`.

Transitions are enforced in the application
(`ClaimTransitionService` plus the `ClaimStatus` map). Paid is
reachable only through the record-payment endpoint, never through a
generic status change. The three money columns are kept separate on
purpose: requested is what was asked, accepted is what the broker
agreed, recovered is what was actually paid.

### claim_status_histories

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_id | uuid, FK to claims, cascade on delete | |
| from_status | string, nullable | Null for the first entry of a claim |
| to_status | string | |
| changed_by | bigint, FK to users, nullable, null on delete | |
| reason | text, nullable | |
| metadata | json, nullable | Amounts in effect at the transition |
| created_at, updated_at | timestamps | |

Append-only: no update or delete endpoints exist. Index
`(organization_id, claim_id)` and `(claim_id, created_at)`. Readiness
overrides also write a row here (same from and to status, with an
`action` of `readiness_override` in metadata). The full audit trail in
`audit_logs` (Milestone 4) covers claim status changes too, with the
transition service writing one explicit row per change.

### claim_evidence

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | bigint, PK | |
| claim_id | uuid, FK to claims, cascade on delete | |
| evidence_file_id | uuid, FK to evidence_files, cascade on delete | |
| attached_by | bigint, FK to users, nullable, null on delete | |
| created_at, updated_at | timestamps | |

Unique `(claim_id, evidence_file_id)`. The API additionally requires
the evidence to belong to the claim's shipment.

### evidence_requirements

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| event_category_id | uuid, FK to event_categories, nullable, null on delete | Null means the requirement applies to every event type |
| document_category | string | One of the `EvidenceDocumentCategory` values |
| is_required | boolean, default true | False means optional (informational only) |
| notes | text, nullable | |
| created_by | bigint, FK to users, nullable, null on delete | |
| created_at, updated_at | timestamps | |

Unique `(organization_id, event_category_id, document_category)`.
Because unique indexes treat nulls as distinct, duplicate global
requirements (null category) are prevented in application validation,
not by the constraint. Index `(organization_id, event_category_id)`.

### claim_requirements

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_id | uuid, FK to claims, cascade on delete | |
| document_category | string | One of the `EvidenceDocumentCategory` values |
| is_required | boolean, default true | |
| is_fulfilled | boolean, default false | |
| evidence_file_id | uuid, FK to evidence_files, nullable, null on delete | The file that fulfils this row, if any |
| snapshot_at | datetime | When the snapshot was taken |
| overridden_by | bigint, FK to users, nullable, null on delete | |
| override_reason | text, nullable | |
| overridden_at | datetime, nullable | |
| created_at, updated_at | timestamps | |

Unique `(claim_id, document_category)`. Snapshots are rewritten when
readiness is checked during review and when a claim is marked ready to
submit; override information survives re-snapshots.

### claim_deadlines

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_id | uuid, FK to claims, cascade on delete | |
| deadline_at | datetime | Customer-supplied, never inferred |
| timezone | string | IANA timezone; status is judged in this timezone |
| source_reference | text, nullable | Where the customer got the deadline |
| reminder_days_before | json, nullable | Array of integers, 0 to 90 |
| status | string, default `upcoming` | `upcoming`, `due_soon`, `overdue`, `met`, `missed` |
| superseded_at | datetime, nullable | Set when a newer deadline replaces this row |
| created_at, updated_at | timestamps | |

Indexes `(organization_id, status)`, `(organization_id, deadline_at)`,
`(claim_id, superseded_at)`. One active (non-superseded) row per claim
is enforced in the application.

### generated_documents

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_id | uuid, FK to claims, cascade on delete | |
| document_type | string, default `claim_package` | |
| storage_key | string, nullable | Null until the job renders the PDF |
| disk | string, nullable | `private` locally, `s3` in production |
| file_size | unsigned bigint, nullable | Bytes |
| generated_by | bigint, FK to users, nullable, null on delete | |
| includes_internal_notes | boolean, default false | |
| status | string, default `queued` | `queued`, `ready`, `failed` |
| error_message | text, nullable | Set when rendering fails |
| generated_at | datetime, nullable | |
| created_at, updated_at | timestamps | |

Indexes `(organization_id, claim_id)` and `(claim_id, status)`.

## Tables in Milestone 4

### audit_logs

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| actor_type | string | `user` or `system` |
| actor_id | bigint, FK to users, nullable, null on delete | Null for system rows |
| action | string | e.g. `shipment.created`, `claim.status_changed`, `member.added` |
| entity_type | string | e.g. `Shipment`, `Claim`, `GeneratedDocument` |
| entity_id | string | UUID of the subject row, stored as a string |
| before | json, nullable | Changed scalar fields before the change |
| after | json, nullable | Changed scalar fields after the change |
| correlation_id | string, nullable | Request correlation id, when one exists |
| ip_address | string, nullable | Request IP, null for console and queue work |
| created_at | timestamp | No `updated_at`: the table is append-only |

Indexes `(organization_id, created_at)`, `(organization_id, entity_type)`,
`(organization_id, action)`, and `(organization_id, actor_id)`.

Append-only by design: there are no update or delete endpoints, and
the model cancels updates and deletes in code. The `before` and `after`
payloads hold only changed scalar fields; passwords, tokens, storage
keys, and internal notes are never written (see
`AuditService::sanitize`). Claim status is excluded from the observer
payloads because the transition service writes one explicit
`claim.status_changed` row per change.

### notifications

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| user_id | bigint, FK to users, cascade on delete | The recipient |
| type | string | One of the `NotificationType` values |
| title | string | |
| body | text | |
| data | json, nullable | Entity links (claim_id, shipment_id, and friends) |
| dedupe_key | string, nullable | Stable key per event, e.g. `claim.evidence_missing:<claim uuid>:2026-11-16` or `deadline.overdue:<deadline uuid>:2026-11-16` |
| read_at | timestamp, nullable | Set when the member marks the row read |
| email_sent_at | timestamp, nullable | Set when the email for this row is sent |
| created_at, updated_at | timestamps | |

Unique `(organization_id, user_id, dedupe_key)` where a dedupe key is
present, so a repeated job run cannot create a second row for the same
event. Indexes `(organization_id, user_id, read_at)` for the unread
count and `(organization_id, user_id, created_at)` for the list.

### notification_preferences

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| user_id | bigint, FK to users, cascade on delete | |
| event_type | string | One of the `NotificationType` values |
| in_app | boolean, default true | |
| email | boolean, default true | |
| created_at, updated_at | timestamps | |

Unique `(user_id, organization_id, event_type)`. A missing row means
both channels on: preferences are only stored when a member changes
one. The notification service reads the row (or the default) per
recipient per event type before creating an in-app row or queueing an
email.

## Reports: financial definitions

`ReportService` is the single source for the money figures used by the
reports endpoints and the dashboard. Every figure is grouped by
currency; amounts in different currencies are never added together,
and there is no conversion.

- **Potential**: sum of `requested_amount` on claims in `potential`,
  `evidence_required`, `under_review`, or `ready_to_submit`. Estimated
  only, never presented as recovered.
- **Submitted**: sum of `requested_amount` on claims in `submitted`,
  `accepted`, `partially_accepted`, `rejected`, or `paid`, plus claims
  in `closed` that have a `submitted_at` timestamp. A claim closed
  without submission counts nowhere.
- **Accepted**: sum of `accepted_amount` on claims in `accepted`,
  `partially_accepted`, or `paid`.
- **Recovered**: sum of `recovered_amount` on claims in `paid` only.
- **Rejected**: sum of `requested_amount` on claims in `rejected`.
- **Outstanding**: accepted minus recovered, floored at 0, computed per
  claim over claims in `accepted`, `partially_accepted`, or `paid`,
  then summed per currency.

## Conventions

- UUID primary keys for organizations, invitations, carriers, shipments,
  events, categories, evidence files, claims, claim histories,
  requirements, deadlines, generated documents, audit logs,
  notifications, and notification preferences, because those ids
  appear in URLs, headers, and emails. Internal join rows (memberships,
  claim evidence links, claim sequences) use auto-increment ids.
- Foreign keys with explicit delete behavior on every relationship.
- `organization_id` on every tenant-owned table, with an index.
- Money is stored as `decimal(12,2)` with a `currency` column, never as
  a float. Amounts in Milestone 2 (`base_rate`, `estimated_amount`) are
  estimates or agreed rates, never recovered revenue. Claim amounts keep
  requested, accepted, and recovered in separate columns for the same
  reason.

## Tables in v1.1.0 (Phase 2, UX Improvements)

### saved_views

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| user_id | bigint, FK to users, cascade on delete | The member who saved the view |
| resource_type | string | `shipment` or `claim` |
| name | string, max 100 | |
| filters | json | Flat map of list query parameter names to text values |
| created_at, updated_at | timestamps | |

Index `(organization_id, user_id, resource_type)`. Views are personal:
a member only sees their own. At most 25 per member per organization
per resource type.

### claim_review_notes

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| organization_id | uuid, FK to organizations, cascade on delete | |
| claim_id | uuid, FK to claims, cascade on delete | |
| user_id | bigint, FK to users, nullable, null on delete | The reviewer who wrote the note |
| note | text | |
| created_at, updated_at | timestamps | |

Indexes `(organization_id, claim_id)` and `(claim_id, created_at)`.
Notes are append-only: there is no update or delete endpoint. The
review action (approve / request changes) writes a note row alongside
the status history row, so the decision and its reasoning stay linked.

## Tables in v1.1.1 (Phase 2, Reliability)

### job_executions

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | uuid, PK | |
| key | string, unique | Idempotency key, for example `claim-package:{document_id}` |
| job | string | Fully qualified job class name |
| status | string, default `running` | `running` or `completed` |
| completed_at | timestamp, nullable | |
| created_at, updated_at | timestamps | `updated_at` is the staleness clock: a `running` row untouched for over 60 minutes can be re-claimed |

Index `(job, status)`. The unique key is the atomic claim: two
workers cannot claim the same key. A failed run releases its key
(deletes the row); a completed key is never re-claimed. The table
is not tenant-scoped: keys embed the tenant-owned id they guard.

### evidence_files: checksum (added in v1.1.1)

| Column | Type | Notes |
| ------ | ---- | ----- |
| checksum | string (64), nullable | SHA-256 hex digest of the uploaded file, stored at upload. Null for files uploaded before v1.1.1, which skip verification. |

### Indexes added in v1.1.1

- `jobs`: composite `(queue, available_at, reserved_at)` for
  queue polling and the queue-monitoring endpoint.
- `shipments`: `(organization_id, delivery_appointment)` for the
  filtered list queries.
- `claims`: `(organization_id, requested_amount)` for the
  amount-range list queries.

## Tables planned for later milestones

None. Milestone 5 (hardening and release) adds no new tables.
