<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Permission;
use App\Services\ApiKeyService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Public API key management (v2.0.2).
 *
 * Keys are managed through the internal API by members with
 * manage-api-keys (owner, admin, operations manager,
 * dispatcher) on a Starter plan or better: the Free plan is
 * refused with a 402. The full key is returned exactly once,
 * in the create and rotate responses, under `plain_text_key`;
 * every other response carries only the prefix, the scopes,
 * and the status. Listing never reveals a usable key.
 */
class ApiKeyController extends Controller
{
    public function __construct(
        protected ApiKeyService $keys,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * List the organization's keys, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageApiKeys->value, $organization);

        $keys = ApiKey::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => array_map(
                fn (ApiKey $key) => $this->keys->present($key),
                $keys->items(),
            ),
            'meta' => [
                'current_page' => $keys->currentPage(),
                'last_page' => $keys->lastPage(),
                'per_page' => $keys->perPage(),
                'total' => $keys->total(),
            ],
        ]);
    }

    /**
     * Create a key. The full key is returned once, under
     * plain_text_key, and never again.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageApiKeys->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['nullable', 'string', Rule::in([ApiKey::KIND_STANDARD, ApiKey::KIND_BROKER])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKeyService::scopes())],
            'broker_name' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $issued = $this->keys->create(
            $organization,
            $request->user(),
            $validated['name'],
            $validated['scopes'],
            $validated['kind'] ?? ApiKey::KIND_STANDARD,
            $validated['broker_name'] ?? null,
            $validated['expires_at'] ?? null,
        );

        return response()->json([
            'data' => $this->keys->present($issued['key']),
            'plain_text_key' => $issued['plain_text'],
        ], 201);
    }

    /**
     * One key.
     */
    public function show(Request $request, ApiKey $apiKey): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageApiKeys->value, $organization);

        if ($apiKey->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->keys->present($apiKey),
        ]);
    }

    /**
     * Rotate a key: issue its replacement and move the old
     * key into its grace window. The replacement's full key
     * is returned once, under plain_text_key.
     */
    public function rotate(Request $request, ApiKey $apiKey): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageApiKeys->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        if ($apiKey->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $issued = $this->keys->rotate($apiKey, $request->user());

        return response()->json([
            'data' => $this->keys->present($issued['key']),
            'plain_text_key' => $issued['plain_text'],
            'rotated_from' => $this->keys->present($apiKey->fresh()),
        ]);
    }

    /**
     * Revoke a key. Immediate and permanent.
     */
    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageApiKeys->value, $organization);

        if ($apiKey->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $revoked = $this->keys->revoke($apiKey, $request->user());

        return response()->json([
            'data' => $this->keys->present($revoked),
        ]);
    }
}
