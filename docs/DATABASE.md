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

There is no `claim_id` column yet. Claims arrive in Milestone 3, and
that milestone's migration adds a nullable claim reference.

## Conventions

- UUID primary keys for organizations, invitations, carriers, shipments,
  events, categories, and evidence files, because those ids appear in
  URLs, headers, and emails. Internal join rows (memberships) use
  auto-increment ids.
- Foreign keys with explicit delete behavior on every relationship.
- `organization_id` on every tenant-owned table, with an index.
- Money is stored as `decimal(12,2)` with a `currency` column, never as
  a float. Amounts in Milestone 2 (`base_rate`, `estimated_amount`) are
  estimates or agreed rates, never recovered revenue.

## Tables planned for later milestones

Evidence requirements, claim requirements, claims, claim evidence,
claim status histories, claim deadlines, notifications, notification
preferences, audit logs, generated documents. See `docs/PHASE-1-SCOPE.md`
for the milestone each one belongs to.
