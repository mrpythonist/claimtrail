<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records security-relevant events that do not belong in the
 * tenant audit trail: failed logins (no organization is known
 * yet) and authorization denials (about the request, not a
 * business entity).
 *
 * Recording never throws: a failed write is logged and dropped,
 * so event recording can never break a login attempt or an
 * error response.
 */
class SecurityEventService
{
    /**
     * Record one event.
     */
    public function record(
        string $type,
        ?Organization $organization = null,
        ?User $user = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): ?SecurityEvent {
        try {
            return SecurityEvent::create([
                'organization_id' => $organization?->getKey(),
                'user_id' => $user?->getKey(),
                'type' => $type,
                'ip_address' => $ipAddress,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Security event could not be recorded.', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a failed login attempt.
     *
     * The email is stored so repeated failures against one
     * account can be counted; the password is never stored.
     */
    public function recordLoginFailed(string $email, Request $request): ?SecurityEvent
    {
        return $this->record(
            SecurityEvent::TYPE_LOGIN_FAILED,
            null,
            null,
            ['email' => $email],
            $request->ip(),
        );
    }

    /**
     * Record an authorization denial for the current request.
     *
     * The organization is taken from the resolved current
     * organization when one exists; the user from the signed-in
     * user. Denials without either are still recorded.
     */
    public function recordAuthorizationDenied(Request $request): ?SecurityEvent
    {
        $organization = null;

        try {
            $current = app(CurrentOrganization::class);

            if ($current->check()) {
                $organization = $current->get();
            }
        } catch (\Throwable) {
            $organization = null;
        }

        return $this->record(
            SecurityEvent::TYPE_AUTHORIZATION_DENIED,
            $organization,
            $request->user(),
            [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
            ],
            $request->ip(),
        );
    }

    /**
     * How many failed logins an email has had recently.
     */
    public function failedLoginCount(string $email, int $minutes = 15): int
    {
        return SecurityEvent::query()
            ->where('type', SecurityEvent::TYPE_LOGIN_FAILED)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->whereJsonContains('metadata->email', $email)
            ->count();
    }

    /**
     * The most recent events for one organization, newest first.
     *
     * @return Collection<int, SecurityEvent>
     */
    public function recentForOrganization(string $organizationId, int $limit = 50)
    {
        return SecurityEvent::forOrganization($organizationId)
            ->orderByDesc('created_at')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }
}
