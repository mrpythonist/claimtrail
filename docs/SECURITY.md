# Security

ClaimTrail stores commercially sensitive transportation records. This
document lists the controls in place in Milestones 1 through 5 and
the review work that is still open. Having controls does not mean the
product is compliant with any regulation; no compliance is claimed.

## Controls in place

### Authentication

- Passwords are hashed with bcrypt (12 rounds in production config, 4 in
  tests). Plaintext passwords never reach the database or the logs.
- Minimum password length is 8 characters, enforced on registration,
  reset, and password change.
- Login failures return one generic message whether the email exists or
  not, so the endpoint cannot be used to enumerate accounts.
- Forgot-password returns the same message for unknown emails.
- Email verification uses Laravel signed URLs (60-minute expiry).
- Password reset uses Laravel's broker with single-use tokens.
- Logout revokes the token used for the request.
- Rate limits on all unauthenticated auth endpoints (see `docs/API.md`).

### Authorization and tenancy

- Every tenant-scoped request must carry `X-Organization-Id`, and the
  backend verifies active membership before the controller runs.
- Unknown organizations and non-members both get 404, so ids cannot be
  probed.
- Permissions come only from `config/permissions.php`. Controllers and
  policies check permissions, never role names.
- Tenant-owned models carry a global scope limiting queries to the
  resolved organization (defense in depth behind explicit authorization).
- Cross-tenant access by direct id is covered by tests
  (`tests/Feature/TenancyTest.php`).
- The owner role is protected: only an owner can change another owner's
  membership or grant the owner role, and the last active owner cannot be
  demoted, deactivated, or removed.

### Invitations

- Only the SHA-256 hash of an invitation token is stored. The raw token
  exists only in the invitation email.
- Invitations expire after 7 days and can only be accepted by a signed-in
  user whose email matches the invitation.
- Invitation tokens are never included in API responses.

### Transport and browser

- CORS allows only the configured frontend origin (`FRONTEND_URL`).
- API routes render JSON errors, never HTML stack traces, for API clients.
- `APP_DEBUG` must be `false` in production so error pages do not leak
  internals.

### File uploads (Milestone 2)

- Uploads are capped at 20 MB and restricted to document and image
  types (pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, csv, txt).
  The `mimes` rule checks the file's actual contents, so a renamed
  executable does not pass as a PDF.
- Files are stored on a private disk (`storage/app/private` locally,
  S3 in production) under generated keys. The original filename is
  metadata only and never becomes a path.
- There are no permanent public URLs. Every download and preview goes
  through an authorized controller route that checks organization
  membership and the `view-evidence` permission. On S3 the route
  redirects to a 5-minute signed URL.
- The storage key and disk name are never exposed in API responses.
- Uploads, downloads, verification, and deletion are all
  permission-gated (`upload-evidence`, `view-evidence`,
  `verify-evidence`, `delete-evidence`).

### Claims and money (Milestone 3)

- Claim status changes go through one service (`ClaimTransitionService`)
  that enforces the transition map and the per-transition data rules.
  Controllers never assign a claim status directly.
- Payment is the only route to `paid`: the transition endpoint rejects
  moves to paid, and payment recording requires an accepted claim, a
  payment date, and a recovered amount greater than zero.
- Accepted amounts are never defaulted from requested amounts, and
  recovered amounts are never set automatically. The three figures are
  separate columns and separate API fields.
- A claim's amount, currency, category, and event link are frozen once
  the claim is submitted. Closed and paid claims are immutable.
- Evidence can only be attached to a claim from the claim's own
  shipment, and only within the same organization.
- Claim references are generated per organization from a locked
  sequence counter, so concurrent claims cannot collide.

### Generated documents (Milestone 3)

- Claim package PDFs are stored on the private disk under generated
  keys and served only through the authorized download route, with the
  same S3 signed-URL behavior as evidence downloads.
- Internal notes are excluded from packages unless the requester
  explicitly asks for them.
- The package states in its footer that it is supporting evidence, not
  a guarantee of acceptance.

### Audit trail (Milestone 4)

- Audit rows are append-only: there are no update or delete endpoints,
  and the model cancels updates and deletes in code.
