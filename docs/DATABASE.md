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

## Conventions

- UUID primary keys for organizations and invitations, because those ids
  appear in URLs, headers, and emails. Internal join rows (memberships)
  use auto-increment ids.
- Foreign keys with explicit delete behavior on every relationship.
- `organization_id` on every tenant-owned table, with an index.
- Financial columns do not exist yet; when they arrive (Milestone 3),
  amounts will be stored as integer minor units with a currency column,
  never as floats.

## Tables planned for later milestones

Shipments, carriers, shipment events, event categories, evidence files,
evidence requirements, claim requirements, claims, claim evidence,
claim status histories, claim deadlines, notifications, notification
preferences, audit logs, generated documents. See `docs/PHASE-1-SCOPE.md`
for the milestone each one belongs to.
