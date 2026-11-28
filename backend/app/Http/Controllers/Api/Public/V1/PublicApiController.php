<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Broker\MockBrokerAdapter;
use App\ClaimCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ShipmentResource;
use App\Models\ApiKey;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\DocumentExtraction;
use App\Models\Organization;
use App\Models\Shipment;
use App\Services\ApiKeyService;
use App\Services\ClaimReferenceService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public REST API (v2.0.2).
 *
 * A separate surface from the internal /api/v1 API the
 * frontend uses: /api/public/v1, authenticated by Bearer
 * API key (see AuthenticateApiKey), never by user session.
 * Every endpoint is scoped: a key without the required
 * scope gets a 403, and a key from another organization
 * sees nothing — unknown ids answer 404, never 403, so a
 * key cannot probe for another org's records.
 *
 * The read shape reuses the internal resources, so the
 * public API and the frontend agree on what a claim or a
 * shipment looks like. Write is deliberately narrow: a key
 * may create a claim for one of its organization's
 * shipments, and nothing else. Status changes, payments,
 * and package generation stay in the internal API, where a
 * named user is accountable. Broker keys are read/status
 * only: they can read claims and ask the mock broker
 * adapter how a claim reads from the broker's side, and
 * nothing else. There is no submission channel in this
 * version: no endpoint sends a claim package to a broker.
 */
class PublicApiController extends Controller
{
    public function __construct(
        protected ClaimReferenceService $references,
        protected EntitlementService $entitlements,
        protected MockBrokerAdapter $broker,
    ) {}

    /**
     * The API key that authenticated this request.
     */
    protected function apiKey(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    /**
     * The organization the key belongs to.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Refuse the request when the key lacks a scope.
     */
    protected function requireScope(Request $request, string $scope): void
    {
        if (! $this->apiKey($request)->hasScope($scope)) {
            abort(403, 'This API key does not grant the '.$scope.' scope.');
        }
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default = 25): int
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
     * Service information: version, the scopes this key
     * carries, and the rate limit. No authentication data
     * is ever included.
     */
    public function index(Request $request): JsonResponse
    {
        $key = $this->apiKey($request);

        return response()->json([
            'data' => [
                'service' => 'ClaimTrail Public API',
                'version' => 'v1',
                'app_version' => config('app.version'),
                'organization_id' => $key->organization_id,
                'key_id' => $key->getKey(),
                'key_kind' => $key->kind,
                'scopes' => $key->scopes ?? [],
                'rate_limit_per_minute' => (int) config('public_api.rate_limit_per_minute', 120),
            ],
        ]);
    }

    /**
     * List the organization's claims, newest first.
     * Needs claims:read.
     */
    public function listClaims(Request $request): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_CLAIMS_READ);
        $organization = $this->organization($request);

        $claims = Claim::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => ClaimResource::collection($claims),
            'meta' => $this->paginationMeta($claims),
        ]);
    }

    /**
     * One claim. Needs claims:read. A claim from another
     * organization answers 404.
     */
    public function showClaim(Request $request, Claim $claim): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_CLAIMS_READ);
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event'])),
        ]);
    }

    /**
     * Create a claim for one of the organization's
     * shipments. Needs claims:write. The claim is created
     * in the potential state with the organization's next
     * reference; the monthly claim cap still applies, so a
     * key cannot create claims past the plan limit.
     */
    public function storeClaim(Request $request): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_CLAIMS_WRITE);
        $organization = $this->organization($request);

        $this->entitlements->authorizeClaimCreation($organization);

        $validated = $request->validate([
            'shipment_id' => [
                'required', 'uuid',
                Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey()),
            ],
            'claim_category' => ['nullable', 'string', Rule::in(ClaimCategory::values())],
            'description' => ['nullable', 'string', 'max:5000'],
            'requested_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'external_broker_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $claim = $organization->claims()->create(array_merge($validated, [
            'claim_reference' => $this->references->nextReference($organization),
            'claim_category' => $validated['claim_category'] ?? ClaimCategory::Other->value,
            'custom_field_values' => [],
        ]));

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event'])),
        ], 201);
    }

    /**
     * List the organization's shipments, newest first.
     * Needs shipments:read.
     */
    public function listShipments(Request $request): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_SHIPMENTS_READ);
        $organization = $this->organization($request);

        $shipments = Shipment::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => ShipmentResource::collection($shipments),
            'meta' => $this->paginationMeta($shipments),
        ]);
    }

    /**
     * One shipment. Needs shipments:read.
     */
    public function showShipment(Request $request, Shipment $shipment): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_SHIPMENTS_READ);
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => new ShipmentResource($shipment),
        ]);
    }

    /**
     * List recorded claim payments, newest first. Needs
     * payments:read. Payments are read-only on the public
     * API: recording a payment stays in the internal API,
     * where a named user is accountable.
     */
    public function listPayments(Request $request): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_PAYMENTS_READ);
        $organization = $this->organization($request);

        $payments = ClaimPayment::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(
                fn (ClaimPayment $payment) => $this->presentPayment($payment),
                $payments->items(),
            ),
            'meta' => $this->paginationMeta($payments),
        ]);
    }

    /**
     * List document extractions, newest first. Needs
     * documents:read. Only the extraction summary is
     * exposed: the extracted text and the document file
     * stay in the internal API.
     */
    public function listDocuments(Request $request): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_DOCUMENTS_READ);
        $organization = $this->organization($request);

        $documents = DocumentExtraction::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => array_map(
                fn (DocumentExtraction $document) => $this->presentDocument($document),
                $documents->items(),
            ),
            'meta' => $this->paginationMeta($documents),
        ]);
    }

    /**
     * How one claim reads from the broker's side, through
     * the mock broker adapter. Needs broker:status, so only
     * broker keys (and standard keys explicitly granted the
     * scope, which the key service never issues) may call
     * it. The view is derived only from the claim as
     * recorded; no broker system is contacted.
     */
    public function brokerClaimStatus(Request $request, Claim $claim): JsonResponse
    {
        $this->requireScope($request, ApiKeyService::SCOPE_BROKER_STATUS);
        $organization = $this->organization($request);
        $key = $this->apiKey($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->broker->statusFor($claim, $key->broker_name ?? 'Broker'),
        ]);
    }

    /**
     * The public presentation of a recorded payment.
     *
     * @return array<string, mixed>
     */
    protected function presentPayment(ClaimPayment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'organization_id' => $payment->organization_id,
            'claim_id' => $payment->claim_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method?->value,
            'payment_reference' => $payment->payment_reference,
            'paid_at' => $payment->paid_at?->toDateString(),
            'notes' => $payment->notes,
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The public presentation of a document extraction:
     * classification, status, and shipment link only.
     *
     * @return array<string, mixed>
     */
    protected function presentDocument(DocumentExtraction $document): array
    {
        return [
            'id' => $document->getKey(),
            'organization_id' => $document->organization_id,
            'classification' => $document->classification?->value,
            'status' => $document->status?->value,
            'shipment_id' => $document->matched_shipment_id,
            'is_duplicate' => (bool) $document->is_duplicate,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
