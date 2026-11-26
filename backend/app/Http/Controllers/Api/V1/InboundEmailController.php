<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use App\Models\Organization;
use App\Models\Shipment;
use App\Permission;
use App\Services\EmailIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Inbound emails for one organization (v1.3.0).
 *
 * The list is the processing history: filter by status or by
 * matched/unmatched, paginated, newest first. Any member may
 * list and read emails; matching, unmatching, and dismissing
 * need manage-email-intake (owner, admin, operations manager,
 * dispatcher). Email bodies are never written to the audit
 * log; only status changes and the matched shipment are.
 */
class InboundEmailController extends Controller
{
    public function __construct(
        protected EmailIntakeService $intake,
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
     * Inbound emails of the organization, newest first.
     * Filters: status, matched (true/false).
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $query = InboundEmail::forOrganization($organization)
            ->with(['attachments', 'matchedShipment'])
            ->orderByDesc('created_at');

        if ($request->filled('status') && in_array($request->input('status'), InboundEmail::statuses(), true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('matched')) {
            $matched = filter_var($request->input('matched'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($matched === true) {
                $query->where('status', InboundEmail::STATUS_MATCHED);
            } elseif ($matched === false) {
                $query->whereIn('status', [InboundEmail::STATUS_UNMATCHED, InboundEmail::STATUS_RECEIVED, InboundEmail::STATUS_FAILED]);
            }
        }

        $emails = $query->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $emails->getCollection()->map(
                fn (InboundEmail $email) => $this->intake->emailPayload($email),
            )->all(),
            'meta' => $this->paginationMeta($emails),
        ]);
    }

    /**
     * One inbound email with its extracted references and
     * attachments.
     */
    public function show(Request $request, InboundEmail $email): JsonResponse
    {
        $organization = $this->organization($request);

        if ($email->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->intake->emailPayload($email),
        ]);
    }

    /**
     * Manually match an email to a shipment. Its attachments
     * become evidence on that shipment.
     */
    public function match(Request $request, InboundEmail $email): JsonResponse
    {
        $organization = $this->organization($request);

        if ($email->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        $validated = $request->validate([
            'shipment_id' => [
                'required', 'uuid',
                Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $shipment = Shipment::forOrganization($organization)
            ->whereKey($validated['shipment_id'])
            ->firstOrFail();

        $matched = $this->intake->matchEmail(
            $organization,
            $email,
            $shipment,
            $request->user(),
        );

        return response()->json([
            'data' => $this->intake->emailPayload($matched),
        ]);
    }

    /**
     * Unmatch an email, returning it to unmatched.
     */
    public function unmatch(Request $request, InboundEmail $email): JsonResponse
    {
        $organization = $this->organization($request);

        if ($email->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $unmatched = $this->intake->unmatchEmail(
            $organization,
            $email,
            $request->user(),
            $validated['reason'] ?? 'manually unmatched',
        );

        return response()->json([
            'data' => $this->intake->emailPayload($unmatched),
        ]);
    }

    /**
     * Dismiss an email after review, with a reason. A matched
     * email cannot be dismissed; unmatch it first.
     */
    public function dismiss(Request $request, InboundEmail $email): JsonResponse
    {
        $organization = $this->organization($request);

        if ($email->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $dismissed = $this->intake->dismissEmail(
            $organization,
            $email,
            $request->user(),
            $validated['reason'],
        );

        return response()->json([
            'data' => $this->intake->emailPayload($dismissed),
        ]);
    }
}
