# Marketing and Acquisition (v1.2.2)

The public marketing site: the pages a visitor sees before they
have an account, the forms that capture them, and the wiring that
carries them into the trial.

## Pages

All pages share `frontend/src/components/layout/MarketingLayout.tsx`
(header, footer, analytics tracker). The authenticated app keeps its
own shell in `AppShell.tsx`; the marketing pages never require a
session.

| Route | What it is |
| ----- | ---------- |
| `/` | Landing: the promise, the four-step workflow, the operations-team section, pricing and demo calls to action. |
| `/product` | Product overview: who it is for (dispatch agencies, 2-20 truck reefer/spot fleets), the seven-step workflow, and what it is and is not. |
| `/features` | Feature index. |
| `/features/[slug]` | One page per feature: claims, evidence, deadlines, PDF packages, reports, multi-tenancy and roles, billing and trials. Each page lists its honest limits. |
| `/pricing` | The five listed tiers from v0.5.0 section 8, monthly/annual toggle (annual is 10x monthly, two months free), the 30-day trial callout, and an FAQ. Prices are hypotheses being tested, not prices set in stone; the page says so. |
| `/demo` | Interactive walkthrough: seven steps through the PLT-1001 detention claim, plus the full demo set (twelve synthetic shipments, eleven claims, $2,440 requested). All data is the v0.6.0 pilot sample: synthetic, labelled as such. |
| `/demo-request` | Demo request form. Posts to `POST /v1/leads` with source `demo_request`. |
| `/screenshots` | Gallery of app screens. See "Screenshots" below. |
| `/getting-started` | The 30-day path and the seven first steps, mirroring the in-app onboarding checklist. |
| `/privacy`, `/terms` | Placeholders pending legal review (unchanged from v1.0.1). |

Shared content lives in `frontend/src/lib/marketing.ts`: plan data,
feature copy, demo steps and shipments, the screenshot manifest, and
the route list used by the sitemap. The pricing page is a static
server component reading that module; the smoke test checks it
agrees with `GET /v1/plans`.

## Lead capture

`POST /v1/leads` (public, 5/min). Fields: name and email required;
company, fleet size, message, and source optional. Source is one of
`demo_request`, `pricing`, `getting_started`. The endpoint is
idempotent by email and source: a second submission within 90 days
updates the existing lead and returns 200 instead of creating a
duplicate. No IP address or user agent is stored.

Leads have no admin UI yet. The operator reviews them with:

```sh
php artisan leads:list                # new leads, newest first
php artisan leads:list --status=all   # every lead
php artisan leads:list --status=contacted --limit=50
```

Statuses: new, contacted, qualified, converted, closed. Marking a
lead is a database update for now; a UI for it belongs to a later
version.

## Analytics

The marketing tracker (`frontend/src/lib/analytics.ts`) posts
page-view and conversion events to `POST /v1/analytics/events`
(public, 60/min). The backend driver is `config/analytics.php`:

- `log` (default): one log line per event on the default channel.
- `none`: events are accepted and discarded.

Events are anonymous: event name, path, optional referrer. The
tracker is off unless `NEXT_PUBLIC_ANALYTICS_ENABLED` is `"true"`,
and sending is fire-and-forget: a failure never breaks the page.

A real provider (Google Analytics 4, Plausible) is not wired in:
it needs a measurement ID that only Aqib can supply. When he does,
add a driver in `App\Services\AnalyticsService` that forwards
events to that provider. The endpoint, the event shape, and the
frontend tracker stay the same.

## SEO

- Per-page metadata (title, description, canonical, Open Graph,
  Twitter card) via `frontend/src/lib/seo.ts`.
- JSON-LD `SoftwareApplication` on the home and pricing pages.
- `frontend/src/app/sitemap.ts` and `robots.ts`.

All absolute URLs need `NEXT_PUBLIC_SITE_URL`. Until the site has
a real domain, the sitemap is empty and robots.txt lists no
sitemap: a sitemap pointing at a placeholder domain would be
worse than none. Set the variable when the marketing site is
deployed and the routes start emitting absolute URLs.

## Trial wiring

The pricing page links to `/register?plan=<key>`. The register
page stores the choice in localStorage (`claimtrail_signup_plan`)
and shows a note; the in-app onboarding page reads it back and
offers the matching trial through the existing
`POST /billing/trials` endpoint (30 days on Growth, no card).
Starting or dismissing the banner clears the stored choice. The
banner only shows for an organization on the Free plan that is
not already trialing. Nothing here changes the billing rules.

## Screenshots

The gallery at `/screenshots` renders a real image when a capture
exists in `frontend/public/screenshots/<key>.png`, and a labelled
placeholder frame when it does not. The keys are in
`SCREENSHOT_ENTRIES` in `src/lib/marketing.ts`: dashboard,
shipments, claim-detail, evidence, deadlines, billing, reports.

No real captures exist yet: there is no headless browser on the
build VM, and a CSS mockup dressed up as a screenshot would be a
fabrication. To capture them:

1. Run the app (backend + frontend) and sign in to an organization
   with real-looking data, or import the v0.6.0 pilot sample
   (`docs/validation/pilot/sample-shipments.csv`) and walk a claim
   through.
2. Screenshot each screen at desktop width (1280px or wider).
   Crop to the content; do not include the browser chrome.
3. Save as PNG named `<key>.png` in `frontend/public/screenshots/`
   (dashboard, shipments, claim-detail, evidence, deadlines,
   billing, reports).
4. Check the page: each capture should show the real screen, with
   no real customer data visible. The pilot sample data is
   synthetic and safe to show.
5. Commit the PNGs with the rest of the site.

## What is still placeholder

- Product screenshots: the gallery is built, the images are not
  (see above). The checklist item stays unchecked until real
  captures land.
- Analytics provider: the log driver works; GA4/Plausible needs
  Aqib's measurement ID.
- Privacy policy and terms: placeholders pending legal review
  (v1.2.3 reviews them).
- The site URL: `NEXT_PUBLIC_SITE_URL` is unset until the
  marketing site has a domain.
