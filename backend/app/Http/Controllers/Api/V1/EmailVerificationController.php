<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Verify an email address from the signed link in the verification email.
     *
     * The link points at this API route, which marks the address verified
     * and redirects to the frontend verification page. The route is
     * protected by Laravel's signed URL middleware.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return redirect($frontend.'/verify-email?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($frontend.'/verify-email?status=verified');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect($frontend.'/verify-email?status=verified');
    }

    /**
     * Resend the verification email to the authenticated user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new verification link has been sent to your email address.']);
    }
}