- The `before` and `after` payloads hold only changed scalar fields.
  Passwords, tokens, storage keys, file contents, and internal notes
  are never written (`AuditService::sanitize` drops them by key).
- Every row records the organization, the actor (user or system), the
  action, the subject, the correlation id, and the request IP. The
  correlation id lets one request's trail be followed end to end.
- The audit list requires `view-audit-logs` (owner, admin, operations
  manager) and is scoped to the resolved organization.
- Audit rows are not seeded and are never fabricated: the trail records
  only actions that really happened.

### Notifications (Milestone 4)

- A member can only read their own notification rows; another member's
  row, or a row from another organization, returns 404.
- Dedupe keys (unique per organization and user) stop a repeated job
  run from notifying twice about the same event, in-app and by email.
- Email goes through a queued mailable with bounded retries (3 tries,
  backoff 60/300/900 seconds) and failed-job logging. The email body
  carries only the notification title, body, and a deep link; it never
  carries file contents or internal notes.
- Invitation notifications are created only for users who already have
  an account, so an invitation to an unknown email cannot be used to
  probe whether an address is registered.
- Notification emails respect per-member per-event preferences, so a
  member who turns a channel off stops receiving it.

### Mass assignment and SQL (Milestone 5 review)

- Every model restricts mass assignment through `$fillable` (or the
  `#[Fillable]` attribute on the User model). No model uses
  `$guarded = []`, and no controller creates a record from raw request
  data: controllers validate first and pass only validated fields.
- No user input reaches a raw SQL string. The remaining `selectRaw`,
  `whereRaw`, and `orderByRaw` calls use static strings or bound
  parameters. The one place a column name was interpolated
  (`ReportService::moneyByCurrency`) takes only hardcoded literals
  from its callers, and now also enforces an allowlist that rejects
  anything else.

### Security headers (Milestone 5, extended in v1.1.3)

- A global middleware (`App\Http\Middleware\SecurityHeaders`) sets
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, and a minimal `Permissions-Policy`
  (camera, microphone, geolocation all disabled) on every API
  response. Since v1.1.3 it also sends a strict
  Content-Security-Policy (`default-src 'none'; frame-ancestors
  'none'; base-uri 'none'`) and HSTS (max-age 31536000,
  includeSubDomains) when the request is HTTPS or HSTS is enabled
  in config (on by default in production). The API serves no HTML
  of its own, so the CSP is a defense-in-depth header; the Next.js
  frontend still needs its own CSP.
- Session cookies are secure by default in production
  (`SESSION_SECURE_COOKIE` defaults to true when `APP_ENV` is
  production).
- CORS allows only the configured frontend origin (`FRONTEND_URL`,
  default `http://localhost:3000`), with credentials disabled. It is
  never `*` with credentials.
- The frontend stores the token in `localStorage` and sends it as a
  Bearer header. The token is never put in a URL and never written
  to a log.

### Error handling (Milestone 5)

- Unauthenticated API requests return JSON `401` even when the
  request has no `Accept: application/json` header. The API-only app
  has no login route, so guests are never redirected (previously this
  produced a 500). Covered by regression tests.
- With `APP_DEBUG=false`, Laravel renders only a generic "Server
  Error" for unexpected exceptions: no stack trace, no SQL, no file
  paths. `APP_DEBUG` must be `false` in production.

### Secrets

- No secrets are committed. `.env` files are git-ignored; only
  `.env.example` files are in the repository. A full-tree scan in
  Milestone 5 found no committed keys, passwords, or tokens; the only
  real `APP_KEY` in the tree is the development `.env` file, which is
  git-ignored and never pushed.
- The demo seeder credentials are development-only and documented as such.

## Security audits (v1.1.3)

These audits ran in v1.1.3 and are now automated tests, so they
re-run on every suite run. No gaps were found; the findings below
are what the audits prove.

### Tenant isolation audit

`TenantIsolationAuditTest` attempts cross-tenant access on every
API resource and asserts 404: shipments, events, evidence, claims,
deadlines, packages, carriers, requirements, custom fields,
deadline settings, export templates, evidence templates, saved
views, and notifications, plus nested cases (an event under
another organization's shipment, attaching evidence across
organizations returns 422, detaching returns 404) and a non-member
viewing an organization (403). Every resource returned 404 for
cross-tenant direct-id access.

