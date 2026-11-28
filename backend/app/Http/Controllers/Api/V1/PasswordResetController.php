<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityPolicyService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function __construct(
        protected SecurityPolicyService $policies,
    ) {}
    /**
     * Send a password reset link.
     *
     * Always returns the same response, whether or not the email address
     * belongs to an account, so the endpoint cannot be used to probe
     * for registered addresses.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset the password using the token from the email link.
     *
     * The new password must also satisfy the strictest security
     * policy across the user's organizations (v2.0.3). The
     * policy is checked before the token is consumed, so a
     * policy failure leaves the reset link usable.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user !== null) {
            $policyErrors = $this->policies->validatePasswordWithPolicy(
                $request->string('password'),
                $this->policies->strictestPolicyForUser($user),
            );

            if ($policyErrors !== []) {
                return response()->json([
                    'message' => 'The new password does not meet your organization\'s security policy.',
                    'errors' => ['password' => $policyErrors],
                ], 422);
            }
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }
}
