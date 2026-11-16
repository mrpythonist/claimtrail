<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationMemberResource;
use App\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\OrganizationRole;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrganizationMemberController extends Controller
{
    /**
     * List members of the resolved organization, with search and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewMembers->value, $organization);

        $members = $organization->members()
            ->with('user')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->whereHas('user', fn ($userQuery) => $userQuery
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search));
            })
            ->orderBy('created_at')
            ->paginate(25);

        return response()->json([
            'data' => OrganizationMemberResource::collection($members),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    /**
     * Change a member's role or status.
     *
     * Owner protections: only an owner can touch another owner's
     * membership, only an owner can grant the owner role, and the last
     * active owner cannot be demoted or deactivated.
     */
    public function update(Request $request, OrganizationMember $member): JsonResponse
    {
        $organization = $this->organization($request);

        if ($member->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(OrganizationRole::values())],
            'status' => ['sometimes', 'string', Rule::in(MembershipStatus::values())],
        ]);

        $actor = $request->user()->membershipIn($organization);
        $actorIsOwner = $actor !== null && $actor->role === OrganizationRole::Owner;
        $targetIsOwner = $member->role === OrganizationRole::Owner;

        if ($targetIsOwner && ! $actorIsOwner) {
            return response()->json(['message' => 'Only an owner can change an owner membership.'], 403);
        }

        if (isset($validated['role'])
            && $validated['role'] === OrganizationRole::Owner->value
            && ! $actorIsOwner
        ) {
            return response()->json(['message' => 'Only an owner can grant the owner role.'], 403);
        }

        if (isset($validated['role'])
            && $targetIsOwner
            && $validated['role'] !== OrganizationRole::Owner->value
            && $this->isLastActiveOwner($organization, $member)
        ) {
            return response()->json(['message' => 'The last owner cannot be demoted. Transfer ownership first.'], 422);
        }

        if (isset($validated['status'])
            && $validated['status'] === MembershipStatus::Inactive->value
            && $targetIsOwner
            && $this->isLastActiveOwner($organization, $member)
        ) {
            return response()->json(['message' => 'The last active owner cannot be deactivated.'], 422);
        }

        $member->update($validated);

        return response()->json([
            'data' => new OrganizationMemberResource($member->fresh()->load('user')),
        ]);
    }

    /**
     * Remove a member from the organization.
     *
     * Only an owner can remove another owner, and the last owner cannot
     * be removed.
     */
    public function destroy(Request $request, OrganizationMember $member): JsonResponse
    {
        $organization = $this->organization($request);

        if ($member->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('manageMembers', $organization);

        $actor = $request->user()->membershipIn($organization);
        $actorIsOwner = $actor !== null && $actor->role === OrganizationRole::Owner;

        if ($member->role === OrganizationRole::Owner && ! $actorIsOwner) {
            return response()->json(['message' => 'Only an owner can remove an owner.'], 403);
        }

        if ($member->role === OrganizationRole::Owner && $this->isLastActiveOwner($organization, $member)) {
            return response()->json(['message' => 'The last owner cannot be removed.'], 422);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }

    /**
     * Whether this membership is the only active owner of the organization.
     */
    protected function isLastActiveOwner(Organization $organization, OrganizationMember $member): bool
    {
        return $organization->members()
            ->where('role', OrganizationRole::Owner->value)
            ->where('status', MembershipStatus::Active->value)
            ->where('id', '!=', $member->id)
            ->doesntExist();
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