The code audit found no unscoped queries. Every tenant-owned
model uses the `BelongsToOrganization` global scope, and
controllers do an explicit organization check because
route-model binding runs before the organization middleware sets
the current organization — the scope is defense in depth, not
the only guard. The five models without the trait are legitimate
globals: `ClaimSequence` (scoped by explicit queries),
`EvidenceTemplateItem` (child of a template), `JobExecution`
(global), `Organization`, and `User`. The `withoutGlobalScope`
call sites are legitimate: invitation acceptance, queued jobs,
and queue monitoring.

### RBAC audit

`RbacAuditTest` asserts the role matrix on sensitive endpoints:

| Role | Restrictions verified |
| ---- | --------------------- |
| viewer | cannot write anywhere |
| billing staff | cannot manage the organization, members, or invitations |
| dispatcher | cannot delete events, review claims, read audit logs, or view operations |
| operations manager | cannot manage security (backups, retention, checks, events) |
| admin | can manage security |

The same test sweeps every registered API route and asserts that
every route except the six documented public ones (health, auth
register/login/forgot-password/reset-password, email verify)
requires `auth:sanctum`, and that every tenant-scoped route
resolves an organization. No unguarded endpoint was found.

### File access review

Evidence and generated-document downloads and previews were
already behind auth, tenant checks, and policy gates, and uploads
reject disallowed types by content (`mimes` rule). v1.1.3 adds a
storage-key guard (`StorageKey::isSafe`): downloads and previews
refuse keys that do not start with the expected prefix
(`evidence/...` or `packages/...`) or that contain traversal
segments. Tests (`FileAccessSecurityTest`) cover unauthenticated
401, cross-tenant 404, traversal attempts refused, and disallowed
upload types rejected. There is no public storage URL: the
evidence disk is private, and S3 access is by signed URLs that
expire in 5 minutes.

### Security events

Failed logins and authorization denials are recorded in
`security_events` and listed at `GET /security/events` (owner,
admin). A failed login records the email only, never the
password. A denial records the method and path. Failed logins
have no organization (none is known yet) and are therefore not
listed per organization; they are purged by the
all-organizations retention run. Recording never throws: a
logging failure must not break the request it is logging.

## Open review items

These are known gaps, not oversights. Each needs a decision before the
product handles real customer data.

1. **Token lifetime.** Sanctum tokens do not expire by default. Set
   `SANCTUM_TOKEN_EXPIRATION` and add a refresh flow before production.
2. **Token storage.** The frontend stores the token in `localStorage`,
   which is readable by any script on the page. This is acceptable for
   Milestone 1, but an httpOnly cookie session (Sanctum SPA mode) should
   be evaluated before production.
3. **Security headers.** API responses now carry nosniff, DENY,
   no-referrer, a minimal Permissions-Policy, a strict
   Content-Security-Policy, and HSTS (v1.1.3), and session cookies
   are secure by default in production. The Next.js frontend still
   needs its own CSP and HSTS at the nginx or Next.js config
   level before production.
4. **Upload scanning.** Uploaded files are type-checked by content but
   not scanned for malware. Add antivirus scanning before the product
   handles uploads from untrusted users.
5. **Penetration test.** No external security review has been done.
6. **Legal documents.** The privacy policy and terms of service are
   placeholders marked for legal review.
7. **Dependency scanning.** No automated vulnerability scanning is wired
   up yet (Dependabot or equivalent, plus `composer audit` / `npm audit`
   in CI).
8. **Backups and retention.** Done in v1.1.3: daily backups with
   checksum verification, weekly retention purge with per-organization
   overrides, and a restore procedure. Still open: account deletion
   (no self-service delete or data-export flow yet) and the
   PostgreSQL backup/restore paths, which were never run against
   live PostgreSQL.
9. **Production secrets.** The Docker Compose defaults are development
   values. Production needs generated secrets managed outside the
   repository.

## Reporting a vulnerability

Email the maintainer directly. Do not open a public issue for a security
problem. (Add a security contact address before a public launch.)
