<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\ShipmentCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShipmentImportController extends Controller
{
    public function __construct(
        protected ShipmentCsvImporter $importer,
    ) {}

    /**
     * Preview a CSV import without creating any shipments.
     *
     * Accepts the file plus an optional column mapping: a JSON object
     * of exact header text to shipment field name, for headers the
     * built-in aliases do not recognize.
     */
    public function preview(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ImportShipments->value, $organization);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable', 'json'],
        ]);

        $mapping = [];

        if ($request->filled('mapping')) {
            $decoded = json_decode((string) $request->input('mapping'), true);
            $mapping = is_array($decoded) ? $decoded : [];
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
        ]);

        $result = $this->importer->confirm(
            $validated['preview_token'],
            $organization->getKey(),
            $validated['row_indexes'] ?? null,
            $request->user()->id
        );

        return response()->json(['data' => $result]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
