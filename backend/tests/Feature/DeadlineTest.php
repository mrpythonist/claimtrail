<?php

namespace Tests\Feature;

use App\Models\ClaimDeadline;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function deadlinePayload(array $overrides = []): array
    {
        return array_merge([
            'deadline_at' => now()->addDays(30)->toISOString(),
            'timezone' => 'America/Chicago',
            'source_reference' => 'Rate confirmation, section 4.',
            'reminder_days_before' => [7, 3, 1],
        ], $overrides);
    }

    public function test_a_deadline_can_be_set_for_a_claim(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'upcoming')
            ->assertJsonPath('data.timezone', 'America/Chicago')
            ->assertJsonPath('data.reminder_days_before', [7, 3, 1]);
    }

    public function test_deadline_timezone_must_be_valid(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'timezone' => 'Not/AZone',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone']);
    }

    public function test_a_viewer_cannot_manage_deadlines(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload())
            ->assertForbidden();
    }

    public function test_setting_a_deadline_twice_supersedes_the_first(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload())
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'deadline_at' => now()->addDays(45)->toISOString(),
            ]))
            ->assertCreated();

        $this->assertNotNull(ClaimDeadline::find($first)->superseded_at);
        $this->assertSame(1, ClaimDeadline::where('claim_id', $claim->getKey())->active()->count());
    }

    public function test_a_deadline_inside_the_reminder_window_is_due_soon(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'deadline_at' => now()->addDays(3)->toISOString(),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'due_soon');
    }

    public function test_a_passed_deadline_on_an_unsubmitted_claim_is_overdue(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'deadline_at' => now()->subDay()->toISOString(),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'overdue');
    }

    public function test_a_claim_submitted_on_time_meets_its_deadline(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'deadline_at' => now()->addDays(10)->toISOString(),
            ]))
            ->assertCreated();

        foreach (['under_review', 'ready_to_submit', 'submitted'] as $step) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                    'to_status' => $step,
                ])
                ->assertOk();
        }

        $this->artisan('claimtrail:refresh-deadline-statuses')->assertExitCode(0);

        $this->assertSame(
            'met',
            ClaimDeadline::where('claim_id', $claim->getKey())->active()->first()->status->value
        );
    }

    public function test_a_claim_submitted_late_misses_its_deadline(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'status' => 'submitted',
            'submitted_at' => now()->subDays(2),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/deadline", $this->deadlinePayload([
                'deadline_at' => now()->subDays(5)->toISOString(),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'missed');
    }

    public function test_the_refresh_command_recomputes_stale_statuses(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $deadline = $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->subDay(),
            'timezone' => 'UTC',
            'status' => 'upcoming',
        ]);

        $this->artisan('claimtrail:refresh-deadline-statuses')
            ->expectsOutputToContain('1 changed')
            ->assertExitCode(0);

        $this->assertSame('overdue', $deadline->fresh()->status->value);
    }

    public function test_deadlines_are_listed_with_a_status_filter(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claimA = $this->createClaim($organization, $shipment, ['claim_reference' => 'CLM-2026-0001']);
        $claimB = $this->createClaim($organization, $shipment, ['claim_reference' => 'CLM-2026-0002']);

        $claimA->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->subDay(),
            'timezone' => 'UTC',
            'status' => 'overdue',
        ]);
        $claimB->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => now()->addDays(30),
            'timezone' => 'UTC',
            'status' => 'upcoming',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/deadlines?status=overdue')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.claim.claim_reference', 'CLM-2026-0001');
    }

    public function test_a_user_cannot_read_another_organizations_deadline(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organizationA);
        $claim = $this->createClaim($organizationA, $shipment);
        $deadline = $claim->deadlines()->create([
            'organization_id' => $organizationA->getKey(),
            'deadline_at' => now()->addDays(10),
            'timezone' => 'UTC',
            'status' => 'upcoming',
        ]);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson("/api/v1/deadlines/{$deadline->getKey()}")
            ->assertNotFound();
    }
}
