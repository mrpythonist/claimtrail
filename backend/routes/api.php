<?php

use App\Http\Controllers\Api\Public\V1\PublicApiController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AnalyticsEventController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BillingWebhookController;
use App\Http\Controllers\Api\V1\CarrierController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\ClaimDeadlineController;
use App\Http\Controllers\Api\V1\ClaimPackageController;
use App\Http\Controllers\Api\V1\ClaimReviewNoteController;
use App\Http\Controllers\Api\V1\CommunicationChannelController;
use App\Http\Controllers\Api\V1\CommunicationDeliveryController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeadlineSettingController;
use App\Http\Controllers\Api\V1\DocumentProcessingController;
use App\Http\Controllers\Api\V1\DriverEvidenceLinkController;
use App\Http\Controllers\Api\V1\DriverEvidenceSubmissionController;
use App\Http\Controllers\Api\V1\EmailIntakeWebhookController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\EventCategoryController;
use App\Http\Controllers\Api\V1\EvidenceFileController;
use App\Http\Controllers\Api\V1\EvidenceRequirementController;
use App\Http\Controllers\Api\V1\EvidenceTemplateController;
use App\Http\Controllers\Api\V1\ExportTemplateController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportMappingController;
use App\Http\Controllers\Api\V1\ImportRunController;
use App\Http\Controllers\Api\V1\ImportScheduleController;
use App\Http\Controllers\Api\V1\InboundEmailAddressController;
use App\Http\Controllers\Api\V1\InboundEmailController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OpsController;
use App\Http\Controllers\Api\V1\OperationalIntelligenceController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMemberController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PublicPlanController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RetentionController;
use App\Http\Controllers\Api\V1\SavedViewController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\ShipmentEventController;
use App\Http\Controllers\Api\V1\ShipmentImportController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\SyncRunController;
use App\Http\Controllers\Api\V1\TmsIntegrationController;
use App\Http\Controllers\Api\V1\TmsSyncRunController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

