<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Marketing-site analytics (v1.2.2).
 *
 * The frontend tracker posts page-view and conversion events to
 * POST /v1/analytics/events and this service decides where they
 * go, based on config/analytics.php. The event shape is the same
 * for every driver, so swapping in a real provider (GA4, Plausible)
 * later means adding a driver here, not changing the endpoint or
 * the frontend.
 *
 * Drivers:
 * - "log" (default): one log line per event, on the default log
 *   channel. The test suite spies on the Log facade, so the
 *   service must use Log::info() (not Log::channel('single')),
 *   or a spied LogManager returns null for channel().
 * - "none": accepted and discarded.
 *
 * Events are anonymous by design: the tracker sends an event name,
 * a path, and an optional referrer. No IP address, user agent, or
 * account data is recorded.
 */
class AnalyticsService
{
    /**
     * The event names the marketing site is allowed to send.
     * Anything else is rejected at the controller, so the log
     * stays a record of real visitor actions, not free text.
     *
     * @return array<int, string>
     */
    public static function allowedEvents(): array
    {
        return [
            'page_view',
            'demo_request_submitted',
            'trial_started',
            'pricing_viewed',
        ];
    }

    /**
     * Record one event. Returns true when the event was recorded
     * by a driver, false when the driver discards it.
     *
     * @param  array{event: string, path: string, referrer?: string|null}  $event
     */
    public function record(array $event): bool
    {
        $driver = (string) config('analytics.driver', 'log');

        if ($driver === 'none') {
            return false;
        }

        Log::info('analytics.event', [
            'event' => $event['event'],
            'path' => $event['path'],
            'referrer' => $event['referrer'] ?? null,
        ]);

        return true;
    }
}
