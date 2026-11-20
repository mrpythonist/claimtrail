# Architecture

ClaimTrail is a modular monolith: one Laravel backend, one Next.js frontend,
one PostgreSQL database, Redis for queues and caching. There are no
microservices in Phase 1. Module boundaries inside the monolith are kept
clear so a module can be extracted later if there is ever a real reason to.

## System layout

```text
Browser
  |
  | HTTPS
  v
Next.js frontend (port 3000)
  |
  | REST, JSON, Bearer token, X-Organization-Id header
  v
Laravel API (port 8000)
  |-- PostgreSQL (organizations, users, members, invitations, ...)
  |-- Redis (queues, cache)
  |-- Queue worker (invitation emails, verification emails, later: reminders)
  |-- Local disk / S3-compatible storage (evidence files from Milestone 2, generated claim packages from Milestone 3)
```

In production the backend should run behind nginx with PHP-FPM instead of
`php artisan serve`. The Docker Compose setup in this repository is a
development setup; see `docs/DEVELOPMENT.md` and the production notes at
the end of this document.

## Backend layout (Laravel)

```text
backend/
  app/
    OrganizationRole.php     Six roles (owner, admin, operations_manager,
                             dispatcher, billing_staff, viewer)
    Permission.php           The full permission vocabulary
    MembershipStatus.php     active / inactive
    Http/
      Controllers/Api/V1/    One controller per resource area
      Middleware/ResolveOrganization.php
      Resources/             API response shapes
    Models/                  Eloquent models
    Models/Concerns/BelongsToOrganization.php
    Policies/OrganizationPolicy.php
    Providers/AppServiceProvider.php   Gates, mail URL customization
    Support/CurrentOrganization.php    Request-scoped tenant holder
  config/permissions.php     The single role to permission map
  database/
    migrations/              One migration per table
    seeders/DemoSeeder.php   Development-only demo data
  routes/api.php             All API routes, /api/v1 prefix
  tests/Feature/             HTTP-level tests (auth, tenancy, permissions,
                             organizations, invitations)
```

### Request flow for a tenant-scoped request

1. `auth:sanctum` authenticates the Bearer token.
2. `ResolveOrganization` reads the `X-Organization-Id` header, loads the
   organization, and verifies the user is an active member. Unknown
   organizations and non-members both get a 404, so organization ids
   cannot be probed. A missing header gets a 400.
3. The resolved organization is stored in `CurrentOrganization` (a
   request-scoped singleton) and on the request attributes.
4. The controller authorizes the action through a policy or a gate, which
   checks the permission map, never a role name.
5. Tenant-owned models carry the `BelongsToOrganization` trait, whose
   global scope limits queries to the resolved organization. This is
   defense in depth: controllers still authorize explicitly.

### Permissions

`config/permissions.php` maps each role to its permissions. `Permission`
is a string-backed enum with the full vocabulary, including permissions
for modules that do not exist yet (claims, deadlines, and so on). Mapping
a permission does not create the feature; it only means the role model is
complete from the start and later modules enforce against the same
vocabulary. Shipment, event, and evidence permissions are enforced from
Milestone 2.

`AppServiceProvider` registers one gate per permission. Controllers and
policies call `Gate::authorize('manage-members', $organization)` or
`$user->hasPermissionIn($organization, Permission::ManageMembers)`.
Role names appear in exactly two places: the enum and the permission map.

### Multi-tenancy rules

- Every tenant-owned record has an `organization_id` foreign key.
- Tenant isolation is enforced on the backend. The frontend sends the
  organization header, but never decides access.
- Cross-tenant access fails with 404 (not found) rather than 403 where
  the resource id itself must not be confirmed to exist.
- The owner role is protected: only an owner can change another owner's
  membership or grant the owner role, and the last active owner cannot be
  demoted, deactivated, or removed.

## Frontend layout (Next.js)

```text
frontend/
  src/
    app/
      page.tsx               Landing page
      login/ register/ forgot-password/ reset-password/ verify-email/
      invitations/accept/
      privacy/ terms/        Placeholders, marked for legal review
      (app)/                Authenticated route group (guarded layout)
        dashboard/
        shipments/           List, create, detail, edit, CSV import
        evidence/            Evidence library
        settings/
          organization/ members/ profile/
          organizations/new/
    components/
      ui/                    Button, Input, Textarea, Card, Badge, Alert,
                             Spinner, EmptyState, Modal, Table, PageHeader
      shipments/             ShipmentForm, EventForm (shared by pages)
      layout/                AppShell (sidebar, org switcher, user menu),
                             AuthLayout (centered auth card)
    lib/
      api.ts                 Typed fetch wrapper, token and org headers;
                             apiUpload for multipart, apiDownload for files
      auth.tsx               AuthProvider, login/logout, org selection
      permissions.ts         The single frontend role to capability map
      validation.ts          Client-side form validation (backend is authoritative)
      types.ts               Shared API types
      shipments.ts           Shipment status chain, labels, file helpers
      timezones.ts           Timezone choices
```

