<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\EntitlementService;
use App\Services\SecurityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Organization security policies (v2.0.3).
 *
 * The effective policy is the organization's own row, or the
 * config defaults until one is set. Only the password rules
 * are enforced by code (on password change); allowed IP
 * ranges are check-only and two-factor is recorded policy
 * only — the check endpoints report, they do not gate access.
 * Every endpoint needs manage-enterprise (owner, admin,
 * operations manager) and the Growth plan or better.
 */
class SecurityPolicyController extends Controller
{
    public function __construct(
        protected SecurityPolicyService $policies,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The effective security policy, with its enforcement notes.
     */
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEnterprise->value, $organization);
        $this->entitlements->authorizeEnterprise($organization);

        return response()->json([
            'data' => array_merge(
                $this->policies->effective($organization),
                ['enforced' => $this->enforcementNotes()],
            ),
        ]);
    }

    /**
     * Set the organization's security policy. Only the fields
     * in the request are changed; the rest keep their current
     * value (or the default when no row exists yet).
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEnterprise->value, $organization);
        $this->entitlements->authorizeEnterprise($organization);

        $validated = $request->validate([
            'password_min_length' => ['sometimes', 'integer', 'min:8', 'max:128'],
            'password_require_numbers' => ['sometimes', 'boolean'],
            'password_require_symbols' => ['sometimes', 'boolean'],
            'session_timeout_minutes' => ['sometimes', 'integer', 'min:5', 'max:43200'],
            'max_concurrent_sessions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'allowed_ip_ranges' => ['sometimes', 'array'],
            'allowed_ip_ranges.*' => ['string', 'max:45'],
            'require_two_factor' => ['sometimes', 'boolean'],
        ]);

        $policy = $this->policies->update($organization, $validated);

        return response()->json([
            'data' => array_merge(
                $this->policies->present($policy),
                ['is_default' => false, 'enforced' => $this->enforcementNotes()],
            ),
        ]);
    }

    /**
     * Check a password against the organization's effective
     * policy, without changing anything.
     */
    public function checkPassword(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEnterprise->value, $organization);
        $this->entitlements->authorizeEnterprise($organization);

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $errors = $this->policies->validatePassword($validated['password'], $organization);

        return response()->json([
            'data' => ['valid' => $errors === [], 'errors' => $errors],
        ]);
    }

    /**
     * Check whether an IP address is inside the organization's
     * allowed ranges. Check-only: the answer does not gate
     * access anywhere.
     */
    public function checkIp(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEnterprise->value, $organization);
        $this->entitlements->authorizeEnterprise($organization);

        $validated = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        return response()->json([
            'data' => $this->policies->checkIp($validated['ip'], $organization),
        ]);
    }

    /**
     * What the policy can and cannot enforce, so the API
     * consumer never has to guess.
     *
     * @return array<string, string>
     */
    protected function enforcementNotes(): array
    {
        return [
            'password' => 'Enforced on password change: a password that fails the policy is refused.',
            'allowed_ip_ranges' => 'Check-only: the check-ip endpoint reports whether an address is inside the ranges; nothing at the edge enforces them.',
            'require_two_factor' => 'Policy only: no second factor exists yet, so requiring it changes nothing at login.',
            'session_timeout_minutes' => 'Recorded: the session lifetime itself stays a deployment setting.',
            'max_concurrent_sessions' => 'Recorded: concurrent sessions are not limited by the app yet.',
        ];
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
