<?php

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
|
| The public REST API (v2.0.2) is a separate surface from the
| internal /api/v1 API the frontend uses: /api/public/v1,
| authenticated by Bearer API key, never by user session.
|
| rate_limit_per_minute: requests allowed per API key per
| minute. A key over the limit gets a 429 with a Retry-After
| header in seconds. The limit is per key, not per
| organization, so one noisy integration cannot starve
| another key on the same account.
|
| rotation_grace_hours: how long a rotated (old) key keeps
| working after its replacement is issued. After the window
| the old key is refused with a 401. Revocation is immediate
| and is not a grace window.
|
| webhook_max_attempts: bounded retries per delivery. The
| delays are in seconds and apply in order; after the last
| attempt the delivery is recorded failed, never retried
| again and never silently dropped.
|
| webhook_timeout_seconds: per-attempt HTTP timeout for a
| webhook delivery. A slow endpoint fails the attempt and
| the retry policy applies.
|
| key_prefix: the visible prefix of every issued key. The
| full key is shown exactly once at creation.
|
*/

return [
    'rate_limit_per_minute' => (int) env('PUBLIC_API_RATE_LIMIT_PER_MINUTE', 120),

    'rotation_grace_hours' => (int) env('PUBLIC_API_ROTATION_GRACE_HOURS', 24),

    'webhook_max_attempts' => (int) env('PUBLIC_API_WEBHOOK_MAX_ATTEMPTS', 5),

    'webhook_retry_delays' => array_map(
        'intval',
        explode(',', (string) env('PUBLIC_API_WEBHOOK_RETRY_DELAYS', '60,300,900,1800')),
    ),

    'webhook_timeout_seconds' => (int) env('PUBLIC_API_WEBHOOK_TIMEOUT_SECONDS', 10),

    'key_prefix' => 'ct_live_',
];
