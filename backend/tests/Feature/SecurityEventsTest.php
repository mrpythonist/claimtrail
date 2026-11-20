<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\User;
use App\OrganizationRole;
use App\Services\SecurityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security event logging (v1.1.3).
 *
 * Failed logins and authorization denials are recorded as
 * security events, visible to owners and admins of the
 * organization through GET /security/events.
 */
class SecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_login_is_recorded_as_a_security_event(): void
    {
        $user = User::factory()->create([
            'email' => 'aqib@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'aqib@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $event = SecurityEvent::query()->first();

        $this->assertNotNull($event);
        $this->assertSame(SecurityEvent::TYPE_LOGIN_FAILED, $event->type);
        $this->assertSame('aqib@example.com', $event->metadata['email']);
        $this->assertNull($event->organization_id);
        $this->assertArrayNotHasKey('password', $event->metadata);
    }

    public function test_repeated_failed_logins_can_be_counted(): void
    {
        $user = User::factory()->create([
            'email' => 'aqib@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'aqib@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->assertSame(3, app(SecurityEventService::class)->failedLoginCount('aqib@example.com'));
        $this->assertSame(0, app(SecurityEventService::class)->failedLoginCount('someone-else@example.com'));
    }

    public function test_an_authorization_denial_is_recorded_with_the_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-DENIED-1',
                'broker_or_customer_name' => 'Test Broker',
            ])->assertForbidden();

        $event = SecurityEvent::query()->first();

        $this->assertNotNull($event);
        $this->assertSame(SecurityEvent::TYPE_AUTHORIZATION_DENIED, $event->type);
        $this->assertSame($organization->getKey(), $event->organization_id);
        $this->assertSame($user->getKey(), $event->user_id);
        $this->assertSame('POST', $event->metadata['method']);
        $this->assertStringContainsString('shipments', $event->metadata['path']);
    }

    public function test_security_events_endpoint_lists_the_organizations_events(): void
    {
        [$viewer, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-DENIED-1',
                'broker_or_customer_name' => 'Test Broker',
            ])->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/security/events')
            ->assertForbidden();

        [$admin, $adminOrg] = $this->actingAsMemberOf(OrganizationRole::Admin);

        // The admin is in a different organization, so the
        // viewer's denial is not theirs to see.
        $this->withHeaders($this->orgHeaders($adminOrg))
            ->getJson('/api/v1/security/events')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // An owner of the same organization sees both denials:
        // the shipment create, and the viewer's own attempt to
        // open the security events endpoint (a 403 is a denial
        // too, whatever the route).
        $owner = User::factory()->create();
        $this->addMember($organization, $owner, OrganizationRole::Owner);
        Sanctum::actingAs($owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/security/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', SecurityEvent::TYPE_AUTHORIZATION_DENIED)
            ->assertJsonPath('data.1.type', SecurityEvent::TYPE_AUTHORIZATION_DENIED);
    }
}
