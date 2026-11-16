<?php

namespace App\Http\Controllers\Api\V1;

use App\DeadlineStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimDeadlineResource;
use App\Models\Claim;
use App\Models\ClaimDeadline;
use App\Models\Organization;
use App\Permission;
use App\Services\DeadlineStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClaimDeadlineController extends Controller
{
    /**
     * List active deadlines of the resolved organization, with
     * status and upcoming-window filters.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewDeadlines->value, $organization);

        $deadlines = $organization->claimDeadlines()->getQuery()
            ->active()
            ->with('claim')
            ->when(
                $request->filled('status') && in_array($request->string('status')->toString(), DeadlineStatus::values(), true),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->when(
                $request->filled('upcoming_days') && is_numeric($request->input('upcoming_days')),
                fn ($query) => $query->where('deadline_at', '<=', now()->addDays((int) $request->input('upcoming_days')))
            )
            ->orderBy('deadline_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => ClaimDeadlineResource::collection($deadlines),
            'meta' => $this->paginationMeta($deadlines),
        ]);
    }

    /**
     * Show one deadline.
     */
    public function show(Request $request, ClaimDeadline $deadline): JsonResponse
    {
        $organization = $this->organization($request);

        if ($deadline->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $deadline);

        $deadline->load('claim');

        return response()->json([
            'data' => new ClaimDeadlineResource($deadline),
        ]);
    }

    /**
     * Set the deadline for a claim.
     *
     * Upsert semantics: when the claim already has an active
     * deadline, the old row is superseded (kept for history) and a
     * new active row is created. The deadline is supplied by the
     * customer: nothing here infers a deadline from the event type
     * or any broker rule.
     */
    public function store(Request $request, Claim $claim, DeadlineStatusService $statuses): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageDeadlines->value, $organization);

        $validated = $request->validate($this->rules());

        $previous = $claim->activeDeadline()->first();

        if ($previous !== null) {
            $previous->update(['superseded_at' => now()]);
        }

        $deadline = $claim->deadlines()->create(array_merge($validated, [
            'organization_id' => $organization->getKey(),
        ]));

        $deadline->update(['status' => $statuses->compute($deadline->fresh()->load('claim'))]);

        return response()->json([
            'data' => new ClaimDeadlineResource($deadline->fresh()->load('claim')),
        ], 201);
    }

    /**
     * Update a deadline.
     */
    public function update(Request $request, ClaimDeadline $deadline, DeadlineStatusService $statuses): JsonResponse
    {
        $organization = $this->organization($request);

        if ($deadline->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $deadline);

        $rules = $this->rules();

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_merge(['sometimes'], $fieldRules);
        }

        $validated = $request->validate($rules);

        $deadline->update($validated);
        $deadline->update(['status' => $statuses->compute($deadline->fresh()->load('claim'))]);

        return response()->json([
            'data' => new ClaimDeadlineResource($deadline->fresh()->load('claim')),
        ]);
    }

    /**
     * Validation rules for deadline input.
     *
     * The timezone must be a real PHP timezone identifier, because
     * deadline status is judged in that timezone.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'deadline_at' => ['required', 'date'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'reminder_days_before' => ['nullable', 'array', 'max:10'],
            'reminder_days_before.*' => ['integer', 'min:0', 'max:90'],
        ];
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
