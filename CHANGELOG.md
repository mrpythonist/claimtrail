# Changelog

All notable changes to ClaimTrail are documented here. The format follows
Keep a Changelog, and the project follows semantic versioning.

## [1.0.1] - Unreleased

Phase 1, in progress. Milestones 0 and 1 are complete.

### Added

- Milestone 0: development environment probe. PHP 8.3.6, Composer 2.7.1,
  Laravel 13.42.0, Sanctum 4.4, Next.js 16.4.6, React 19.3.0, Tailwind
  CSS 4.3.3, Vitest 5.1.0.
- Milestone 1: authentication API (register, login, logout, forgot
  password, reset password, email verification, profile and password
  update) with rate limiting.
- Milestone 1: multi-tenant organizations with member invitations,
  activation and deactivation, member removal, and organization switching.
- Milestone 1: role-based access control with six roles, one central
  permission map, gates, policies, and owner protections.
- Milestone 1: tenant isolation enforced on the backend (header
  resolution middleware, global query scope, 404 for cross-tenant
  access).
- Milestone 1: Next.js application shell with sidebar navigation,
  organization switcher, user menu, and pages for landing, auth,
  dashboard, organization settings, members and invitations, and profile
  settings.
- Milestone 1: Docker Compose development setup (backend, queue worker,
  frontend, PostgreSQL 16, Redis 7) and Dockerfiles.
- Milestone 1: documentation (README, architecture, database, API,
  OpenAPI, security, development, scope, roadmap) and this changelog.
- Milestone 1: 51 backend feature tests and 16 frontend tests.

### Notes

- The original brief specified Laravel 11. `composer create-project`
  installs the current stable release, so the project uses Laravel 13.
- Docker builds were not verified on the build machine (no Docker
  available there). See `docs/DEVELOPMENT.md`.
- The CI workflow is not in the repository yet; workflow files cannot be
  pushed through the GitHub API and must be added through the web UI.
