<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SecurityPolicy;
use App\Models\User;

/**
 * Organization security policies (v2.0.3).
 *
 * One policy row per organization; until a row exists the
 * config defaults apply. Only the password rules are enforced
 * by code, on password change (AuthController::updatePassword
 * and the reset flow consult validatePassword): a password
 * that fails the organization's own policy is refused with
 * the reasons listed. Allowed IP ranges are check-only — the
 * checkIp endpoint reports whether an address falls inside
 * them, and nothing at the edge enforces them. Two-factor is
 * recorded policy only: no second factor exists yet, so
 * require_two_factor can be set but changes nothing at login.
 * Session timeout and max concurrent sessions are recorded
 * and reported; the session lifetime itself stays a deployment
 * setting. The doc (docs/ENTERPRISE-READINESS.md) states each
 * of these limits plainly.
 */
class SecurityPolicyService
{
    /**
     * The config defaults, as stored values.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = (array) config('enterprise.security_policy_defaults', []);

        return [
            'password_min_length' => (int) ($defaults['password_min_length'] ?? 8),
            'password_require_numbers' => (bool) ($defaults['password_require_numbers'] ?? false),
            'password_require_symbols' => (bool) ($defaults['password_require_symbols'] ?? false),
            'session_timeout_minutes' => (int) ($defaults['session_timeout_minutes'] ?? 120),
            'max_concurrent_sessions' => $defaults['max_concurrent_sessions'] ?? null,
            'allowed_ip_ranges' => (array) ($defaults['allowed_ip_ranges'] ?? []),
            'require_two_factor' => (bool) ($defaults['require_two_factor'] ?? false),
        ];
    }

    /**
     * The organization's policy row, if it has set one.
     */
    public function policyFor(Organization $organization): ?SecurityPolicy
    {
        return SecurityPolicy::forOrganization($organization->getKey())->first();
    }

    /**
     * The effective policy: the organization's row, or the
     * config defaults when no row exists.
     *
     * @return array<string, mixed>
     */
    public function effective(Organization $organization): array
    {
        $policy = $this->policyFor($organization);

        if ($policy === null) {
            return array_merge($this->defaults(), ['is_default' => true]);
        }

        return array_merge($this->present($policy), ['is_default' => false]);
    }

    /**
     * Create or replace the organization's policy row.
     */
    public function update(Organization $organization, array $data): SecurityPolicy
    {
        $policy = $this->policyFor($organization) ?? new SecurityPolicy([
            'organization_id' => $organization->getKey(),
        ]);

        $policy->fill($data);
        $policy->save();

        return $policy->fresh();
    }

    /**
     * The password rules, as the API presents them.
     *
     * @return array<string, mixed>
     */
    public function present(SecurityPolicy $policy): array
    {
        return [
            'password_min_length' => (int) $policy->password_min_length,
            'password_require_numbers' => (bool) $policy->password_require_numbers,
            'password_require_symbols' => (bool) $policy->password_require_symbols,
            'session_timeout_minutes' => (int) $policy->session_timeout_minutes,
            'max_concurrent_sessions' => $policy->max_concurrent_sessions !== null
                ? (int) $policy->max_concurrent_sessions
                : null,
            'allowed_ip_ranges' => array_values((array) ($policy->allowed_ip_ranges ?? [])),
            'require_two_factor' => (bool) $policy->require_two_factor,
        ];
    }

    /**
     * Check a password against the organization's effective
     * policy. Returns the list of reasons it fails, empty when
     * it passes.
     *
     * @return array<int, string>
     */
    public function validatePassword(string $password, Organization $organization): array
    {
        return $this->validatePasswordWithPolicy($password, $this->effective($organization));
    }

    /**
     * Check a password against a policy array (an effective
     * policy, or the defaults for a user with no organization).
     *
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    public function validatePasswordWithPolicy(string $password, array $policy): array
    {
        $errors = [];

        if (mb_strlen($password) < (int) ($policy['password_min_length'] ?? 8)) {
            $errors[] = 'Password must be at least '.(int) ($policy['password_min_length'] ?? 8).' characters long.';
        }

        if (! empty($policy['password_require_numbers']) && ! preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if (! empty($policy['password_require_symbols']) && ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one symbol.';
        }

        return $errors;
    }

    /**
     * The strictest password policy across a user's active
     * organizations: the longest minimum length, and numbers
     * or symbols required when any organization requires them.
     * Used by the password reset flow, where the user is
     * identified by email and no single organization is in
     * context. A user with no organization gets the defaults.
     *
     * @return array<string, mixed>
     */
    public function strictestPolicyForUser(User $user): array
    {
        $policy = $this->defaults();

        foreach ($user->organizations()->get() as $organization) {
            $effective = $this->effective($organization);
            $policy['password_min_length'] = max(
                (int) $policy['password_min_length'],
                (int) $effective['password_min_length'],
            );
            $policy['password_require_numbers'] = (bool) $policy['password_require_numbers']
                || (bool) $effective['password_require_numbers'];
            $policy['password_require_symbols'] = (bool) $policy['password_require_symbols']
                || (bool) $effective['password_require_symbols'];
        }

        return $policy;
    }

    /**
     * Whether an IP address is inside the organization's
     * allowed ranges (check-only: nothing enforces this).
     *
     * @return array{ip: string, allowed: bool, in_range: bool|null, ranges: array<int, string>}
     */
    public function checkIp(string $ip, Organization $organization): array
    {
        $policy = $this->effective($organization);
        $ranges = array_values((array) ($policy['allowed_ip_ranges'] ?? []));

        if ($ranges === []) {
            return ['ip' => $ip, 'allowed' => true, 'in_range' => null, 'ranges' => []];
        }

        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, (string) $range)) {
                return ['ip' => $ip, 'allowed' => true, 'in_range' => true, 'ranges' => $ranges];
            }
        }

        return ['ip' => $ip, 'allowed' => false, 'in_range' => false, 'ranges' => $ranges];
    }

    /**
     * IPv4 CIDR / single-address match. IPv6 addresses only
     * match an exact range string; CIDR matching is IPv4 only
     * in this version, and that limit is documented.
     */
    protected function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$network, $prefix] = explode('/', $range, 2);

            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);

            if ($ipLong === false || $networkLong === false) {
                return false;
            }

            $prefix = (int) $prefix;

            if ($prefix < 0 || $prefix > 32) {
                return false;
            }

            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));

            return ($ipLong & $mask) === ($networkLong & $mask);
        }

        return $ip === $range;
    }
}
