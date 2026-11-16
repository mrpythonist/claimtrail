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
`action` of `readiness_override` in metadata) until the full audit
trail arrives in Milestone 4.

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

## Conventions

- UUID primary keys for organizations, invitations, carriers, shipments,
  events, categories, evidence files, claims, claim histories,
  requirements, deadlines, and generated documents, because those ids
  appear in URLs, headers, and emails. Internal join rows (memberships,
  claim evidence links, claim sequences) use auto-increment ids.
- Foreign keys with explicit delete behavior on every relationship.
- `organization_id` on every tenant-owned table, with an index.
- Money is stored as `decimal(12,2)` with a `currency` column, never as
  a float. Amounts in Milestone 2 (`base_rate`, `estimated_amount`) are
  estimates or agreed rates, never recovered revenue. Claim amounts keep
  requested, accepted, and recovered in separate columns for the same
  reason.

## Tables planned for later milestones

Notifications, notification preferences, audit logs. See
`docs/PHASE-1-SCOPE.md` for the milestone each one belongs to.
