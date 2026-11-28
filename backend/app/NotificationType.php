<?php

namespace App;

/**
 * Notification event types.
 *
 * Each type is one thing a member can be told about, in the app,
 * by email, or both, per their preferences. The values are stable
 * API strings: the frontend preference form and the stored
 * notification rows both use them.
 */
enum NotificationType: string
{
    case ClaimCreated = 'claim.created';
    case ClaimEvidenceMissing = 'claim.evidence_missing';
    case ClaimReadyForReview = 'claim.ready_for_review';
    case DeadlineUpcoming = 'deadline.upcoming';
    case DeadlineOverdue = 'deadline.overdue';
    case ClaimSubmitted = 'claim.submitted';
    case ClaimAccepted = 'claim.accepted';
    case ClaimRejected = 'claim.rejected';
    case PaymentRecorded = 'payment.recorded';
    case OrganizationInvitation = 'organization.invitation';
    case SupportTicketReply = 'support.ticket_reply';
    case ImportCompleted = 'import.completed';
    case ImportFailed = 'import.failed';
    case TmsSyncCompleted = 'tms.sync_completed';
    case TmsSyncFailed = 'tms.sync_failed';
    case CommunicationChannelConnected = 'comms.channel_connected';
    case CommunicationChannelTested = 'comms.channel_tested';
    case DriverLinkCreated = 'comms.driver_link_created';
    case DriverLinkUsed = 'comms.driver_link_used';
    case DriverLinkExpired = 'comms.driver_link_expired';
    case CommunicationEmailSent = 'comms.email_sent';
    case CommunicationEmailFailed = 'comms.email_failed';
    case ReconciliationPaymentRecorded = 'reconciliation.payment_recorded';
    case ReconciliationCompleted = 'reconciliation.completed';
    case WorkflowNotification = 'workflow.notification';
    case WorkflowEscalated = 'workflow.escalated';
    case WorkflowApprovalNeeded = 'workflow.approval_needed';
    case WorkflowRunFailed = 'workflow.run_failed';
    case DocumentNeedsVerification = 'document.needs_verification';
    case WebhookDeliveryFailed = 'webhook.delivery_failed';
    case EnterpriseAlertTriggered = 'enterprise.alert_triggered';

    public function label(): string
    {
        return match ($this) {
            self::ClaimCreated => 'New claim created',
            self::ClaimEvidenceMissing => 'Missing evidence',
            self::ClaimReadyForReview => 'Claim ready for review',
            self::DeadlineUpcoming => 'Upcoming deadline',
            self::DeadlineOverdue => 'Overdue deadline',
            self::ClaimSubmitted => 'Claim submitted',
            self::ClaimAccepted => 'Claim accepted',
            self::ClaimRejected => 'Claim rejected',
            self::PaymentRecorded => 'Payment recorded',
            self::OrganizationInvitation => 'Organization invitation',
            self::SupportTicketReply => 'Support ticket reply',
            self::ImportCompleted => 'Import completed',
            self::ImportFailed => 'Import failed',
            self::TmsSyncCompleted => 'TMS sync completed',
            self::TmsSyncFailed => 'TMS sync failed',
            self::CommunicationChannelConnected => 'Communication channel connected',
            self::CommunicationChannelTested => 'Communication channel tested',
            self::DriverLinkCreated => 'Driver evidence link created',
            self::DriverLinkUsed => 'Driver evidence link used',
            self::DriverLinkExpired => 'Driver evidence link expired',
            self::CommunicationEmailSent => 'Communication email sent',
            self::CommunicationEmailFailed => 'Communication email failed',
            self::ReconciliationPaymentRecorded => 'Reconciliation payment recorded',
            self::ReconciliationCompleted => 'Reconciliation completed',
            self::WorkflowNotification => 'Workflow notification',
            self::WorkflowEscalated => 'Workflow escalation',
            self::WorkflowApprovalNeeded => 'Workflow approval needed',
            self::WorkflowRunFailed => 'Workflow run failed',
            self::DocumentNeedsVerification => 'Document needs verification',
            self::WebhookDeliveryFailed => 'Webhook delivery failed',
            self::EnterpriseAlertTriggered => 'Enterprise alert triggered',
        };
    }

    /**
     * Every type value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
