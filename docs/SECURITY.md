# Security

ClaimTrail stores commercially sensitive transportation records. This
document lists the controls in place in Milestone 1 and the review work
that is still open. Having controls does not mean the product is compliant
with any regulation; no compliance is claimed.

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

### Secrets

- No secrets are committed. `.env` files are git-ignored; only
  `.env.example` files are in the repository.
- The demo seeder credentials are development-only and documented as such.

## Open review items

These are known gaps, not oversights. Each needs a decision before the
product handles real customer data.

1. **Token lifetime.** Sanctum tokens do not expire by default. Set
   `SANCTUM_TOKEN_EXPIRATION` and add a refresh flow before production.
2. **Token storage.** The frontend stores the token in `localStorage`,
   which is readable by any script on the page. This is acceptable for
   Milestone 1, but an httpOnly cookie session (Sanctum SPA mode) should
   be evaluated before production.
3. **Security headers.** No Content-Security-Policy, HSTS, or similar
   headers are configured yet. Add them at the nginx or Next.js config
   level before production.
4. **Audit trail.** Milestone 1 has no audit logging. It arrives in
   Milestone 4; until then, membership and invitation changes are not
   recorded beyond database timestamps.
5. **File uploads.** No uploads exist yet. Milestone 2 must add type
   validation, size limits, private storage, and signed download URLs.
6. **Penetration test.** No external security review has been done.
7. **Legal documents.** The privacy policy and terms of service are
   placeholders marked for legal review.
8. **Dependency scanning.** No automated vulnerability scanning is wired
   up yet (Dependabot or equivalent, plus `composer audit` / `npm audit`
   in CI).
9. **Backups and deletion.** No backup, retention, or account-deletion
   procedures exist yet.
10. **Production secrets.** The Docker Compose defaults are development
    values. Production needs generated secrets managed outside the
    repository.

## Reporting a vulnerability

Email the maintainer directly. Do not open a public issue for a security
problem. (Add a security contact address before a public launch.)
