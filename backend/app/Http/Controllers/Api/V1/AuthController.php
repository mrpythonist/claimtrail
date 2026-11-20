<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        protected SecurityEventService $securityEvents,
    ) {}

    /**
     * Register a new user and issue an API token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Log a user in and issue an API token.
     *
     * The failure message is deliberately generic so it does not reveal
     * whether the email address exists.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Credential checks always use the session guard, even if the
        // request itself arrived authenticated some other way.
        if (! Auth::guard('web')->attempt($validated)) {
            // Failed logins are recorded as security events (v1.1.3)
            // so repeated attempts against one account are visible.
            // The password is never recorded.
            $this->securityEvents->recordLoginFailed($validated['email'], $request);

            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Log the current user out by revoking the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Return the authenticated user with their organizations.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('organizations');

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Update the authenticated user's display name.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'user' => new UserResource($user->fresh()->load('organizations')),
        ]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