// Every API request gets a correlation id, echoed back on the
// response and recorded on audit rows written during the request.
Route::prefix('v1')->middleware('correlation')->group(function () {
    // Public health check for load balancers and uptime monitors.
    // Reports only whether the app and its dependencies are up.
    Route::get('/health', [HealthController::class, 'show']);

    // Public marketing endpoints (v1.2.2). The plan catalog feeds
    // the pricing page, the lead endpoint captures demo requests,
    // and the analytics endpoint accepts anonymous page-view and
    // conversion events from the marketing tracker. Tight rate
    // limits apply because these are unauthenticated entry points.
    // Each route carries its own throttle prefix: Laravel's guest
    // throttle key is domain + IP only, so without a prefix all
    // three routes (and the auth routes) would share one bucket
    // and a pricing-page load could block a demo request.
    Route::get('/plans', [PublicPlanController::class, 'index'])->middleware('throttle:30,1,plans');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('throttle:5,1,leads');
    Route::post('/analytics/events', [AnalyticsEventController::class, 'store'])->middleware('throttle:60,1,analytics');

    // Public authentication endpoints. Tight rate limits apply because
    // these are the unauthenticated entry points.
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1,leads');
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1,leads');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1,leads');

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
            Route::put('/event-categories/{category}', [EventCategoryController::class, 'update']);
            Route::delete('/event-categories/{category}', [EventCategoryController::class, 'destroy']);

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

            Route::get('/claims/export', [ClaimController::class, 'export']);
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

            // Customization (v1.1.2): custom fields, evidence
            // templates, deadline settings, and export templates.
            // All write routes require manage-customization (owner,
            // admin, operations manager).
            Route::get('/custom-fields', [CustomFieldController::class, 'index']);
            Route::post('/custom-fields', [CustomFieldController::class, 'store']);
            Route::get('/custom-fields/{field}', [CustomFieldController::class, 'show']);
            Route::put('/custom-fields/{field}', [CustomFieldController::class, 'update']);
            Route::delete('/custom-fields/{field}', [CustomFieldController::class, 'destroy']);

            Route::get('/evidence-templates', [EvidenceTemplateController::class, 'index']);
            Route::post('/evidence-templates', [EvidenceTemplateController::class, 'store']);
            Route::get('/evidence-templates/{template}', [EvidenceTemplateController::class, 'show']);
            Route::put('/evidence-templates/{template}', [EvidenceTemplateController::class, 'update']);
            Route::delete('/evidence-templates/{template}', [EvidenceTemplateController::class, 'destroy']);
            Route::post('/evidence-templates/{template}/apply-to-claim/{claim}', [EvidenceTemplateController::class, 'applyToClaim']);

            Route::get('/deadline-settings/resolve', [DeadlineSettingController::class, 'resolve']);
            Route::get('/deadline-settings', [DeadlineSettingController::class, 'index']);
            Route::post('/deadline-settings', [DeadlineSettingController::class, 'store']);
            Route::get('/deadline-settings/{setting}', [DeadlineSettingController::class, 'show']);
            Route::put('/deadline-settings/{setting}', [DeadlineSettingController::class, 'update']);
            Route::delete('/deadline-settings/{setting}', [DeadlineSettingController::class, 'destroy']);

            Route::get('/export-templates/available-columns', [ExportTemplateController::class, 'availableColumns']);
            Route::get('/export-templates', [ExportTemplateController::class, 'index']);
            Route::post('/export-templates', [ExportTemplateController::class, 'store']);
            Route::get('/export-templates/{template}', [ExportTemplateController::class, 'show']);
            Route::put('/export-templates/{template}', [ExportTemplateController::class, 'update']);
            Route::delete('/export-templates/{template}', [ExportTemplateController::class, 'destroy']);
            Route::post('/export-templates/{template}/set-default', [ExportTemplateController::class, 'setDefault']);

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
            Route::get('/ops/monitoring', [OpsController::class, 'monitoring']);
            Route::post('/ops/monitoring/check', [OpsController::class, 'runMonitoringCheck']);

            // Backups (v1.1.3): deployment-wide backups, attributed
            // to the requesting organization. All require
            // manage-security (owner and admin). Restores are not
            // exposed here: restoring replaces the live database,
            // so it stays an operator action (`backup:restore`).
            Route::get('/backups', [BackupController::class, 'index']);
            Route::post('/backups', [BackupController::class, 'store']);
            Route::get('/backups/{backup}', [BackupController::class, 'show']);
            Route::post('/backups/{backup}/verify', [BackupController::class, 'verify']);

            // Retention (v1.1.3): effective retention settings and
            // per-organization overrides, in days. 0 keeps forever.
            Route::get('/retention', [RetentionController::class, 'show']);
            Route::put('/retention', [RetentionController::class, 'update']);

            // Security (v1.1.3): production-readiness checks and
            // the security event log (failed logins, authorization
            // denials). Both require manage-security.
            Route::get('/security/check', [SecurityController::class, 'check']);
            Route::get('/security/events', [SecurityController::class, 'events']);

            // Billing (v1.2.0): plans, checkout, trials, plan
            // changes, cancellations, invoices, payment methods,
            // and the mocked billing portal. All require
            // manage-billing (owner, admin, billing staff), except
            // the plan catalog, which any member may read.
            Route::get('/billing/plans', [BillingController::class, 'plans']);
            Route::get('/billing/subscription', [BillingController::class, 'subscription']);
            Route::post('/billing/checkout', [BillingController::class, 'startCheckout']);
            Route::post('/billing/checkout/{session}/complete', [BillingController::class, 'completeCheckout']);
            Route::post('/billing/trials', [BillingController::class, 'startTrial']);
            Route::post('/billing/subscription/change-plan', [BillingController::class, 'changePlan']);
            Route::post('/billing/subscription/cancel', [BillingController::class, 'cancel']);
            Route::post('/billing/subscription/resume', [BillingController::class, 'resume']);
            Route::get('/billing/invoices', [BillingController::class, 'invoices']);
            Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);
            Route::post('/billing/invoices/{invoice}/pay', [BillingController::class, 'payInvoice']);
            Route::post('/billing/invoices/{invoice}/refund', [BillingController::class, 'refundInvoice']);
            Route::get('/billing/events', [BillingController::class, 'billingEvents']);
            Route::post('/billing/payment-method', [BillingController::class, 'updatePaymentMethod']);
            Route::post('/billing/portal', [BillingController::class, 'createPortalSession']);
            Route::get('/billing/usage', [BillingController::class, 'usage']);
            Route::post('/billing/webhooks/simulate', [BillingWebhookController::class, 'simulate']);

            // Support tickets (v1.2.3): any member may open a ticket
            // and reply on it; triage, status changes, and internal
            // notes need manage-support (owner and admin).
            Route::get('/support/tickets', [SupportTicketController::class, 'index']);
            Route::post('/support/tickets', [SupportTicketController::class, 'store']);
            Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show']);
            Route::post('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
            Route::post('/support/tickets/{ticket}/status', [SupportTicketController::class, 'setStatus']);

            // Email intake (v1.3.0): organization-specific inbound
            // addresses and the manual matching interface. Any
            // member may list and read addresses and emails;
            // creating, regenerating, and deactivating addresses,
            // and matching, unmatching, and dismissing emails need
            // manage-email-intake (owner, admin, operations
            // manager, dispatcher).
            Route::get('/email-intake/addresses', [InboundEmailAddressController::class, 'index']);
            Route::post('/email-intake/addresses', [InboundEmailAddressController::class, 'store']);
            Route::post('/email-intake/addresses/{address}/regenerate', [InboundEmailAddressController::class, 'regenerate']);
            Route::post('/email-intake/addresses/{address}/deactivate', [InboundEmailAddressController::class, 'deactivate']);
            Route::get('/email-intake/emails', [InboundEmailController::class, 'index']);
            Route::get('/email-intake/emails/{email}', [InboundEmailController::class, 'show']);
            Route::post('/email-intake/emails/{email}/match', [InboundEmailController::class, 'match']);
            Route::post('/email-intake/emails/{email}/unmatch', [InboundEmailController::class, 'unmatch']);
            Route::post('/email-intake/emails/{email}/dismiss', [InboundEmailController::class, 'dismiss']);

            // Advanced imports (v1.3.1): saved CSV mappings,
            // import history with row-level error correction,
            // scheduled imports, and synchronization workflows.
            // Any member may list and read; creating, updating,
            // deleting, running, and correcting need
            // manage-imports (owner, admin, operations manager,
            // dispatcher). The two-step shipment CSV upload
            // (preview/confirm) still needs import-shipments.
            Route::get('/imports/mappings', [ImportMappingController::class, 'index']);
            Route::post('/imports/mappings', [ImportMappingController::class, 'store']);
            Route::get('/imports/mappings/{mapping}', [ImportMappingController::class, 'show']);
            Route::put('/imports/mappings/{mapping}', [ImportMappingController::class, 'update']);
            Route::delete('/imports/mappings/{mapping}', [ImportMappingController::class, 'destroy']);
            Route::get('/imports/runs', [ImportRunController::class, 'index']);
            Route::get('/imports/runs/{run}', [ImportRunController::class, 'show']);
            Route::get('/imports/runs/{run}/rows', [ImportRunController::class, 'rows']);
            Route::post('/imports/runs/{run}/rows/{row}/correct', [ImportRunController::class, 'correctRow']);
            Route::get('/imports/schedules', [ImportScheduleController::class, 'index']);
            Route::post('/imports/schedules', [ImportScheduleController::class, 'store']);
            Route::get('/imports/schedules/{schedule}', [ImportScheduleController::class, 'show']);
            Route::put('/imports/schedules/{schedule}', [ImportScheduleController::class, 'update']);
            Route::delete('/imports/schedules/{schedule}', [ImportScheduleController::class, 'destroy']);
            Route::post('/imports/schedules/{schedule}/run', [ImportScheduleController::class, 'run']);
            Route::get('/imports/sync-runs', [SyncRunController::class, 'index']);
            Route::post('/imports/sync-runs', [SyncRunController::class, 'store']);
            Route::get('/imports/sync-runs/{syncRun}', [SyncRunController::class, 'show']);
            Route::post('/imports/sync-runs/{syncRun}/run', [SyncRunController::class, 'run']);
            Route::delete('/imports/sync-runs/{syncRun}', [SyncRunController::class, 'destroy']);

            // TMS integrations (v1.3.2): a generic provider
            // adapter with a mock provider and Trimble
            // TMW.Suite modelled schema-only. Any member may
            // list integrations, providers, and sync runs;
            // creating, updating, deleting, testing, and
            // syncing need manage-tms-integrations (owner,
            // admin, operations manager, dispatcher).
            // Credentials are never returned by the API.
            Route::get('/tms/providers', [TmsIntegrationController::class, 'providers']);
            Route::get('/tms/integrations', [TmsIntegrationController::class, 'index']);
            Route::post('/tms/integrations', [TmsIntegrationController::class, 'store']);
            Route::get('/tms/integrations/{integration}', [TmsIntegrationController::class, 'show']);
            Route::put('/tms/integrations/{integration}', [TmsIntegrationController::class, 'update']);
            Route::delete('/tms/integrations/{integration}', [TmsIntegrationController::class, 'destroy']);
            Route::post('/tms/integrations/{integration}/test', [TmsIntegrationController::class, 'test']);
            Route::post('/tms/integrations/{integration}/sync', [TmsIntegrationController::class, 'sync']);
            Route::get('/tms/sync-runs', [TmsSyncRunController::class, 'index']);
            Route::get('/tms/sync-runs/{syncRun}', [TmsSyncRunController::class, 'show']);

            // Communication integrations (v1.3.3): Slack,
            // Teams, and mock channels behind a provider
            // adapter, delivery history, and driver evidence
            // submission links. Any member may list channels,
            // links, and deliveries; creating, updating,
            // deleting, testing, resending, and revoking need
            // manage-communications (owner, admin, operations
            // manager, dispatcher). Webhook URLs are stored
            // encrypted and never returned by the API. No live
            // delivery exists: every send is logged, never sent.
            Route::get('/comms/providers', [CommunicationChannelController::class, 'providers']);
            Route::get('/comms/channels', [CommunicationChannelController::class, 'index']);
            Route::post('/comms/channels', [CommunicationChannelController::class, 'store']);
            Route::get('/comms/channels/{channel}', [CommunicationChannelController::class, 'show']);
            Route::put('/comms/channels/{channel}', [CommunicationChannelController::class, 'update']);
            Route::delete('/comms/channels/{channel}', [CommunicationChannelController::class, 'destroy']);
            Route::post('/comms/channels/{channel}/test', [CommunicationChannelController::class, 'test']);
            Route::get('/comms/deliveries', [CommunicationDeliveryController::class, 'index']);
            Route::get('/comms/deliveries/{delivery}', [CommunicationDeliveryController::class, 'show']);
            Route::get('/comms/driver-links', [DriverEvidenceLinkController::class, 'index']);
            Route::post('/comms/driver-links', [DriverEvidenceLinkController::class, 'store']);
            Route::get('/comms/driver-links/{link}', [DriverEvidenceLinkController::class, 'show']);
            Route::delete('/comms/driver-links/{link}', [DriverEvidenceLinkController::class, 'destroy']);
            Route::post('/comms/driver-links/{link}/resend', [DriverEvidenceLinkController::class, 'resend']);
            Route::get('/comms/driver-links/{link}/submissions', [DriverEvidenceLinkController::class, 'submissions']);

            // Financial reconciliation (v1.5.0): the claim
            // payment ledger, claim balances, org summary, and
            // reconciliation reports. Any member may read the
            // ledger, balances, summary, and reports; recording
            // or voiding a payment and generating a report need
            // manage-reconciliation (owner, admin, operations
            // manager, dispatcher). Partial payments are normal:
            // the claim stays accepted until the paid total
            // reaches the amount due. Nothing here moves real
            // money: the ledger only records what the org says
            // it received.
            Route::get('/reconciliation/summary', [ReconciliationController::class, 'summary']);
            Route::get('/reconciliation/ledger', [ReconciliationController::class, 'ledger']);
            Route::get('/reconciliation/ledger/{payment}', [ReconciliationController::class, 'showPayment']);
            Route::post('/reconciliation/claims/{claim}/payments', [ReconciliationController::class, 'recordPayment']);
            Route::delete('/reconciliation/ledger/{payment}', [ReconciliationController::class, 'voidPayment']);
            Route::get('/reconciliation/claims/{claim}', [ReconciliationController::class, 'claimBalance']);
            Route::get('/reconciliation/reports', [ReconciliationController::class, 'reports']);
            Route::post('/reconciliation/reports', [ReconciliationController::class, 'generateReport']);
            Route::get('/reconciliation/reports/{report}', [ReconciliationController::class, 'showReport']);

            // Advanced analytics (v1.5.1): recovery and
            // rejection rates, average submission and payment
            // times, broker, carrier, and event-type
            // performance, and monthly/yearly comparisons,
            // with CSV exports. Reading needs view-analytics
            // (owner, admin, operations manager, dispatcher,
            // billing staff, viewer); exporting needs
            // export-reports. Analytics is a full report, so
            // the Free plan is refused with a 402, the same
            // as the reports module. Every metric is computed
            // from the org's own recorded claims, shipments,
            // and payments; money is summed as recorded, with
            // no conversion, and every average carries its
            // sample size.
            Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('/analytics/brokers', [AnalyticsController::class, 'brokers']);
            Route::get('/analytics/carriers', [AnalyticsController::class, 'carriers']);
            Route::get('/analytics/event-types', [AnalyticsController::class, 'eventTypes']);
            Route::get('/analytics/monthly', [AnalyticsController::class, 'monthly']);
            Route::get('/analytics/yearly', [AnalyticsController::class, 'yearly']);
            Route::get('/analytics/export', [AnalyticsController::class, 'export']);

            // Operational intelligence (v1.5.2): detention
            // trends, facility performance, rejection reasons,
            // missing-evidence trends, broker-level trends,
            // revenue leakage, and deterministic operational
            // recommendations. Same readers and the same plan
            // gate as analytics: view-analytics, Free plan
            // refused with a 402. Every number is computed
            // from the org's own recorded claims, shipments,
            // payments, and evidence; money is summed as
            // recorded, with no conversion, and every average
            // carries its sample size.
            Route::get('/operations/detention-trends', [OperationalIntelligenceController::class, 'detentionTrends']);
            Route::get('/operations/facilities', [OperationalIntelligenceController::class, 'facilities']);
            Route::get('/operations/rejection-reasons', [OperationalIntelligenceController::class, 'rejectionReasons']);
            Route::get('/operations/missing-evidence', [OperationalIntelligenceController::class, 'missingEvidence']);
            Route::get('/operations/broker-trends', [OperationalIntelligenceController::class, 'brokerTrends']);
            Route::get('/operations/revenue-leakage', [OperationalIntelligenceController::class, 'revenueLeakage']);
            Route::get('/operations/recommendations', [OperationalIntelligenceController::class, 'recommendations']);

            // Workflow engine (v2.0.0): configuration, dry-run
            // evaluation, and execution history with approval
            // checkpoints and manual retries. Any member may
            // list workflows and read runs. Creating, changing,
            // deleting, testing, and retrying need
            // manage-workflows (owner, admin, operations
            // manager, dispatcher) and the Starter plan or
            // better: the Free plan is refused with a 402.
            // Approving or rejecting a checkpoint needs the
            // role the checkpoint names (or the owner); the
            // engine enforces that. The runs routes come first
            // so /workflows/runs is not read as a workflow id.
            Route::get('/workflows/runs', [WorkflowController::class, 'runs']);
            Route::get('/workflows/runs/{run}', [WorkflowController::class, 'showRun']);
            Route::post('/workflows/runs/{run}/retry', [WorkflowController::class, 'retryRun']);
            Route::post('/workflows/runs/{run}/approve', [WorkflowController::class, 'approveRun']);
            Route::post('/workflows/runs/{run}/reject', [WorkflowController::class, 'rejectRun']);
            Route::get('/workflows', [WorkflowController::class, 'index']);
            Route::post('/workflows', [WorkflowController::class, 'store']);
            Route::get('/workflows/{workflow}', [WorkflowController::class, 'show']);
            Route::put('/workflows/{workflow}', [WorkflowController::class, 'update']);
            Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy']);
            Route::post('/workflows/{workflow}/test', [WorkflowController::class, 'test']);

            // AI document processing (v2.0.1): OCR extraction,
            // classification, field extraction, shipment
            // matching, duplicate detection, human verification,
            // and accuracy monitoring. Any member may list
            // extractions and read the queue and accuracy.
            // Processing and verification need
            // manage-document-processing (owner, admin,
            // operations manager, dispatcher) and the Starter
            // plan or better: the Free plan is refused with a
            // 402. The queue and accuracy routes come first so
            // /documents/queue is not read as an extraction id.
            Route::get('/documents/queue', [DocumentProcessingController::class, 'queue']);
            Route::get('/documents/accuracy', [DocumentProcessingController::class, 'accuracy']);
            Route::post('/documents/process', [DocumentProcessingController::class, 'process']);
            Route::get('/documents', [DocumentProcessingController::class, 'index']);
            Route::get('/documents/{extraction}', [DocumentProcessingController::class, 'show']);
            Route::post('/documents/{extraction}/fields/{field}/verify', [DocumentProcessingController::class, 'verifyField']);
            Route::post('/documents/{extraction}/verify', [DocumentProcessingController::class, 'verify']);
            Route::post('/documents/{extraction}/reject', [DocumentProcessingController::class, 'reject']);
            Route::post('/documents/{extraction}/link-shipment', [DocumentProcessingController::class, 'linkShipment']);
            Route::post('/documents/{extraction}/dismiss-match', [DocumentProcessingController::class, 'dismissMatch']);

            // Public API keys (v2.0.2): Bearer keys for the
            // public API. Managing keys needs manage-api-keys
            // (owner, admin, operations manager, dispatcher)
            // and the Starter plan or better: the Free plan is
            // refused with a 402. The full key is returned
            // exactly once, at creation or rotation.
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::get('/api-keys/{apiKey}', [ApiKeyController::class, 'show']);
            Route::post('/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate']);
            Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

            // Webhook endpoints (v2.0.2): organization webhook
            // subscriptions and delivery history. Managing
            // endpoints needs manage-integrations (owner,
            // admin, operations manager, dispatcher) and the
            // Starter plan or better. The signing secret is
            // returned once, at creation; the target URL is
            // stored encrypted and never returned.
            Route::get('/webhooks', [WebhookController::class, 'index']);
            Route::post('/webhooks', [WebhookController::class, 'store']);
            Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
            Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test']);
            Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);

            // Accounting integrations (v2.0.2): mock provider
            // connections (QuickBooks / Xero modelled
            // schema-only). Managing connections needs
            // manage-integrations and the Starter plan or
            // better. A sync exports recorded payments to the
            // provider's dummy shape; no money moves.
            Route::get('/accounting/providers', [AccountingController::class, 'providers']);
            Route::get('/accounting', [AccountingController::class, 'index']);
            Route::post('/accounting', [AccountingController::class, 'store']);
            Route::get('/accounting/{accounting}', [AccountingController::class, 'show']);
            Route::post('/accounting/{accounting}/test', [AccountingController::class, 'test']);
            Route::post('/accounting/{accounting}/sync', [AccountingController::class, 'sync']);
            Route::delete('/accounting/{accounting}', [AccountingController::class, 'destroy']);
        });
    });

    // Billing provider webhooks (v1.2.0): public, authenticated by
    // the HMAC signature over the raw body. The portal session
    // endpoint is public too: the token in the URL is the
    // credential, and it expires after 30 minutes.
    Route::post('/billing/webhooks', [BillingWebhookController::class, 'handle']);
    Route::get('/billing/portal/{token}', [BillingWebhookController::class, 'portal']);

    // Inbound email webhook (v1.3.0): public, authenticated by
    // the HMAC signature over the raw body. The provider sends a
    // normalized JSON payload; no real provider is wired yet.
    // Tight rate limit with its own prefix, so inbound mail
    // cannot share a bucket with the marketing endpoints.
    Route::post('/email-intake/inbound', [EmailIntakeWebhookController::class, 'inbound'])
        ->middleware('throttle:120,1,email-intake');

    // Driver evidence submission (v1.3.3): public, the token
    // in the URL is the credential, exactly like the billing
    // portal token. The link is scoped to one shipment or
    // claim, expires, and is single-use by default. Tight
    // rate limit with its own prefix, so a driver upload
    // cannot share a bucket with the marketing endpoints.
    Route::get('/driver-evidence/{token}', [DriverEvidenceSubmissionController::class, 'show'])
        ->middleware('throttle:30,1,driver-evidence');
    Route::post('/driver-evidence/{token}/submit', [DriverEvidenceSubmissionController::class, 'submit'])
        ->middleware('throttle:10,1,driver-evidence-submit');
});

// Public REST API (v2.0.2): a separate surface from the
// internal /api/v1 API the frontend uses. Authenticated by
// Bearer API key (the api-key middleware), never by user
// session. Keys are scoped per endpoint, rate limited per
// key, and refused with a 402 on the Free plan. The
// /broker routes come before /claims/{claim} so a broker
// status lookup is never read as a claim id.
Route::prefix('public/v1')->middleware(['correlation', 'api-key'])->group(function () {
    Route::get('/', [PublicApiController::class, 'index']);
    Route::get('/claims', [PublicApiController::class, 'listClaims']);
    Route::post('/claims', [PublicApiController::class, 'storeClaim']);
    Route::get('/broker/claims/{claim}/status', [PublicApiController::class, 'brokerClaimStatus']);
    Route::get('/claims/{claim}', [PublicApiController::class, 'showClaim']);
    Route::get('/shipments', [PublicApiController::class, 'listShipments']);
    Route::get('/shipments/{shipment}', [PublicApiController::class, 'showShipment']);
    Route::get('/payments', [PublicApiController::class, 'listPayments']);
    Route::get('/documents', [PublicApiController::class, 'listDocuments']);
});
