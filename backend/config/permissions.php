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
| Permissions for modules that do not exist yet (shipments, events,
| evidence, claims, deadlines, reports) are mapped here so the role
| model is complete from the start. They are enforced as each module
| lands; mapping a permission does not create the feature.
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
        Permission::ReviewClaims->value,
        Permission::SubmitClaims->value,
        Permission::RecordClaimPayments->value,
        Permission::ViewClaims->value,
        Permission::GenerateClaimPackages->value,
        Permission::ManageDeadlines->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
        Permission::ExportReports->value,
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
        Permission::ReviewClaims->value,
        Permission::ViewClaims->value,
        Permission::GenerateClaimPackages->value,
        Permission::ManageDeadlines->value,
        Permission::ViewDeadlines->value,
        Permission::ViewReports->value,
        Permission::ExportReports->value,
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
        Permission::CreateClaims->value,
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
        Permission::ReviewClaims->value,
        Permission::SubmitClaims->value,
        Permission::RecordClaimPayments->value,
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
