<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportMapping;
use App\Models\Organization;
use App\Models\SyncRun;
use App\Permission;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Synchronization workflows for one organization (v1.3.1).
 *
 * A sync workflow is a named, repeatable import over a saved
 * mapping and a stored file. It runs on demand and moves
 * through pending, running, completed, and failed; the linked
 * import run carries the per-row detail. No TMS or other
 * external system is involved: the file is one the
 * organization already uploaded. TMS integrations are v1.3.2.
 * Creating, running, and deleting need manage-imports (owner,
 * admin, operations manager, dispatcher). Any member may list
 * and read sync runs.
 */
class SyncRunController extends Controller
{
    public function __construct(
        protected ImportService $imports,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's sync workflows, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $syncRuns = SyncRun::forOrganization($organization)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $syncRuns->map(fn (SyncRun $syncRun) => $this->imports->syncPayload($syncRun))->all(),
        ]);
    }

    /**
     * Create a sync workflow with its source file.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'import_mapping_id' => ['nullable', 'uuid', Rule::exists('import_mappings', 'id')->where('organization_id', $organization->getKey())],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $mapping = isset($validated['import_mapping_id'])
            ? ImportMapping::forOrganization($organization)->findOrFail($validated['import_mapping_id'])
            : null;

        $key = 'imports/'.$organization->getKey().'/sync/'.Str::uuid().'.csv';
        Storage::disk(ImportService::disk())->put($key, file_get_contents($request->file('file')->getRealPath()));

        $syncRun = SyncRun::create([
            'organization_id' => $organization->getKey(),
            'name' => $validated['name'],
            'import_mapping_id' => $mapping?->getKey(),
            'source_file_path' => $key,
            'status' => SyncRun::STATUS_PENDING,
            'created_by' => $request->user()->getKey(),
        ]);

        return response()->json([
            'data' => $this->imports->syncPayload($syncRun),
        ], 201);
    }

    /**
     * One sync workflow.
     */
    public function show(Request $request, SyncRun $syncRun): JsonResponse
    {
        $organization = $this->organization($request);

        if ($syncRun->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->imports->syncPayload($syncRun),
        ]);
    }

    /**
     * Run a sync workflow now. Returns the workflow with its
     * final status and the linked import run.
     */
    public function run(Request $request, SyncRun $syncRun): JsonResponse
    {
        $organization = $this->organization($request);

        if ($syncRun->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $syncRun = $this->imports->runSync($syncRun, $request->user());

        return response()->json([
            'data' => $this->imports->syncPayload($syncRun),
        ]);
    }

    /**
     * Delete a sync workflow and its stored source file.
     * Import runs it produced stay in the history.
     */
    public function destroy(Request $request, SyncRun $syncRun): JsonResponse
    {
        $organization = $this->organization($request);

        if ($syncRun->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        Storage::disk(ImportService::disk())->delete($syncRun->source_file_path);
        $syncRun->delete();

        return response()->json(['message' => 'Sync workflow deleted.']);
    }
}
