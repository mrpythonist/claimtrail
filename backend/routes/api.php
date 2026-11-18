<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CarrierController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\ClaimDeadlineController;
use App\Http\Controllers\Api\V1\ClaimPackageController;
use App\Http\Controllers\Api\V1\ClaimReviewNoteController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\EventCategoryController;
use App\Http\Controllers\Api\V1\EvidenceFileController;
use App\Http\Controllers\Api\V1\EvidenceRequirementController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OpsController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMemberController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SavedViewController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\ShipmentEventController;
use App\Http\Controllers\Api\V1\ShipmentImportController;
use Illuminate\Support\Facades\Route;

// Every API request gets a correlation id, echoed back on the
// response and recorded on audit rows written during the request.
Route::prefix('v1')->middleware('correlation')->group(function () {
    // Public health check for load balancers and uptime monitors.
    // Reports only whether the app and its dependencies are up.
    Route::get('/health', [HealthController::class, 'show']);

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

            Route::get('/onboarding', [OnboardingController::class, 'show']);
            Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss']);

            Route::get('/saved-views', [SavedViewController::class, 'index']);
            Route::post('/saved-views', [SavedViewController::class, 'store']);
            Route::put('/saved-views/{savedView}', [SavedViewController::class, 'update']);
            Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy']);

            Route::get('/members', [OrganizationMemberController::class, 'index']);
            Route::put('/members/{member}', [OrganizationMemberController::class, 'update']);
            Route::delete('/members/{member}', [OrganizationMemberController::class, 'destroy']);

            Route::get('/invitations', [OrganizationInvitationController::class, 'index']);
            Route::post('/invitations', [OrganizationInvitationController::class, 'store']);
            Route::delete('/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy']);

            Route::get('/carriers', [CarrierController::class, 'index']);
            Route::post('/carriers', [CarrierController::class, 'store']);
            Route::put('/carriers/{carrier}', [CarrierController::class, 'update']);

            // Fixed shipment paths must come before /shipments/{shipment},
            // or "export" and "import" would bind as a shipment id.
            Route::get('/shipments/export', [ShipmentController::class, 'export']);
            Route::post('/shipments/import/preview', [ShipmentImportController::class, 'preview']);
            Route::post('/shipments/import/confirm', [ShipmentImportController::class, 'confirm']);
            Route::post('/shipments/bulk-status', [ShipmentController::class, 'bulkStatus']);
            Route::post('/shipments/bulk-archive', [ShipmentController::class, 'bulkArchive']);
            Route::get('/shipments', [ShipmentController::class, 'index']);
            Route::post('/shipments', [ShipmentController::class, 'store']);
            Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);
            Route::put('/shipments/{shipment}', [ShipmentController::class, 'update']);
            Route::post('/shipments/{shipment}/archive', [ShipmentController::class, 'archive']);

            Route::get('/shipments/{shipment}/events', [ShipmentEventController::class, 'index']);
            Route::post('/shipments/{shipment}/events', [ShipmentEventController::class, 'store']);

            Route::get('/event-categories', [EventCategoryController::class, 'index']);
            Route::post('/event-categories', [EventCategoryController::class, 'store']);

            Route::get('/events/{event}', [ShipmentEventController::class, 'show']);
            Route::put('/events/{event}', [ShipmentEventController::class, 'update']);
            Route::delete('/events/{event}', [ShipmentEventController::class, 'destroy']);

            Route::get('/evidence', [EvidenceFileController::class, 'index']);
            Route::post('/evidence', [EvidenceFileController::class, 'store']);
            Route::get('/evidence/{evidence}', [EvidenceFileController::class, 'show']);
            Route::get('/evidence/{evidence}/download', [EvidenceFileController::class, 'download']);
            Route::get('/evidence/{evidence}/preview', [EvidenceFileController::class, 'preview']);
            Route::put('/evidence/{evidence}/verification', [EvidenceFileController::class, 'verify']);
            Route::delete('/evidence/{evidence}', [EvidenceFileController::class, 'destroy']);

            Route::get('/claims', [ClaimController::class, 'index']);
            Route::post('/claims', [ClaimController::class, 'store']);
            Route::post('/claims/bulk-transition', [ClaimController::class, 'bulkTransition']);
            Route::post('/claims/bulk-delete', [ClaimController::class, 'bulkDelete']);
            Route::get('/claims/{claim}', [ClaimController::class, 'show']);
            Route::put('/claims/{claim}', [ClaimController::class, 'update']);
            Route::post('/claims/{claim}/transition', [ClaimController::class, 'transition']);
            Route::post('/claims/{claim}/review', [ClaimController::class, 'review']);
            Route::get('/claims/{claim}/review-notes', [ClaimReviewNoteController::class, 'index']);
            Route::post('/claims/{claim}/review-notes', [ClaimReviewNoteController::class, 'store']);
            Route::post('/claims/{claim}/record-payment', [ClaimController::class, 'recordPayment']);
            Route::get('/claims/{claim}/history', [ClaimController::class, 'history']);
            Route::get('/claims/{claim}/evidence', [ClaimController::class, 'evidence']);
            Route::post('/claims/{claim}/evidence', [ClaimController::class, 'attachEvidence']);
            Route::delete('/claims/{claim}/evidence/{evidence}', [ClaimController::class, 'detachEvidence']);
            Route::get('/claims/{claim}/readiness', [ClaimController::class, 'readiness']);
            Route::post('/claims/{claim}/readiness/override', [ClaimController::class, 'overrideReadiness']);

            Route::get('/evidence-requirements', [EvidenceRequirementController::class, 'index']);
            Route::post('/evidence-requirements', [EvidenceRequirementController::class, 'store']);
            Route::put('/evidence-requirements/{requirement}', [EvidenceRequirementController::class, 'update']);
            Route::delete('/evidence-requirements/{requirement}', [EvidenceRequirementController::class, 'destroy']);

            Route::get('/deadlines', [ClaimDeadlineController::class, 'index']);
            Route::get('/deadlines/{deadline}', [ClaimDeadlineController::class, 'show']);
            Route::put('/deadlines/{deadline}', [ClaimDeadlineController::class, 'update']);
            Route::post('/claims/{claim}/deadline', [ClaimDeadlineController::class, 'store']);

            Route::post('/claims/{claim}/package', [ClaimPackageController::class, 'store']);
            Route::get('/claims/{claim}/package', [ClaimPackageController::class, 'show']);
            Route::get('/documents/{document}/download', [ClaimPackageController::class, 'download']);

            // Audit trail: read-only by design. There are no store,
            // update, or delete routes for audit rows.
            Route::get('/audit-logs', [AuditLogController::class, 'index']);

            // The member's own notifications. The fixed unread-count
            // path must come before any /notifications/{id} route.
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

            Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
            Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);

            Route::get('/reports/summary', [ReportController::class, 'summary']);
            Route::get('/reports/recovery-history', [ReportController::class, 'recoveryHistory']);
            Route::get('/reports/export', [ReportController::class, 'export']);

            // Operations (v1.1.1): detailed health, queue depth,
            // failed jobs, and recent errors. All require the
            // view-operations permission (owner and admin) and are
            // tenant-scoped to the resolved organization.
            Route::get('/ops/health', [HealthController::class, 'ops']);
            Route::get('/ops/queue', [OpsController::class, 'queue']);
            Route::get('/ops/failed-jobs', [OpsController::class, 'failedJobs']);
            Route::post('/ops/failed-jobs/retry-all', [OpsController::class, 'retryAllFailedJobs']);
            Route::post('/ops/failed-jobs/flush', [OpsController::class, 'flushFailedJobs']);
            Route::post('/ops/failed-jobs/{uuid}/retry', [OpsController::class, 'retryFailedJob']);
            Route::post('/ops/failed-jobs/{uuid}/forget', [OpsController::class, 'forgetFailedJob']);
            Route::get('/ops/errors', [OpsController::class, 'errors']);
        });
    });
});
