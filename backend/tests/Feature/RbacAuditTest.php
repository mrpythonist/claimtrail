<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RBAC security audit (v1.1.3).
 *
 * Two parts. First, the role matrix on sensitive endpoints: a
 * role that must not perform an action gets a 403 (not a 404,
 * not a silent success). Second, a sweep over every registered
 * API route: every route except the documented public ones must
 * require authentication, and every tenant-scoped route must
 * resolve an organization. The matrix itself is documented in
 * docs/SECURITY.md.
 */
class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_write_shipments_or_claims(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/shipments', [
            'internal_reference' => 'CT-VIEWER-1',
            'broker_or_customer_name' => 'Test Broker',
        ])->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/claims', [
            'shipment_id' => '00000000-0000-0000-0000-000000000000',
            'claim_category' => 'detention',
            'requested_amount' => 100,
            'currency' => 'USD',
        ])->assertForbidden();
    }

    public function test_billing_staff_cannot_manage_organization_or_members(): void
    {
        [$user, $organization, $member] = $this->actingAsMemberOf(OrganizationRole::BillingStaff);

        $this->withHeaders($this->orgHeaders($organization))->putJson('/api/v1/members/'.$member->getKey(), [
            'role' => 'admin',
        ])->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))->postJson('/api/v1/invitations', [
            'email' => 'someone@example.com',
            'role' => 'viewer',
        ])->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'name' => 'Hijacked Name',
        ])->assertForbidden();
    }

    public function test_dispatcher_limits(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $event = $this->createEvent($shipment);
        $claim = $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::UnderReview,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/events/'.$event->getKey())
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review', [
                'decision' => 'approve',
            ])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/ops/health')
            ->assertForbidden();
    }

    public function test_viewer_cannot_read_audit_logs_or_security_areas(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/ops/health')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/backups')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/retention')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/security/check')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/security/events')->assertForbidden();
    }

    public function test_operations_manager_cannot_manage_security(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);

        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/backups')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/security/check')->assertForbidden();
        $this->withHeaders($this->orgHeaders($organization))->putJson('/api/v1/retention', [
            'audit_logs' => 30,
        ])->assertForbidden();
    }

    public function test_admin_can_use_the_security_areas(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/backups')->assertOk();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/retention')->assertOk();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/security/check')->assertOk();
        $this->withHeaders($this->orgHeaders($organization))->getJson('/api/v1/security/events')->assertOk();
    }

    public function test_every_api_route_except_the_public_ones_requires_authentication(): void
    {
        // Method + URI pairs that are intentionally public.
        $public = [
            ['GET', 'api/v1/health'],
            ['POST', 'api/v1/auth/register'],
            ['POST', 'api/v1/auth/login'],
            ['POST', 'api/v1/auth/forgot-password'],
            ['POST', 'api/v1/auth/reset-password'],
            ['GET', 'api/v1/email/verify/{id}/{hash}'],
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                if (in_array([$method, $uri], $public, true)) {
                    continue;
                }

                $this->assertContains(
                    'auth:sanctum',
                    $route->gatherMiddleware(),
                    $method.' '.$uri.' is missing auth:sanctum.'
                );
            }
        }
    }

    public function test_every_tenant_scoped_route_resolves_an_organization(): void
    {
        // Authenticated routes that are intentionally not
        // tenant-scoped: account-level auth, invitation
        // acceptance, and the organization routes themselves
        // (they address the organization in the URL).
        $nonTenant = [
            'api/v1/auth/logout',
            'api/v1/auth/me',
            'api/v1/auth/profile',
            'api/v1/auth/password',
            'api/v1/email/verification-notification',
            'api/v1/invitations/accept',
            'api/v1/organizations',
            'api/v1/organizations/{organization}',
            'api/v1/organizations/{organization}/switch',
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            if (in_array($uri, $nonTenant, true)) {
                $this->assertNotContains(
                    'organization',
                    $middleware,
                    $uri.' must stay outside the organization middleware.'
                );

                continue;
            }

            $this->assertContains(
                'organization',
                $middleware,
                $uri.' is authenticated but does not resolve an organization.'
            );
        }
    }

    public function test_the_route_sweep_actually_covered_routes(): void
    {
        $count = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $count++;
            }
        }

        // Guards against the sweep silently matching nothing if
        // the route list ever stops loading in tests.
        $this->assertGreaterThan(90, $count);
    }
}
