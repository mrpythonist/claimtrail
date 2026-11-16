# Phase 1 Scope (v1.0.1)

Phase 1 is the foundation of ClaimTrail: accounts, organizations, and the
full claim workflow for trucking revenue recovery. This document tracks
which of the 14 specified modules are done and which are still to come.

## Status

| # | Module | Milestone | Status |
| - | ------ | --------- | ------ |
| 1 | Authentication | M1 | Done |
| 2 | Multi-tenant organizations | M1 | Done |
| 3 | Role-based access control | M1 | Done |
| 4 | Dashboard | M1 (overview) / M4 (full metrics) | Partial: member and invitation counts only |
| 5 | Shipment management | M2 | Not started |
| 6 | Shipment events | M2 | Not started |
| 7 | Evidence management | M2 | Not started |
| 8 | Claim management | M3 | Not started |
| 9 | Evidence requirements and readiness | M3 | Not started |
| 10 | Deadline management | M3 | Not started |
| 11 | Claim package generation (PDF) | M3 | Not started |
| 12 | Claim history and audit trail | M4 | Not started |
| 13 | Notifications | M4 | Not started |
| 14 | Basic reporting | M4 | Not started |

## Milestone 0: environment probe (done)

- PHP 8.3.6, Composer 2.7.1, Laravel 13.42.0, Sanctum 4.4.
- Next.js 16.4.6, React 19.3.0, Tailwind CSS 4.3.3, Vitest 5.1.0.
- Note: the original brief said Laravel 11, but `composer create-project`
  installs the current stable release, which is Laravel 13. The project
  uses Laravel 13.

## Milestone 1: foundation (done)

- Auth API: register, login, logout, forgot password, reset password,
  email verification, profile and password update. Rate limited.
- Organizations: create, profile, settings, member list, invitations,
  accept invitation, activate/deactivate/remove members, switch orgs.
- Tenancy enforced on the backend: header resolution middleware, global
  query scope, policy and gate authorization, 404 for cross-tenant access.
- Six roles, one central permission map, owner protections.
- Design system and app shell: sidebar, org switcher, user menu,
  breadcrumbs, page headers, reusable table and form components.
- Pages: landing, login, register, forgot/reset password, verify email,
  invitation accept, privacy/terms placeholders, dashboard, organization
  settings, members and invitations, profile settings, create organization.
- Docker Compose setup (backend, queue worker, frontend, PostgreSQL,
  Redis) and Dockerfiles.
- Documentation: README, architecture, database, API, OpenAPI, security,
  development, scope, roadmap, changelog.
- Tests: 51 backend feature tests, 16 frontend tests, production build
  verified, lint clean.

## Milestone 2: shipments, events, evidence (planned)

- Shipment CRUD with statuses, search, filter, sort, pagination, CSV
  import (mapping, validation, duplicate detection, preview, row errors)
  and CSV export.
- Shipment events: detention, layover, TONU, lumper, additional stop,
  additional miles, other. Multiple events per shipment.
- Evidence: secure upload, type and size validation, private storage,
  signed download URLs, categorization, verification status.
- Extends the dashboard with shipment and event counts.

## Milestone 3: claims, readiness, deadlines, PDF (planned)

- Claim CRUD with the 10-status workflow and controlled transitions.
- Evidence requirements per organization, readiness calculation and
  overrides with audit records.
- Deadline tracking with timezones and reminder schedules.
- PDF claim package generation (queued, downloadable, regenerable).
- Financial recording: requested, accepted, and actually recovered
  amounts kept separate.

## Milestone 4: audit, notifications, reports (planned)

- Append-only audit trail for shipments, events, evidence, claims,
  deadlines, membership, and admin actions.
- In-app and email notifications with preferences, read state, and
  idempotent delivery.
- Reports: claims by status and event type, potential/submitted/accepted/
  recovered/rejected/outstanding value, recovery history over time.
  Date filters and CSV export.
- Full dashboard metrics.
- Integration testing, security review, final verification.

## Milestone 5: hardening and release (planned)

- Full test pass (backend, frontend, API integration, critical end-to-end
  scenarios A to I from the specification).
- Security review against `docs/SECURITY.md`.
- Documentation refresh and v1.0.1 release.

## Explicit exclusions (not in Phase 1)

Subscription billing, Stripe, paid plan enforcement, public pricing
checkout, TMS integrations, EDI, automated broker communication,
WhatsApp, SMS, live GPS tracking, driver mobile apps, automated claim
submission or negotiation, AI agents, AI eligibility decisions, automatic
broker rule discovery, complex analytics, predictive reporting,
multi-currency conversion, white-label, reseller portals, enterprise SSO.
See `docs/ROADMAP.md`.
