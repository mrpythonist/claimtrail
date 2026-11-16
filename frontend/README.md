# ClaimTrail frontend

Next.js 16 (App Router), React 19, TypeScript, Tailwind CSS 4. See the
root `README.md` and `docs/` for the full picture.

## Quickstart

```sh
npm install
cp .env.example .env.local   # set NEXT_PUBLIC_API_URL if the API is not on localhost:8000
npm run dev                  # http://localhost:3000
```

## Tests and build

```sh
npm test                     # Vitest (component + validation tests)
npm run lint                 # ESLint
npm run build                # Production build with type check
```

## Layout

- `src/app/` - routes: landing, auth pages, and the guarded `(app)` group
  (dashboard, settings)
- `src/components/ui/` - reusable components (Button, Input, Card, Table,
  Modal, and more)
- `src/components/layout/` - AppShell and AuthLayout
- `src/lib/` - API client, auth context, permissions, validation, types
