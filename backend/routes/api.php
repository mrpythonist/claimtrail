<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMemberController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public authentication endpoints. Tight rate limits apply because
    // these are the unauthenticated entry points.
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    // Email verification link from the verification email. The signed
    // middleware guarantees the link has not been tampered with, and
    // the controller redirects to the frontend afterwards.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'updatePassword']);
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1');

        // Accepting an invitation is not tenant-scoped: the user is not
        // a member yet, so there is no organization to resolve.
        Route::post('/invitations/accept', [OrganizationInvitationController::class, 'accept']);

        // Organizations the user belongs to. These routes address the
        // organization in the URL and authorize through the policy.
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch']);

        // Tenant-scoped routes. The organization middleware resolves the
        // organization from the X-Organization-Id header and verifies
        // active membership before any of these run.
        Route::middleware('organization')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'show']);

            Route::get('/members', [OrganizationMemberController::class, 'index']);
            Route::put('/members/{member}', [OrganizationMemberController::class, 'update']);
            Route::delete('/members/{member}', [OrganizationMemberController::class, 'destroy']);

            Route::get('/invitations', [OrganizationInvitationController::class, 'index']);
            Route::post('/invitations', [OrganizationInvitationController::class, 'store']);
            Route::delete('/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy']);
        });
    });
});
