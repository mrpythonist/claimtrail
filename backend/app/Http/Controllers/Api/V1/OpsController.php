<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\ErrorLogService;
use App\Services\FailedJobService;
use App\Services\QueueMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Operations endpoints for organization admins: queue depth,
 * failed jobs (retry, forget, retry-all, flush), and recent
 * errors. Everything here is tenant-scoped: failed jobs and
 * errors are shown only when they belong to the resolved
 * organization, and queue totals are bare counts with no payload
 * content.
 */
class OpsController extends Controller
{
    public function __construct(
        protected QueueMonitorService $queues,
        protected FailedJobService $failedJobs,
        protected ErrorLogService $errors,
    ) {}

    /**
     * Queue depth by queue name, plus totals.
     */
    public function queue(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        return response()->json(['data' => $this->queues->stats()]);
    }

    /**
     * Failed jobs of the organization, newest first, paginated.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $all = $this->queues->failedJobsForOrganization($organization->getKey());

        $paginator = new LengthAwarePaginator(
            array_slice($all, ($page - 1) * $perPage, $perPage),
            count($all),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Retry one failed job: re-queue its original payload.
     */
    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        if (! $this->failedJobs->retry($uuid, $organization->getKey())) {
            abort(404, 'Failed job not found.');
        }

        return response()->json(['message' => 'Failed job re-queued.']);
    }

    /**
     * Retry every failed job of the organization.
     */
    public function retryAllFailedJobs(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        $result = $this->failedJobs->retryAll($organization->getKey());

        return response()->json(['data' => $result]);
    }

    /**
     * Delete one failed job without retrying it.
     */
    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        if (! $this->failedJobs->forget($uuid, $organization->getKey())) {
            abort(404, 'Failed job not found.');
        }

        return response()->json(['message' => 'Failed job deleted.']);
    }

    /**
     * Delete the organization's failed jobs older than the given
     * hours (default 168, one week).
     */
    public function flushFailedJobs(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        $validated = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        $deleted = $this->failedJobs->flushOlderThan(
            $organization->getKey(),
            $validated['hours'] ?? 168
        );

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    /**
     * Recent server errors for the organization, newest first.
     */
    public function errors(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewOperations->value, $organization);

        $limit = max(1, min(100, (int) $request->input('limit', 50)));

        return response()->json([
            'data' => $this->errors->recentForOrganization($organization->getKey(), $limit),
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
