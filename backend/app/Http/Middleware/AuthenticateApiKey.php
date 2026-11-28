<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer API key authentication for the public API (v2.0.2).
 *
 * The public surface (/api/public/v1) never accepts user
 * session tokens: it takes `Authorization: Bearer <key>`,
 * hashes the presented key with SHA-256, and looks the hash
 * up. An unknown, revoked, expired, or out-of-grace key is
 * refused with a 401. A key on a Free-plan organization is
 * refused with a 402: the public API is a Starter+ feature.
 *
 * Rate limiting is per key (config
 * public_api.rate_limit_per_minute, 120 by default): a key
 * over the limit gets a 429 with a Retry-After header in
 * seconds. The limit is per key, not per organization, so
 * one noisy integration cannot starve another key on the
 * same account.
 *
 * On success the resolved key is attached to the request
 * as `api_key`, and last_used_at is updated. Scope checks
 * live in the public controller, not here: authentication
 * answers "who is this key", authorization answers "may
 * this key do this".
 */
class AuthenticateApiKey
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $key = ApiKey::query()
            ->where('key_hash', ApiKeyService::hash($token))
            ->first();

        if ($key === null || ! $key->isUsable()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $organization = $key->organization;

        if ($organization === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $this->entitlements->authorizePublicApi($organization);
        } catch (\App\Exceptions\EntitlementExceededException $e) {
            return $e->render();
        }

        $rate = $this->checkRateLimit($key);

        if ($rate !== null) {
            return response()->json(
                ['message' => 'Too many requests.'],
                429,
                ['Retry-After' => (string) $rate],
            );
        }

        $key->update(['last_used_at' => now()]);

        $request->attributes->set('api_key', $key);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }

    /**
     * Per-key fixed-window rate limit. Returns the seconds
     * until the window resets when the key is over the
     * limit, or null when the request may proceed.
     */
    protected function checkRateLimit(ApiKey $key): ?int
    {
        $limit = (int) config('public_api.rate_limit_per_minute', 120);
        $window = (int) now()->format('Hi');
        $cacheKey = 'public_api_rate:'.$key->getKey().':'.$window;

        $count = (int) Cache::get($cacheKey, 0);

        if ($count >= $limit) {
            $resetAt = now()->copy()->startOfMinute()->addMinute();

            return (int) max(1, now()->diffInSeconds($resetAt));
        }

        Cache::put($cacheKey, $count + 1, now()->addMinute());

        return null;
    }
}
