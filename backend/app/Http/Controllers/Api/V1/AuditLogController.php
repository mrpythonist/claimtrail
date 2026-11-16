<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only audit trail.
 *
 * There are intentionally no store, update, or delete actions:
 * audit rows are append-only, written by AuditService from model
 * observers and services. Listing needs the audit view permission
 * (owner, admin, operations manager).
 */
class AuditLogController extends Controller
{
    /**
     * List audit rows of the resolved organization, newest first,
     * with filters for entity type, action, actor, and date range.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewAuditLogs->value, $organization);

        $logs = AuditLog::forOrganization($organization->getKey())
            ->with('actor')
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('actor_id'), fn ($query) => $query->where('actor_id', $request->string('actor_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->string('date_to')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => AuditLogResource::collection($logs),
            'meta' => $this->paginationMeta($logs),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
