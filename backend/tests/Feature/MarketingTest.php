<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public marketing endpoints (v1.2.2): the plan catalog, lead
 * capture, and the leads:list command.
 */
class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_plans_returns_listed_plans_only(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.trial.plan', 'growth')
            ->assertJsonPath('data.trial.days', 30)
            ->assertJsonPath('data.trial.requires_card', false);

        $plans = $response->json('data.plans');
        $this->assertCount(5, $plans);
        $this->assertSame(
            ['free', 'starter', 'growth', 'fleet', 'agency'],
            array_column($plans, 'key'),
        );

        $byKey = array_column($plans, null, 'key');
        $this->assertSame(0, $byKey['free']['monthly_cents']);
        $this->assertSame(1900, $byKey['starter']['monthly_cents']);
        $this->assertSame(4900, $byKey['growth']['monthly_cents']);
        $this->assertSame(9900, $byKey['fleet']['monthly_cents']);
        $this->assertSame(14900, $byKey['agency']['monthly_cents']);
        $this->assertSame(1200, $byKey['agency']['per_extra_carrier_cents']);
        $this->assertSame(10, $byKey['agency']['included_carriers']);
        $this->assertTrue($byKey['growth']['listed']);
    }

    public function test_public_plans_needs_no_authentication(): void
    {
        $this->getJson('/api/v1/plans')->assertOk();
    }

    public function test_lead_store_creates_a_lead(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'company' => 'Prairie Line Transport',
            'fleet_size' => '3-10 trucks',
            'message' => 'We would like a demo for our dispatch team.',
            'source' => 'demo_request',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Dana Whitfield')
            ->assertJsonPath('data.email', 'dana@example.com')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.source', 'demo_request');

        $this->assertDatabaseHas('leads', [
            'email' => 'dana@example.com',
            'company' => 'Prairie Line Transport',
            'status' => 'new',
        ]);
    }

    public function test_lead_store_defaults_source_to_demo_request(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'name' => 'Marcus Webb',
            'email' => 'marcus@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.source', 'demo_request');
    }

    public function test_lead_store_validates_input(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'name' => '',
            'email' => 'not-an-email',
            'source' => 'nowhere',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'source']);
    }

    public function test_lead_store_updates_instead_of_duplicating_a_recent_lead(): void
    {
        Lead::create([
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'source' => 'demo_request',
            'company' => 'Old Company',
            'status' => 'contacted',
        ]);

        $response = $this->postJson('/api/v1/leads', [
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'company' => 'Prairie Line Transport',
            'source' => 'demo_request',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.company', 'Prairie Line Transport')
            ->assertJsonPath('data.status', 'contacted');

        $this->assertSame(1, Lead::where('email', 'dana@example.com')->count());
    }

    public function test_lead_store_treats_a_different_source_as_a_new_lead(): void
    {
        Lead::create([
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'source' => 'demo_request',
        ]);

        $this->postJson('/api/v1/leads', [
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'source' => 'pricing',
        ])->assertCreated();

        $this->assertSame(2, Lead::where('email', 'dana@example.com')->count());
    }

    public function test_marketing_routes_have_separate_throttle_buckets(): void
    {
        // Regression: Laravel's guest throttle key is domain + IP
        // only, so without a per-route prefix the plan catalog,
        // lead capture, and analytics endpoints share one bucket
        // and five pricing-page loads would block a demo request.
        // Five plan loads must not consume any of the five lead
        // submissions, and the sixth lead submission is still
        // throttled.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/plans')->assertOk();
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/leads', [
                'name' => 'Lead '.$i,
                'email' => "lead{$i}@example.com",
            ])->assertCreated();
        }

        $this->postJson('/api/v1/leads', [
            'name' => 'Lead 6',
            'email' => 'lead6@example.com',
        ])->assertTooManyRequests();
    }

    public function test_leads_list_command_shows_new_leads(): void
    {
        Lead::create([
            'name' => 'Dana Whitfield',
            'email' => 'dana@example.com',
            'company' => 'Prairie Line Transport',
            'status' => 'new',
        ]);
        Lead::create([
            'name' => 'Tom Becker',
            'email' => 'tom@example.com',
            'status' => 'contacted',
        ]);

        $this->artisan('leads:list')
            ->expectsOutputToContain('dana@example.com')
            ->doesntExpectOutputToContain('tom@example.com')
            ->assertExitCode(0);
    }

    public function test_leads_list_command_rejects_an_unknown_status(): void
    {
        $this->artisan('leads:list', ['--status' => 'bogus'])
            ->assertExitCode(1);
    }

    public function test_leads_list_command_reports_when_empty(): void
    {
        $this->artisan('leads:list')
            ->expectsOutputToContain('No leads found.')
            ->assertExitCode(0);
    }
}