### Frontend conventions

- Pages are client components that fetch through `lib/api.ts`. There is
  no server-side data fetching in Milestone 1.
- The token lives in `localStorage` (`claimtrail_token`) and the selected
  organization in `localStorage` (`claimtrail_org_id`). A 401 clears the
  session and returns the user to the login page.
- Data components are keyed by organization id so switching organizations
  remounts them with fresh state.
- The frontend permission map (`lib/permissions.ts`) only decides which
  controls to show. The backend enforces every permission.

## Design system

Tokens live in `frontend/src/app/globals.css` using Tailwind 4 `@theme`:

- Brand: deep navy scale (`brand-50` to `brand-950`), used for the
  sidebar, primary buttons, and links.
- Accent: amber, used sparingly (logo mark, warnings).
- Neutrals: Tailwind slate.
- Type: Geist Sans with system fallbacks, Geist Mono for identifiers.
- No gradients anywhere in the interface.

Reusable components are in `src/components/ui/`. New pages should compose
from them instead of inventing new patterns.

## Module boundaries (present and planned)

| Area | Backend | Frontend | Status |
| ---- | ------- | -------- | ------ |
| Identity and auth | AuthController, Sanctum | auth pages, AuthProvider | Done (M1) |
| Organizations and tenancy | Organization models, middleware, trait | settings, org switcher | Done (M1) |
| RBAC | Permission enum, config map, gates, policy | permissions.ts | Done (M1) |
| Shipments | ShipmentController, ShipmentRules, ShipmentCsvImporter | shipments pages, ShipmentForm | Done (M2) |
| Events | ShipmentEventController, EventCategory | events on shipment detail, EventForm | Done (M2) |
| Evidence | EvidenceFileController, private disk | evidence library, upload | Done (M2) |
| Claims | ClaimController, ClaimTransitionService, ClaimReferenceService | claims pages, review page | Done (M3) |
| Readiness | ReadinessService, EvidenceRequirement | readiness panel, requirements settings | Done (M3) |
| Deadlines | ClaimDeadlineController, DeadlineStatusService, hourly command | deadlines overview | Done (M3) |
| PDF packages | ClaimPackageController, GenerateClaimPackage job, dompdf | package section on claim detail | Done (M3) |
| Audit trail | AuditService, AuditLog model, observers, CorrelationIdMiddleware | audit log page, before/after viewer | Done (M4) |
| Notifications | NotificationService, Notification model, queued mailable | notifications page, preferences form, unread badge | Done (M4) |
| Reports | ReportService | reports page, CSV export | Done (M4) |
| Dashboard metrics | DashboardService | rebuilt dashboard | Done (M4) |
| Operations and reliability | HealthController, OpsController, HealthCheckService, QueueMonitorService, FailedJobService, ErrorLogService, ErrorReporter, job idempotency traits | operations page, ops.ts | Done (v1.1.1) |
| Customization | CustomFieldController, EvidenceTemplateController, DeadlineSettingController, ExportTemplateController, EventCategoryController, CustomFieldService, ClaimReferenceService (prefix) | Not built (backend only in v1.1.2) | Done, backend only (v1.1.2) |

## Production notes (not implemented in Phase 1)

- Run the backend with PHP-FPM behind nginx, not `php artisan serve`.
- Use PostgreSQL and Redis services (RDS and ElastiCache, or containers).
- Set a real mailer (SES, Mailgun, or SMTP) instead of the log driver.
- Set `SANCTUM_TOKEN_EXPIRATION` once the frontend has a token refresh flow.
- Serve the frontend from a Node server or a static host with the API URL
  baked in at build time.
- Add the CI workflow (prepared separately; the GitHub API rejects
  workflow file pushes, so it is added through the web UI).

## Reliability (v1.1.1)

- Health checks live in `HealthCheckService`: database, cache,
  queue (database driver only), and storage. `GET /api/v1/health`
  is the only public endpoint besides auth.
- Operations endpoints under `/api/v1/ops` require the
  `view-operations` permission (owner, admin). Failed jobs are
  attributed to an organization from their payload and never
  shown across tenants.
- Queued jobs share a standard retry profile
  (`HasStandardRetries`: 3 tries, 120s timeout, backoff
  30/120/600s, retry until 24h) and are idempotent
  (`IsIdempotent` + the `job_executions` table keyed by a unique
  idempotency key). See `docs/OPERATIONS.md` for the runbook.
