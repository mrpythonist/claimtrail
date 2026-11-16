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
    case ViewEvents = 'view-events';

    // Evidence (enforced from Milestone 2)
    case UploadEvidence = 'upload-evidence';
    case DeleteEvidence = 'delete-evidence';
    case ViewEvidence = 'view-evidence';

    // Claims (enforced from Milestone 3)
    case CreateClaims = 'create-claims';
    case EditClaims = 'edit-claims';
    case ReviewClaims = 'review-claims';
    case SubmitClaims = 'submit-claims';
    case RecordClaimPayments = 'record-claim-payments';
    case ViewClaims = 'view-claims';
    case GenerateClaimPackages = 'generate-claim-packages';

    // Deadlines (enforced from Milestone 3)
    case ManageDeadlines = 'manage-deadlines';
    case ViewDeadlines = 'view-deadlines';

    // Reporting and exports (enforced from Milestone 4)
    case ViewReports = 'view-reports';
    case ExportReports = 'export-reports';

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
