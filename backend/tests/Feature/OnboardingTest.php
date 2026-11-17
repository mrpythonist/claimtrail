<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_reports_live_progress_for_a_new_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/onboarding');

        $response->assertOk()
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.total_count', 7)
            ->assertJsonPath('data.all_completed', false)
            ->assertJsonPath('data.dismissed', false)
            ->assertJsonPath('data.steps.0.key', 'create_organization')
            ->assertJsonPath('data.steps.0.completed', true)
            ->assertJsonPath('data.steps.1.key', 'invite_member')
            ->assertJsonPath('data.steps.1.completed', false);
    }

    public function test_onboarding_steps_complete_as_the_team_works(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->createEvent($shipment);
        $this->createEvidence($organization, $shipment);
        $this->createClaim($organization, $shipment, ['status' => ClaimStatus::ReadyToSubmit]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/onboarding');

        $response->assertOk()
            ->assertJsonPath('data.completed_count', 6)
            ->assertJsonPath('data.all_completed', false);

        $steps = collect($response->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps['create_shipment']['completed']);
        $this->assertTrue($steps['add_event']['completed']);
        $this->assertTrue($steps['upload_evidence']['completed']);
        $this->assertTrue($steps['create_claim']['completed']);
        $this->assertTrue($steps['review_claim']['completed']);
        $this->assertFalse($steps['invite_member']['completed']);
    }

    public function test_an_invitation_completes_the_invite_member_step(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $organization->invitations()->create([
            'email' => 'teammate@example.com',
            'role' => OrganizationRole::Dispatcher,
            'token' => 'test-token',
            'expires_at' => now()->addDays(7),
            'invited_by' => $user->id,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/onboarding')
            ->assertOk()
            ->assertJsonPath('data.completed_count', 2);
    }

    public function test_a_manager_can_dismiss_onboarding_and_a_viewer_cannot(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/onboarding/dismiss')
            ->assertOk()
            ->assertJsonPath('data.dismissed', true);

        $this->assertNotNull($organization->fresh()->onboarding_dismissed_at);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/onboarding')
            ->assertOk()
            ->assertJsonPath('data.dismissed', true);

        [$viewer] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/onboarding/dismiss')
            ->assertForbidden();
    }

    public function test_onboarding_is_tenant_scoped(): void
    {
        [$user, $orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $orgB = $this->createOrganization(['name' => 'Other Org']);

        // The user is not a member of org B, so the middleware rejects it.
        $this->withHeaders($this->orgHeaders($orgB))
            ->getJson('/api/v1/onboarding')
            ->assertNotFound();

        $this->assertInstanceOf(Organization::class, $orgA);
    }
}