- Logging has two JSON channels: `json` (all levels) and
  `errors` (error and above). Every record carries a correlation
  id, the application name, and the environment. 5xx errors are
  reported with request context; 4xx responses are not errors.
- Slow queries are logged above `DB_SLOW_LOG_MS` (default 500).
  Bindings are never logged.
- Every response carries an `X-Response-Time` header. The
  event-categories list supports ETags (304 on `If-None-Match`).

## Customization (v1.1.2, backend only)

- Custom fields are definitions per organization and resource type
  (`shipment` or `claim`), stored in `custom_fields`; values live
  as a JSON map on the shipment or claim row. `CustomFieldService`
  validates values (unknown keys, required fields, text/number/
  date/select/boolean types), lists the available export columns,
  and resolves deadline settings. At most 20 active definitions per
  organization and resource type.
- Event categories can be renamed (slug recomputed, collision-checked
  against visible categories), disabled (new events rejected, old
  events still readable), and given a default estimated amount and
  currency that prefill new events. System categories cannot be
  changed, and a category with events cannot be deleted.
- Evidence templates (`evidence_templates` plus items) are named
  checklists that apply to a claim by creating a `ClaimRequirement`
  row per missing document category; applying twice creates nothing
  the second time.
- Deadline settings resolve by claim category, then event category,
  then global, and apply at claim creation only when no explicit
  submission deadline is given. The claim reference prefix comes
  from organization settings (`claim_reference_prefix`, default
  `CLM`) and only affects claims created after the setting is saved.
- Export templates save a column selection per resource type; one
  default per organization and resource type. Shipment and claim
  CSV exports accept `?export_template_id=` and fall back to the
  default column sets. A new permission, `manage-customization`
  (owner, admin, operations manager), guards the customization
  write endpoints. Notification preferences were verified
  pre-existing and were not rebuilt in this version.
- No frontend was built for customization in v1.1.2.

## Security and Operations (v1.1.3)

- A new permission, `manage-security` (owner, admin), guards
  backups, retention, the production-readiness check, and the
  security event log. The Security settings hub
  (`/settings/security`) is the frontend surface for all four.
- Backups live in `BackupService`: a run copies the whole database
  plus every evidence and package file and writes a manifest of
  SHA-256 checksums. SQLite file databases are supported; the
  PostgreSQL path shells out to `pg_dump`/`pg_restore` and fails
  with a clear message when the binary is missing. Restores verify
  the manifest first and refuse on any mismatch. Runs are recorded
  in `backup_runs`. See `docs/OPERATIONS.md`.
- Retention lives in `RetentionService`: config defaults in
  `config/retention.php` (audit logs 365 days, notifications 90,
  job executions 30, failed documents 30, security events 180;
  0 keeps forever), per-organization overrides in organization
  settings (`retention.*`), a weekly `retention:purge` with
  `--dry-run`, and one `retention.purged` audit row per
  organization. Ready claim packages are never purged.
- Security events (`security_events`) record failed logins
  (`login.failed`, email only, never the password) and
  authorization denials (`authorization.denied`, with method and
  path). Denials are recorded in the exception render callback in
  `bootstrap/app.php`, because Laravel's internal dont-report list
  means a report callback never fires for authorization
  exceptions. Recording never throws.
- `SecurityCheckService` runs ten production-readiness checks
  (APP_DEBUG, APP_KEY, APP_URL, session cookies, Sanctum token
  expiration, evidence disk, CORS origin, backup freshness, mail
  driver, queue driver) and reports pass/warn/fail per check plus
  an overall status. It is exposed as `security:check` (exit 1 on
  any failure) and `GET /security/check`. The check reads
  `config('app.env')`, not `app()->environment()`, so test
  overrides are honored.
- The security-headers middleware now also sends a strict
  Content-Security-Policy (`default-src 'none'; frame-ancestors
  'none'; base-uri 'none'`) and HSTS when the request is HTTPS or
  HSTS is enabled (on by default in production). Session cookies
  are secure by default in production.
- File downloads are guarded by `StorageKey::isSafe`, which
  rejects storage keys that escape their prefix (evidence or
  packages) or contain traversal segments.
- Audit sweeps: `TenantIsolationAuditTest` asserts 404 on
  cross-tenant access for every API resource; `RbacAuditTest`
  asserts the role matrix and that every non-public API route
  requires `auth:sanctum` and every tenant-scoped route resolves
  an organization. Findings are in `docs/SECURITY.md`; the
  incident-response procedure is in `docs/INCIDENT-RESPONSE.md`.
