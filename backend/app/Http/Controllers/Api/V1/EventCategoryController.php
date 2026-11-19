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
     *
     * The list changes rarely, so it carries an ETag: a client
     * that sends the ETag back in If-None-Match gets a 304 with
     * no body instead of the full list.
     */
    public function index(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewEvents->value, $organization);

        $categories = EventCategory::visibleTo($organization)
            ->orderBy('label')
            ->get();

        $payload = [
            'data' => EventCategoryResource::collection($categories),
        ];

        $etag = '"'.md5((string) json_encode($payload)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response(null, 304)->header('ETag', $etag);
        }

        return response()->json($payload)->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=0, must-revalidate');
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
            'default_estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'default_currency' => ['nullable', 'string', 'size:3', 'alpha'],
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
            'default_estimated_amount' => $validated['default_estimated_amount'] ?? null,
            'default_currency' => $validated['default_currency'] ?? null,
        ]);

        return response()->json([
            'data' => new EventCategoryResource($category),
        ], 201);
    }

    /**
     * Update an organization-owned event category.
     *
     * System categories belong to no organization and cannot be
     * changed: they return 404, as do another organization's
     * categories. Renaming recomputes the slug and checks it against
     * every category visible to the organization.
     */
    public function update(Request $request, EventCategory $category): JsonResponse
    {
        $organization = $this->organization($request);

        if ($category->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'default_estimated_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ]);

        if (isset($validated['label'])) {
            $slug = Str::slug($validated['label']);

            if ($slug === '') {
                return response()->json([
                    'message' => 'The label must contain letters or numbers.',
                ], 422);
            }

            $collision = EventCategory::visibleTo($organization)
                ->where('slug', $slug)
                ->where('id', '!=', $category->getKey())
                ->exists();

            if ($collision) {
                return response()->json([
                    'message' => 'A category with this name already exists.',
                ], 422);
            }

            $validated['slug'] = $slug;
        }

        $category->update($validated);

        return response()->json([
            'data' => new EventCategoryResource($category->fresh()),
        ]);
    }

    /**
     * Delete an organization-owned event category.
     *
     * Categories with events cannot be deleted: disable them
     * instead. The database foreign key is restrict, so this check
     * returns a clean 422 before the database would.
     */
    public function destroy(Request $request, EventCategory $category): JsonResponse
    {
        $organization = $this->organization($request);

        if ($category->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        if ($category->events()->exists()) {
            return response()->json([
                'message' => 'This category cannot be deleted because events use it. Disable it instead.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Event category deleted.']);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
