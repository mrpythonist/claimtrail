<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Marketing-site analytics events (v1.2.2).
 *
 * The endpoint always answers 202: recording is best-effort and
 * must never break the page that sent the event. The default "log"
 * driver writes one log line per event; the "none" driver discards
 * them.
 */
class AnalyticsEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_is_accepted_and_logged(): void
    {
        Log::spy();

        $response = $this->postJson('/api/v1/analytics/events', [
            'event' => 'page_view',
            'path' => '/pricing',
            'referrer' => '/product',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.accepted', true);

        Log::shouldHaveReceived('info')
            ->with('analytics.event', [
                'event' => 'page_view',
                'path' => '/pricing',
                'referrer' => '/product',
            ]);
    }

    public function test_unknown_event_names_are_rejected(): void
    {
        $this->postJson('/api/v1/analytics/events', [
            'event' => 'free_text_anything',
            'path' => '/pricing',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['event']);
    }

    public function test_missing_path_is_rejected(): void
    {
        $this->postJson('/api/v1/analytics/events', [
            'event' => 'page_view',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);
    }

    public function test_none_driver_accepts_without_logging(): void
    {
        config(['analytics.driver' => 'none']);
        Log::spy();

        $this->postJson('/api/v1/analytics/events', [
            'event' => 'page_view',
            'path' => '/',
        ])->assertAccepted();

        Log::shouldNotHaveReceived('info');
    }

    public function test_event_needs_no_authentication(): void
    {
        Log::spy();

        $this->postJson('/api/v1/analytics/events', [
            'event' => 'page_view',
            'path' => '/',
        ])->assertAccepted();
    }
}
