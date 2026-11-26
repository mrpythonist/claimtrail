<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Permission;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Import history and error correction for one organization (v1.3.1).
 *
 * The list is the import history: filter by status or source,
 * paginated, newest first. Any member may list and read runs
 * and rows; correcting a row needs manage-imports (owner,
 * admin, operations manager, dispatcher). A correction merges
 * new field values over the stored row data, re-validates with
 * the importer's rules, re-checks the reference, and creates
 * the shipment on success.
 */
class ImportRunController extends Controller
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
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata, in the shape every list endpoint uses.
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

    /**
     * Import runs of the organization, newest first.
     * Filters: status, source.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                ImportRun::STATUS_PENDING,
                ImportRun::STATUS_RUNNING,
                ImportRun::STATUS_COMPLETED,
                ImportRun::STATUS_PARTIAL,
                ImportRun::STATUS_FAILED,
            ])],
            'source' => ['nullable', 'string', Rule::in([
                ImportRun::SOURCE_UPLOAD,
                ImportRun::SOURCE_SCHEDULE,
                ImportRun::SOURCE_SYNC,
            ])],
        ]);

        $query = ImportRun::forOrganization($organization)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }

        $runs = $query->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $runs->getCollection()
                ->map(fn (ImportRun $run) => $this->imports->runPayload($run))->all(),
            'meta' => $this->paginationMeta($runs),
        ]);
    }

    /**
     * One import run, with its rows.
     */
    public function show(Request $request, ImportRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $run->load('rows');

        return response()->json([
            'data' => array_merge(
                $this->imports->runPayload($run),
                ['rows' => $run->rows->map(fn (ImportRow $row) => $this->imports->rowPayload($row))->all()],
            ),
        ]);
    }

    /**
     * Rows of one import run, in file order.
     * Filter: status.
     */
    public function rows(Request $request, ImportRun $run): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                ImportRow::STATUS_IMPORTED,
                ImportRow::STATUS_SKIPPED,
                ImportRow::STATUS_DUPLICATE,
                ImportRow::STATUS_FAILED,
                ImportRow::STATUS_CORRECTED,
            ])],
        ]);

        $query = $run->rows()->orderBy('row_index');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $rows = $query->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $rows->getCollection()
                ->map(fn (ImportRow $row) => $this->imports->rowPayload($row))->all(),
            'meta' => $this->paginationMeta($rows),
        ]);
    }

    /**
     * Correct one failed or duplicate row and retry it.
     *
     * The body is field values merged over the stored row data.
     * A row that already imported cannot be corrected. If the
     * corrected row still fails validation, the row keeps the
     * new values and errors, and the response is 422.
     */
    public function correctRow(Request $request, ImportRun $run, ImportRow $row): JsonResponse
    {
        $organization = $this->organization($request);

        if ($run->organization_id !== $organization->getKey()
            || $row->import_run_id !== $run->getKey()
        ) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'data' => ['required', 'array', 'min:1'],
            'data.*' => ['nullable'],
        ]);

        $corrected = $this->imports->correctRow(
            $run,
            $row,
            $request->user(),
            $validated['data'],
        );

        return response()->json([
            'data' => $this->imports->rowPayload($corrected),
        ]);
    }
}
