<?php

use App\OrganizationRole;
use App\Permission;

/*
|--------------------------------------------------------------------------
| Role to permission map
|--------------------------------------------------------------------------
|
| This file is the single place where roles are mapped to permissions.
| Controllers, policies, and gates must check permissions, never role
| names. To change what a role can do, edit this file only.
|
| Report permissions are mapped here before the reporting module
| lands in Milestone 4, so the role model is complete from the start.
| Mapping a permission does not create the feature.
|
| The claim mappings changed in Milestone 3, when claims were
| enforced: the Milestone 1 draft had given dispatchers claim
| creation and billing staff claim review; the Phase 1 spec assigns
| creation to owner, admin, operations manager, and billing staff,
| and review to owner, admin, and operations manager. Operations
| managers gained claim submission in the same correction.
|
*/

return [
    OrganizationRole::Owner->value => Permission::values(),

    OrganizationRole::Admin->value => [
        Permission::ManageOrganization->value,
        Permission::ManageMembers->value,
        Permission::ManageInvitations->value,
        Permission::ViewMembers->value,
        Permission::CreateShipments->value,
        Permission::EditShipments->value,
        Permission::ViewShipments->value,
        Permission::ArchiveShipments->value,
        Permission::ImportShipments->value,
        Permission::ExportShipments->value,
        Permission::CreateEvents->value,
        Permission::EditEvents->value,
        Permission::DeleteEvents->value,
        Permission::ViewEvents->value,
        Permission::UploadEvidence->value,
        Permission::DeleteEvidence->value,
        Permission::VerifyEvidence->value,
        Permission::ViewEvidence->value,
        Permission::CreateClaims->value,
        Permission::EditClaims->value,
        Permission::DeleteClaims->value,
        Permission::ReviewClaims->value,
        Permission::SubmitClaims->value,
        Permission::RecordClaimPayments->value,
        Permission::AttachClaimEvidence->value,
        Permission::ViewClaims->value,
        Permission::GenerateClaimPackages->value,
        Permission::ManageEvidenceRequirements->value,
        Permission::ManageDeadlines->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
        Permission::ExportReports->value,
        Permission::ViewAuditLogs->value,
    ],

    OrganizationRole::OperationsManager->value => [
        Permission::ViewMembers->value,
        Permission::CreateShipments->value,
        Permission::EditShipments->value,
        Permission::ViewShipments->value,
        Permission::ArchiveShipments->value,
        Permission::ImportShipments->value,
        Permission::ExportShipments->value,
        Permission::CreateEvents->value,
        Permission::EditEvents->value,
        Permission::DeleteEvents->value,
        Permission::ViewEvents->value,
        Permission::UploadEvidence->value,
        Permission::DeleteEvidence->value,
        Permission::VerifyEvidence->value,
        Permission::ViewEvidence->value,
        Permission::CreateClaims->value,
        Permission::EditClaims->value,
        Permission::DeleteClaims->value,
        Permission::ReviewClaims->value,
        Permission::SubmitClaims->value,
        Permission::AttachClaimEvidence->value,
        Permission::ViewClaims->value,
        Permission::GenerateClaimPackages->value,
        Permission::ManageEvidenceRequirements->value,
        Permission::ManageDeadlines->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
        Permission::ExportReports->value,
        Permission::ViewAuditLogs->value,
    ],

    OrganizationRole::Dispatcher->value => [
        Permission::ViewMembers->value,
        Permission::CreateShipments->value,
        Permission::EditShipments->value,
        Permission::ViewShipments->value,
        Permission::CreateEvents->value,
        Permission::EditEvents->value,
        Permission::ViewEvents->value,
        Permission::UploadEvidence->value,
        Permission::ViewEvidence->value,
        Permission::AttachClaimEvidence->value,
        Permission::ViewClaims->value,
        Permission::ViewDeadlines->value,
    ],

    OrganizationRole::BillingStaff->value => [
        Permission::ViewMembers->value,
        Permission::ViewShipments->value,
        Permission::ExportShipments->value,
        Permission::ViewEvents->value,
        Permission::UploadEvidence->value,
        Permission::VerifyEvidence->value,
        Permission::ViewEvidence->value,
        Permission::CreateClaims->value,
        Permission::EditClaims->value,
        Permission::DeleteClaims->value,
        Permission::SubmitClaims->value,
        Permission::RecordClaimPayments->value,
        Permission::AttachClaimEvidence->value,
        Permission::ViewClaims->value,
        Permission::GenerateClaimPackages->value,
        Permission::ManageDeadlines->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
        Permission::ExportReports->value,
    ],

    OrganizationRole::Viewer->value => [
        Permission::ViewMembers->value,
        Permission::ViewShipments->value,
        Permission::ViewEvents->value,
        Permission::ViewEvidence->value,
        Permission::ViewClaims->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
    ],
];
