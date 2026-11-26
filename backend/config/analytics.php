<?php

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Marketing-site analytics for ClaimTrail (v1.2.2). The frontend
| posts page-view and conversion events to POST /v1/analytics/events
| and the driver below decides where they go.
|
| Drivers:
| - "log" (default): events are written to the Laravel log, one
|   line per event. That is the whole integration for now: it is
|   verifiable without any third-party account, and the operator
|   can grep the log for what visitors did.
| - "none": events are accepted and discarded. Use it when the
|   marketing site runs somewhere the log should stay quiet.
|
| A real provider (Google Analytics 4 or Plausible) is not wired
| in: it needs a measurement ID / domain that only Aqib can supply,
| and a third-party script tag on the marketing pages. When he
| supplies one, add a driver here that forwards events to that
| provider instead of replacing the endpoint or the frontend
| tracker. The event shape stays the same either way.
*/

return [
    'driver' => env('ANALYTICS_DRIVER', 'log'),
];
