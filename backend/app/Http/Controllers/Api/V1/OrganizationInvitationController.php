<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationInvitationResource;
use App\Mail\OrganizationInvitationMail;
use App\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\NotificationType;
use App\OrganizationRole;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    /**
     * List invitations for the resolved organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('manageInvitations', $organization);

        $invitations = $organization->invitations()
            ->with('inviter')
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => OrganizationInvitationResource::collection($invitations),
            'meta' => [
                'current_page' => $invitations->currentPage(),
                'last_page' => $invitations->lastPage(),
                'per_page' => $invitations->perPage(),
                'total' => $invitations->total(),
            ],
        ]);
    }

    /**
     * Invite an email address to join the organization.
     *
     * Only an owner can invite another owner. The raw token is sent in
     * the email; only its hash is stored.
     */
    public function store(Request $request, NotificationService $notifications): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('manageInvitations', $organization);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(OrganizationRole::values())],
        ]);

        $actor = $request->user()->membershipIn($organization);

        if ($validated['role'] === OrganizationRole::Owner->value
            && ($actor === null || $actor->role !== OrganizationRole::Owner)
        ) {
            return response()->json(['message' => 'Only an owner can invite another owner.'], 403);
        }

        $email = Str::lower($validated['email']);

        if ($organization->members()->whereHas('user', fn ($query) => $query->where('email', $email))->exists()) {
            return response()->json(['message' => 'That person is already a member of this organization.'], 422);
        }

        if ($organization->pendingInvitations()->where('email', $email)->exists()) {
            return response()->json(['message' => 'A pending invitation already exists for that email address. Revoke it first to send a new one.'], 422);
        }

        // Clear old accepted invitations so the (organization, email)
        // unique constraint allows a fresh invite.
        $organization->invitations()->where('email', $email)->whereNotNull('accepted_at')->delete();

        $rawToken = Str::random(48);

        $invitation = $organization->invitations()->create([
            'email' => $email,
            'role' => $validated['role'],
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(7),
            'invited_by' => $request->user()->id,
        ]);

        Mail::to($email)->send(new OrganizationInvitationMail($invitation, $rawToken));

        // When the invited address already has an account, the
        // invitee also gets an in-app notification. No account yet
        // means no in-app row: the invitation email is the only
        // contact until they register. No second email is sent here;
        // the invitation email above is the email for this event.
        $invitee = User::where('email', $email)->first();

        if ($invitee !== null) {
            $notifications->notify(
                $organization,
                [$invitee],
                NotificationType::OrganizationInvitation,
                'You were invited to join '.$organization->name,
                $request->user()->name.' invited you to join '.$organization->name.' on ClaimTrail as '.$invitation->role->label().'.',
                [
                    'organization_id' => (string) $organization->getKey(),
                    'invitation_id' => (string) $invitation->getKey(),
                ],
                'invitation:'.$invitation->getKey(),
                $request->user(),
                requireMembership: false,
                sendEmail: false,
            );
        }

        return response()->json([
            'data' => new OrganizationInvitationResource($invitation),
        ], 201);
    }

    /**
     * Accept an invitation. The user must be signed in, and the
     * invitation must have been sent to their email address.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $invitation = OrganizationInvitation::withoutGlobalScope('organization')
            ->where('token', hash('sha256', $validated['token']))
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json(['message' => 'This invitation is invalid or has expired.'], 404);
        }

        $user = $request->user();

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            return response()->json(['message' => 'This invitation was sent to a different email address.'], 403);
        }

        if ($user->isActiveMemberOf($invitation->organization)) {
            return response()->json(['message' => 'You are already a member of this organization.'], 422);
        }

        $invitation->organization->members()->create([
            'user_id' => $user->id,
            'role' => $invitation->role,
            'status' => MembershipStatus::Active,
            'invited_by' => $invitation->invited_by,
            'joined_at' => now(),
        ]);

        $invitation->update(['accepted_at' => now()]);

        return response()->json([
            'message' => 'Invitation accepted.',
            'organization_id' => $invitation->organization_id,
        ]);
    }

    /**
     * Revoke a pending invitation.
     */
    public function destroy(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('manageInvitations', $organization);

        if ($invitation->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $invitation->delete();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
