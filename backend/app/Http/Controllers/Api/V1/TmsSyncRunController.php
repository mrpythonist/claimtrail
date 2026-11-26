<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TmsSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TMS sync runs for one organization (v1.3.2).
 *
 * The monitoring view for TMS syncs: every run an
 * integration performed, whatever started it (an API run,
 * the tms:sync command, or the tms:run-due scheduler),
 * with its kind, status, per-outcome summary, and failure
 * reason. Any member may list and read sync runs; syncs
 * themselves are started from the integration endpoints
 * and need manage-tms-integrations.
 */
class TmsSyncRunController extends Controller
{
    public function __construct(
        protected \App\Services\TmsService $tms,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's TMS sync runs, newest first.
     * Filters: tms_integration_id, kind, status.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'tms_integration_id' => ['nullable', 'uuid', Rule::exists('tms_integrations', 'id')->where('organization_id', $organization->getKey())],
            'kind' => ['nullable', 'string', Rule::in(TmsSyncRun::kinds())],
            'status' => ['nullable', 'string', Rule::in([TmsSyncRun::STATUS_PENDING, TmsSyncRun::STATUS_RUNNING, TmsSyncRun::STATUS_COMPLETED, TmsSyncRun::STATUS_FAILED])],
        ]);

        $query = TmsSyncRun::forOrganization($organization)->orderByDesc('created_at');

        if (! empty($validated['tms_integration_id'])) {
            $query->where('tms_integration_id', $validated['tms_integration_id']);
        }

        if (! empty($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $runs = $query->get();

        return response()->json([
            'data' => $runs->map(fn (TmsSyncRun $run) => $this->tms->syncRunPayload($run))->all(),
        ]);
    }

    /**
     * One TMS sync run.
     */
    public function show(Request $request, TmsSyncRun $syncRun): JsonResponse
    {
        $organization = $this->organization($request);

        if ($syncRun->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->tms->syncRunPayload($syncRun),
        ]);
    }
}
