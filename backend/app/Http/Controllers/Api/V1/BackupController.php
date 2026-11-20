<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupRunResource;
use App\Models\BackupRun;
use App\Models\Organization;
use App\Permission;
use App\Services\BackupService;
use App\Support\BackupException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Backup runs for one organization.
 *
 * Backups are deployment-wide (the whole database plus every
 * stored file), and a run is attributed to the organization
 * that requested it. Listing is scoped to the resolved
 * organization; scheduled runs have no organization and are
 * not listed here. All four endpoints require manage-security
 * (owner and admin). Restores are deliberately not exposed
 * here: restoring replaces the live database, so it stays an
 * operator action through `backup:restore`.
 */
class BackupController extends Controller
{
    public function __construct(
        protected BackupService $backups,
    ) {}

    /**
     * Backup runs of the resolved organization, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        $runs = BackupRun::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => BackupRunResource::collection($runs),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /**
     * Create a backup now and return its run row.
     *
     * A backup that cannot be made (in-memory database, missing
     * pg_dump) returns 422 with the reason instead of a fake
     * success.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        try {
            $run = $this->backups->create($organization);
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new BackupRunResource($run),
        ], 201);
    }

    /**
     * One backup run. The global scope does not apply at binding
     * time (binding runs before the organization middleware), so
     * the organization check is explicit, like the other
     * controllers: a run of another organization is a 404.
     */
    public function show(Request $request, BackupRun $backup): JsonResponse
    {
        $organization = $this->organization($request);

        if ($backup->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        return response()->json([
            'data' => new BackupRunResource($backup),
        ]);
    }

    /**
     * Re-verify a backup's checksums without restoring it.
     */
    public function verify(Request $request, BackupRun $backup): JsonResponse
    {
        $organization = $this->organization($request);

        if ($backup->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        return response()->json([
            'data' => $this->backups->verify($backup),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
