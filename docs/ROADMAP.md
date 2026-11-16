# Roadmap

Ideas beyond Phase 1 (v1.0.1). Nothing here is implemented, scheduled, or
promised. This document exists so architectural decisions made now do not
close these doors by accident.

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
