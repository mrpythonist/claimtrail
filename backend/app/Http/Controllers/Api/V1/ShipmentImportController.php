<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportMapping;
use App\Models\Organization;
use App\Permission;
use App\Services\ImportService;
use App\Services\ShipmentCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShipmentImportController extends Controller
{
    public function __construct(
        protected ShipmentCsvImporter $importer,
        protected ImportService $imports,
    ) {}

    /**
     * Preview a CSV import without creating any shipments.
     *
     * Accepts the file plus an optional column mapping: a JSON object
     * of exact header text to shipment field name, for headers the
     * built-in aliases do not recognize. A saved mapping may be
     * named with mapping_id instead; a JSON mapping in the same
     * request wins over the saved one for the headers it names.
     */
    public function preview(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ImportShipments->value, $organization);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable', 'json'],
            'mapping_id' => ['nullable', 'uuid', Rule::exists('import_mappings', 'id')->where('organization_id', $organization->getKey())],
        ]);

        $mapping = [];

        if ($request->filled('mapping_id')) {
            $saved = ImportMapping::forOrganization($organization)->findOrFail($request->input('mapping_id'));
            $mapping = $saved->mapping ?? [];
        }

        if ($request->filled('mapping')) {
            $decoded = json_decode((string) $request->input('mapping'), true);
            $mapping = array_merge($mapping, is_array($decoded) ? $decoded : []);
        }

        $result = $this->importer->preview(
            $request->file('file'),
            $organization->getKey(),
            $mapping
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Confirm a previewed import and create the selected rows.
     *
     * Rows come from the server-side preview payload. Only valid,
     * non-duplicate rows are created; the summary reports what was
     * imported and why each skipped row was skipped.
     */
    public function confirm(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ImportShipments->value, $organization);

        $validated = $request->validate([
            'preview_token' => ['required', 'string'],
            'row_indexes' => ['nullable', 'array'],
            'row_indexes.*' => ['integer', 'min:1'],
            'mapping_id' => ['nullable', 'uuid', Rule::exists('import_mappings', 'id')->where('organization_id', $organization->getKey())],
        ]);

        $result = $this->importer->confirm(
            $validated['preview_token'],
            $organization->getKey(),
            $validated['row_indexes'] ?? null,
            $request->user()->id
        );

        $run = $this->imports->recordRun(
            $organization,
            $request->user(),
            'upload',
            $result,
            $validated['mapping_id'] ?? null,
        );

        return response()->json(['data' => [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'skipped_rows' => $result['skipped_rows'],
            'filename' => $result['filename'],
            'import_run_id' => (string) $run->getKey(),
        ]]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
