<?php

namespace App;

/**
 * The full permission vocabulary for ClaimTrail.
 *
 * Milestone 1 enforces the organization and member permissions. The
 * shipment, event, evidence, claim, deadline, report, and export
 * permissions are defined here so later modules map onto the same
 * vocabulary, and are enforced as each module lands. Defining a
 * permission here does not mean the feature exists yet.
 */
enum Permission: string
{
    // Organization and membership (enforced in Milestone 1)
    case ManageOrganization = 'manage-organization';
    case ManageMembers = 'manage-members';
    case ManageInvitations = 'manage-invitations';
    case ViewMembers = 'view-members';

    // Shipments (enforced from Milestone 2)
    case CreateShipments = 'create-shipments';
    case EditShipments = 'edit-shipments';
    case ViewShipments = 'view-shipments';
    case ArchiveShipments = 'archive-shipments';
    case ImportShipments = 'import-shipments';
    case ExportShipments = 'export-shipments';

    // Shipment events (enforced from Milestone 2)
    case CreateEvents = 'create-events';
    case EditEvents = 'edit-events';
    case DeleteEvents = 'delete-events';
    case ViewEvents = 'view-events';

    // Evidence (enforced from Milestone 2)
    case UploadEvidence = 'upload-evidence';
    case DeleteEvidence = 'delete-evidence';
    case VerifyEvidence = 'verify-evidence';
    case ViewEvidence = 'view-evidence';

    // Claims (enforced from Milestone 3)
    case CreateClaims = 'create-claims';
    case EditClaims = 'edit-claims';
    case DeleteClaims = 'delete-claims';
    case ReviewClaims = 'review-claims';
    case SubmitClaims = 'submit-claims';
    case RecordClaimPayments = 'record-claim-payments';
    case AttachClaimEvidence = 'attach-claim-evidence';
    case ViewClaims = 'view-claims';
    case GenerateClaimPackages = 'generate-claim-packages';

    // Evidence requirements (enforced from Milestone 3)
    case ManageEvidenceRequirements = 'manage-evidence-requirements';

    // Deadlines (enforced from Milestone 3)
    case ManageDeadlines = 'manage-deadlines';
    case ViewDeadlines = 'view-deadlines';

    // Reporting and exports (enforced from Milestone 4)
    case ViewReports = 'view-reports';
    case ExportReports = 'export-reports';

    // Audit trail (enforced from Milestone 4)
    case ViewAuditLogs = 'view-audit-logs';

    // Operations (enforced from v1.1.1): queue depth, failed jobs,
    // and recent errors for the organization.
    case ViewOperations = 'view-operations';

    // Customization (enforced from v1.1.2): custom fields, event
    // category updates, evidence templates, deadline settings,
    // and export templates.
    case ManageCustomization = 'manage-customization';

    // Security (enforced from v1.1.3): backups, retention
    // settings, production-readiness checks, and the security
    // event log. Owner and admin only.
    case ManageSecurity = 'manage-security';

    // Billing (enforced from v1.2.0): plans, checkout, trials,
    // plan changes, cancellations, invoices, payment methods,
    // and the billing portal. Owner, admin, and billing staff.
    case ManageBilling = 'manage-billing';

    // Support (enforced from v1.2.3): triage and replies on the
    // organization's support tickets, including internal notes.
    // Owner and admin only. Any member may open a ticket and
    // reply on their own tickets.
    case ManageSupport = 'manage-support';

    // Email intake (enforced from v1.3.0): organization inbound
    // addresses, and manual matching, unmatching, and dismissal
    // of inbound emails. Owner, admin, operations manager, and
    // dispatcher. Any member may list and read inbound emails.
    case ManageEmailIntake = 'manage-email-intake';

    // Advanced imports (enforced from v1.3.1): saved CSV
    // mappings, schedules, sync runs, and error correction.
    // Owner, admin, operations manager, and dispatcher. Any
    // member may list and read import history. Uploading and
    // confirming a shipment CSV still needs import-shipments.
    case ManageImports = 'manage-imports';

    // TMS integrations (enforced from v1.3.2): TMS
    // provider connections, connection tests, sync runs,
    // and integration settings. Owner, admin, operations
    // manager, and dispatcher. Any member may list
    // integrations and read sync runs. Credentials are
    // never returned by the API.
    case ManageTmsIntegrations = 'manage-tms-integrations';

    // Communication integrations (enforced from v1.3.3):
    // Slack and Teams channel connections, channel tests
    // and delivery history, driver evidence submission
    // links, and email workflows. Owner, admin, operations
    // manager, and dispatcher. Any member may list
    // channels, links, and deliveries. Webhook URLs are
    // stored encrypted and never returned by the API.
    case ManageCommunications = 'manage-communications';

    // Financial reconciliation (enforced from v1.5.0):
    // the claim payment ledger, claim balances, and
    // reconciliation reports. Owner, admin, operations
    // manager, and dispatcher. Any member may read the
    // ledger, balances, summary, and reports. Recording
    // or voiding a payment and generating a report need
    // this permission. Nothing here moves real money.
    case ManageReconciliation = 'manage-reconciliation';

    // Advanced analytics (enforced from v1.5.1): recovery
    // and rejection rates, average submission and payment
    // times, broker, carrier, and event-type performance,
    // and monthly/yearly comparisons, with CSV exports.
    // Owner, admin, operations manager, dispatcher, billing
    // staff, and viewer may read analytics. Exporting needs
    // export-reports. Analytics is a full report, so the
    // Free plan is refused with a 402, the same as reports.
    case ViewAnalytics = 'view-analytics';

    /**
     * Every permission value, for validation and documentation.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
