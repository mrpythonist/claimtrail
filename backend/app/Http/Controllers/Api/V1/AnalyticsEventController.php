<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Marketing-site analytics events (v1.2.2).
 *
 * The frontend tracker posts page views and conversions here. The
 * endpoint always answers 202: recording is best-effort and must
 * never slow down or break the page that sent the event. Events
 * are anonymous: an event name, a path, and an optional referrer.
 * No IP address, user agent, or account data is recorded.
 */
class AnalyticsEventController extends Controller
{
    /**
     * Accept one analytics event.
     */
    public function store(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', Rule::in(AnalyticsService::allowedEvents())],
            'path' => ['required', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);

        $analytics->record([
            'event' => $validated['event'],
            'path' => $validated['path'],
            'referrer' => $validated['referrer'] ?? null,
        ]);

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
