<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimReviewNoteResource;
use App\Models\Claim;
use App\Models\Organization;
use App\Permission;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClaimReviewNoteController extends Controller
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    /**
     * List the reviewer notes on a claim, oldest first.
     */
    public function index(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $claim);

        $notes = $claim->reviewNotes()
            ->with('author')
            ->orderBy('created_at')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => ClaimReviewNoteResource::collection($notes),
            'meta' => $this->paginationMeta($notes),
        ]);
    }

    /**
     * Add a reviewer note to a claim.
     *
     * Notes are append-only and permission-gated as a review action.
     * Closed claims take no more notes: their record is final.
     */
    public function store(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        if ($claim->isClosed()) {
            return response()->json([
                'message' => 'A closed claim takes no more reviewer notes.',
            ], 422);
        }

        Gate::authorize(Permission::ReviewClaims->value, $organization);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $note = $claim->reviewNotes()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $request->user()->id,
            'note' => $validated['note'],
        ]);

        $this->audit->record(
            $organization,
            'claim.review_note_added',
            $claim,
            $request->user(),
            null,
            ['claim_reference' => $claim->claim_reference],
        );

        return response()->json([
            'data' => new ClaimReviewNoteResource($note->load('author')),
        ], 201);
    }

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
}
