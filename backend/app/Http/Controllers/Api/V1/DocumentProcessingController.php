<?php

namespace App\Http\Controllers\Api\V1;

use App\DocumentClassification;
use App\Http\Controllers\Controller;
use App\Models\DocumentExtraction;
use App\Models\DocumentExtractionField;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Permission;
use App\Services\DocumentProcessingService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AI document processing (v2.0.1).
 *
 * An evidence file is processed by the local provider: text
 * extraction, rule-based classification, structured field
 * extraction with a confidence each, shipment reference
 * matching, and duplicate detection. Only an exact reference
 * match auto-links a shipment; anything weaker lands in the
 * human verification queue, where a named role approves,
 * corrects, or rejects each field. Accuracy is measured only
 * from human decisions, never claimed as a benchmark.
 *
 * There is no live OCR service: the local provider reads text
 * a document already carries and cannot read scanned images.
 * Processing and verification need manage-document-processing
 * (owner, admin, operations manager, dispatcher) and the
 * Starter plan or better: the Free plan is refused with a
 * 402. Any member may list extractions and read accuracy.
 */
class DocumentProcessingController extends Controller
{
    public function __construct(
        protected DocumentProcessingService $documents,
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

    /**
     * Process one evidence file. Needs
     * manage-document-processing and a plan that includes
     * document processing. Idempotent: an evidence file that
     * already has an extraction returns it.
     */
    public function process(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        $validated = $request->validate([
            'evidence_file_id' => [
                'required', 'uuid',
                Rule::exists('evidence_files', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $evidence = EvidenceFile::forOrganization($organization->getKey())
            ->whereKey($validated['evidence_file_id'])
            ->firstOrFail();

        $extraction = $this->documents->process($evidence, $request->user());

        return response()->json([
            'data' => $this->documents->presentExtraction($extraction),
        ], 201);
    }

    /**
     * List the organization's extractions, newest first. Any
     * member may list. Filters: status, classification,
     * shipment_id, is_duplicate.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(\App\DocumentExtractionStatus::values())],
            'classification' => ['nullable', 'string', Rule::in(DocumentClassification::values())],
            'shipment_id' => ['nullable', 'uuid'],
            // Query strings carry booleans as text ("true" /
            // "false" / "1" / "0"), which the boolean rule
            // rejects, so the filter accepts those spellings
            // and casts with $request->boolean().
            'is_duplicate' => ['nullable', 'string', Rule::in(['true', 'false', '1', '0'])],
        ]);

        $extractions = DocumentExtraction::forOrganization($organization->getKey())
            ->with(['fields', 'matchedShipment', 'duplicateOf'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['classification'] ?? null, fn ($query, $classification) => $query->where('classification', $classification))
            ->when($validated['shipment_id'] ?? null, fn ($query, $id) => $query->where('matched_shipment_id', $id))
            ->when(array_key_exists('is_duplicate', $validated), fn ($query) => $query->where('is_duplicate', $request->boolean('is_duplicate')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => array_map(
                fn (DocumentExtraction $extraction) => $this->documents->presentExtraction($extraction),
                $extractions->items(),
            ),
            'meta' => $this->paginationMeta($extractions),
        ]);
    }

    /**
     * The human verification queue: extractions that need a
     * person, oldest first so the longest-waiting document is
     * reviewed first. Any member may read it.
     */
    public function queue(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $extractions = DocumentExtraction::forOrganization($organization->getKey())
            ->with(['fields', 'matchedShipment', 'duplicateOf'])
            ->where('status', \App\DocumentExtractionStatus::NeedsVerification->value)
            ->orderBy('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => array_map(
                fn (DocumentExtraction $extraction) => $this->documents->presentExtraction($extraction),
                $extractions->items(),
            ),
            'meta' => $this->paginationMeta($extractions),
        ]);
    }

    /**
     * Extraction accuracy, measured only from human
     * decisions. Any member may read it.
     */
    public function accuracy(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'data' => $this->documents->accuracy($organization),
        ]);
    }

    /**
     * One extraction with its fields. Any member may read.
     */
    public function show(Request $request, DocumentExtraction $extraction): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->documents->presentExtraction($extraction),
        ]);
    }

    /**
     * Decide on one extracted field. Needs
     * manage-document-processing and a plan that includes
     * document processing.
     */
    public function verifyField(Request $request, DocumentExtraction $extraction, DocumentExtractionField $field): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()
            || $field->extraction_id !== $extraction->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['verified', 'corrected', 'rejected'])],
            'corrected_value' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->documents->presentField($this->documents->verifyField(
                $field,
                $request->user(),
                $validated['decision'],
                $validated['corrected_value'] ?? null,
                $validated['note'] ?? null,
            )),
        ]);
    }

    /**
     * Mark a whole extraction verified. Needs
     * manage-document-processing. A classification may be
     * supplied when the classifier could not name the
     * document.
     */
    public function verify(Request $request, DocumentExtraction $extraction): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        $validated = $request->validate([
            'classification' => ['nullable', 'string', Rule::in(DocumentClassification::verifiableValues())],
        ]);

        try {
            $verified = $this->documents->verifyExtraction(
                $extraction,
                $request->user(),
                $validated['classification'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'data' => $this->documents->presentExtraction($verified),
        ]);
    }

    /**
     * Reject an extraction as not usable. Needs
     * manage-document-processing.
     */
    public function reject(Request $request, DocumentExtraction $extraction): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $rejected = $this->documents->rejectExtraction(
                $extraction,
                $request->user(),
                $validated['reason'],
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'data' => $this->documents->presentExtraction($rejected),
        ]);
    }

    /**
     * Link a shipment to an extraction by hand. Needs
     * manage-document-processing.
     */
    public function linkShipment(Request $request, DocumentExtraction $extraction): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        $validated = $request->validate([
            'shipment_id' => [
                'required', 'uuid',
                Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $shipment = Shipment::forOrganization($organization->getKey())
            ->whereKey($validated['shipment_id'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->documents->presentExtraction($this->documents->linkShipment(
                $extraction,
                $request->user(),
                $shipment,
            )),
        ]);
    }

    /**
     * Dismiss the reference match: a human decided this
     * document belongs to no shipment. Needs
     * manage-document-processing.
     */
    public function dismissMatch(Request $request, DocumentExtraction $extraction): JsonResponse
    {
        $organization = $this->organization($request);

        if ($extraction->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDocumentProcessing->value, $organization);
        $this->entitlements->authorizeDocumentProcessing($organization);

        return response()->json([
            'data' => $this->documents->presentExtraction($this->documents->dismissMatch(
                $extraction,
                $request->user(),
            )),
        ]);
    }
}
