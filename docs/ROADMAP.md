# Roadmap

Ideas beyond Phase 1 (v1.0.1). Nothing here is implemented, scheduled, or
promised. This document exists so architectural decisions made now do not
close these doors by accident.

## Known limitations to close

Follow-ups that come directly out of Phase 1's known limitations
(`docs/PHASE-1-SCOPE.md`) and open security items
(`docs/SECURITY.md`). These are the first things to do after the
release, before new features.

- **Carrier deletion and shipment unarchive.** Both were left out of
  Phase 1 on purpose; add them with the same permission and audit
  treatment as the rest of the product.
- **Token refresh and httpOnly cookie sessions.** Set
  `SANCTUM_TOKEN_EXPIRATION`, add a refresh flow, and evaluate Sanctum
  SPA mode so the token stops living in `localStorage`.
- **Browser end-to-end tests.** The API scenario pass covers the
  workflows; the frontend still needs a browser suite (Playwright or
  equivalent) on a machine with browsers.
- **Production verification.** Run the Docker builds, migrate against
  live PostgreSQL, run the queue worker against live Redis, and
  exercise the S3 storage paths and a real mailer. None of these ran
  during Phase 1.
- **Upload malware scanning.** Type checks are in place; scanning is
  not.
- **Penetration test and dependency scanning.** No external review has
  been done; wire Dependabot (or equivalent) plus `composer audit`
  and `npm audit` into CI.
- **Backup, retention, and account-deletion procedures.** None exist
  yet.
- **Production TLS and WAF setup, plus CSP and HSTS headers.**

## After Phase 1

- **Subscription billing.** Stripe integration, plans, trial handling,
  and plan enforcement (organizations over their limits).
- **Broker integrations.** TMS integrations and EDI, starting with the
  most common systems among early customers.
- **Automated broker communication.** Sending claim packages to brokers
  from inside ClaimTrail, with delivery tracking.
- **Driver mobile app.** Photo capture and arrival/departure confirmation
  from the road.
- **Live GPS tracking.** Optional location proof for detention events.
- **SMS and WhatsApp notifications.** In addition to email and in-app.
- **Advanced reporting.** Trends, broker-level recovery rates, and
  exportable board reports.
- **Multi-currency.** Explicit exchange rates for mixed-currency
  reporting. Never invented rates.
- **White-label and resellers.** Branded portals for agencies.
- **Enterprise SSO.** SAML and OIDC for larger customers.

## Explicitly not on the roadmap

- AI-based legal or contractual eligibility decisions. ClaimTrail records
  what the customer and their contracts say; it does not decide
  eligibility for them.
- Automated claim negotiation. Submission and follow-up stay human.
- Guarantees of recovery. No feature will present a potential amount as
  recovered revenue.
