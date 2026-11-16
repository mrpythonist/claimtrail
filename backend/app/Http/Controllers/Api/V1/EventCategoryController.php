<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventCategoryResource;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EventCategoryController extends Controller
{
    /**
     * List event categories visible to the organization: the system
     * categories plus the organization's own custom ones.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewEvents->value, $organization);

        $categories = EventCategory::visibleTo($organization)
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => EventCategoryResource::collection($categories),
        ]);
    }

    /**
     * Add a custom event category for the organization.
     *
     * The slug must not collide with a system category or another
     * custom category of the same organization.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::CreateEvents->value, $organization);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $slug = Str::slug($validated['label']);

        if ($slug === '') {
            return response()->json([
                'message' => 'The label must contain letters or numbers.',
            ], 422);
        }

        $collision = EventCategory::visibleTo($organization)
            ->where('slug', $slug)
            ->exists();

        if ($collision) {
            return response()->json([
                'message' => 'A category with this name already exists.',
            ], 422);
        }

        $category = EventCategory::create([
            'organization_id' => $organization->getKey(),
            'slug' => $slug,
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'data' => new EventCategoryResource($category),
        ], 201);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
