export type OrganizationRole =
  | "owner"
  | "admin"
  | "operations_manager"
  | "dispatcher"
  | "billing_staff"
  | "viewer";

export type MembershipStatus = "active" | "inactive";

export type InvitationStatus = "pending" | "accepted" | "expired";

export interface Organization {
  id: string;
  name: string;
  slug: string;
  timezone: string;
  settings: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  current_user_role?: OrganizationRole;
  current_user_status?: MembershipStatus;
}

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  organizations?: Organization[];
}

export interface OrganizationMember {
  id: number;
  organization_id: string;
  user_id: number;
  user: {
    id: number;
    name: string;
    email: string;
  };
  role: OrganizationRole;
  role_label: string;
  status: MembershipStatus;
  joined_at: string | null;
  created_at: string;
}

export interface OrganizationInvitation {
  id: string;
  organization_id: string;
  email: string;
  role: OrganizationRole;
  role_label: string;
  status: InvitationStatus;
  expires_at: string;
  accepted_at: string | null;
  invited_by: {
    id: number;
    name: string;
    email: string;
  } | null;
  created_at: string;
}

export interface MoneyAmount {
  currency: string;
  amount: string;
}

export interface DashboardCounts {
  members_total: number;
  members_active: number;
  members_inactive: number;
  invitations_pending: number;
  shipments_total: number;
  shipments_with_events: number;
  shipments_with_opportunities: number;
  events_open: number;
  events_total: number;
  evidence_total: number;
  claims_total: number;
  claims_open: number;
  claims_open_opportunities: number;
  claims_awaiting_evidence: number;
  claims_ready: number;
  claims_submitted: number;
  claims_approved: number;
  claims_rejected: number;
  claims_paid: number;
  claims_closed: number;
  deadlines_upcoming: number;
  deadlines_due_soon: number;
  deadlines_overdue: number;
  deadlines_next_14_days: number;
}

export interface DashboardData {
  organization: {
    id: string;
    name: string;
    slug: string;
    timezone: string;
    created_at: string;
  };
  current_user_role: OrganizationRole | null;
  member_since: string | null;
  counts: DashboardCounts;
  claims_by_status: { status: string; label: string; count: number }[];
  financials: {
    potential: MoneyAmount[];
    submitted: MoneyAmount[];
    accepted: MoneyAmount[];
    recovered: MoneyAmount[];
  };
  upcoming_deadlines: {
    deadline_id: string;
    claim_id: string;
    claim_reference: string;
    deadline_at: string;
    status: string;
    status_label: string;
  }[];
  filters: {
    date_from: string | null;
    date_to: string | null;
    status: string | null;
  };
  claims_filtered?: {
    status: string;
    label: string;
    count: number;
    requested_amounts: MoneyAmount[];
  };
}

export type NotificationEventType =
  | "claim.created"
  | "claim.evidence_missing"
  | "claim.ready_for_review"
  | "deadline.upcoming"
  | "deadline.overdue"
  | "claim.submitted"
  | "claim.accepted"
  | "claim.rejected"
  | "payment.recorded"
  | "organization.invitation"
  | "support.ticket_reply"
  | "import.completed"
  | "import.failed";

export interface AppNotification {
  id: string;
  type: NotificationEventType;
  type_label: string;
  title: string;
  body: string;
  data: Record<string, unknown> | null;
  is_unread: boolean;
  read_at: string | null;
  email_sent_at: string | null;
  created_at: string;
}

export interface NotificationsMeta extends PaginatedMeta {
  unread_count: number;
}

export interface NotificationPreference {
  event_type: NotificationEventType;
  label: string;
  in_app: boolean;
  email: boolean;
}

export type AuditActorType = "user" | "system";

export interface AuditLog {
  id: string;
  organization_id: string;
  actor_type: AuditActorType;
  actor: { id: number; name: string } | null;
  action: string;
  entity_type: string;
  entity_id: string;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  correlation_id: string | null;
  ip_address: string | null;
  created_at: string;
}

export interface ReportSummary {
  counts_by_status: { status: string; label: string; count: number }[];
  counts_by_event_type: { category: string; label: string; count: number }[];
  money: {
    potential: MoneyAmount[];
    submitted: MoneyAmount[];
    accepted: MoneyAmount[];
    recovered: MoneyAmount[];
    rejected: MoneyAmount[];
    outstanding: MoneyAmount[];
  };
  filters: {
    date_from: string | null;
    date_to: string | null;
  };
}

export interface RecoveryHistoryMonth {
  month: string;
  amounts: MoneyAmount[];
}

export interface PaginatedMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** Shape of Laravel validation error responses. */
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  fields?: Record<string, string[]>;
  /** Machine-readable error code, e.g. "plan_limit_exceeded" on a 402. */
  error?: string;
  /** Included on a 402 plan-limit refusal: what limit was hit and the upgrade that raises it. */
  limit?: EntitlementLimitInfo;
}

/** The `limit` block of a 402 plan-limit refusal. */
export interface EntitlementLimitInfo {
  resource: string;
  limit: number | null;
  used: number;
  plan: string;
  upgrade_plan: string | null;
  upgrade_plan_name: string | null;
}

/** Plan limits for an organization, from GET /billing/usage `entitlements`. */
export interface EntitlementLimits {
  plan: string;
  plan_name: string;
  claims_per_month: number | null;
  members: number | null;
  storage_bytes: number | null;
  pdf_packages: boolean;
  reports: boolean;
}

/** Current usage against the plan limits, from GET /billing/usage `entitlements`. */
export interface EntitlementUsage {
  claims_created: number;
  claims_carryover: number;
  members: number;
  pending_invitations: number;
  storage_bytes: number;
}

/** The cheapest listed plan that raises a limit, from GET /billing/usage `entitlements`. */
export interface EntitlementUpgradeOption {
  key: string;
  name: string;
}

/** Per-resource upgrade options, from GET /billing/usage `entitlements`. */
export interface EntitlementUpgrade {
  claims: EntitlementUpgradeOption | null;
  members: EntitlementUpgradeOption | null;
  storage: EntitlementUpgradeOption | null;
  pdf_packages: EntitlementUpgradeOption | null;
  reports: EntitlementUpgradeOption | null;
}

/** Plan limits and usage for an organization, from GET /billing/usage. */
export interface EntitlementSummary {
  plan: string;
  plan_name: string;
  status: string;
  period_start: string | null;
  period_end: string | null;
  carriers: number;
  limits: EntitlementLimits;
  usage: EntitlementUsage;
  upgrade: EntitlementUpgrade;
}

export type ShipmentStatus =
  | "draft"
  | "planned"
  | "in_transit"
  | "delivered"
  | "completed"
  | "archived";

export type EventStatus = "potential" | "under_review" | "dismissed";

export type VerificationStatus = "pending" | "verified" | "rejected";

export type DocumentCategory =
  | "bill_of_lading"
  | "proof_of_delivery"
  | "rate_confirmation"
  | "arrival_departure_record"
  | "appointment_confirmation"
  | "broker_communication"
  | "driver_photo"
  | "receipt"
  | "lumper_receipt"
  | "other";

export interface Carrier {
  id: string;
  organization_id: string;
  name: string;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  dot_number: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface Shipment {
  id: string;
  organization_id: string;
  internal_reference: string;
  external_load_reference: string | null;
  broker_or_customer_name: string;
  carrier_id: string | null;
  driver_name: string | null;
  driver_contact: string | null;
  pickup_location: string | null;
  delivery_location: string | null;
  pickup_appointment: string | null;
  delivery_appointment: string | null;
  actual_arrival_at: string | null;
  actual_departure_at: string | null;
  base_rate: string | null;
  currency: string;
  status: ShipmentStatus;
  status_label: string;
  notes: string | null;
  carrier: { id: string; name: string } | null;
  created_by: { id: number; name: string } | null;
  events_count: number;
  evidence_count: number;
  custom_field_values?: CustomFieldValues;
  created_at: string;
  updated_at: string;
}

export interface EventCategory {
  id: string;
  slug: string;
  label: string;
  description: string | null;
  is_system: boolean;
  is_active?: boolean;
  default_estimated_amount?: string | null;
  default_currency?: string | null;
}

export type CustomFieldType = "text" | "number" | "date" | "select" | "boolean";

export type CustomFieldResourceType = "shipment" | "claim";

/** Values keyed by custom field field_key, as sent to and read from the API. */
export type CustomFieldValues = Record<
  string,
  string | number | boolean | null
>;

export interface CustomField {
  id: string;
  organization_id: string;
  resource_type: CustomFieldResourceType;
  field_key: string;
  label: string;
  field_type: CustomFieldType;
  is_required: boolean;
  options: string[] | null;
  sort_order: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface EvidenceTemplateItem {
  id: string;
  document_category: DocumentCategory;
  label: string;
  is_required: boolean;
  sort_order: number;
}

export interface EvidenceTemplate {
  id: string;
  name: string;
  description: string | null;
  is_active: boolean;
  items: EvidenceTemplateItem[];
  created_at: string;
  updated_at: string;
}

export type DeadlineScopeType = "global" | "claim_category" | "event_category";

export interface DeadlineSetting {
  id: string;
  scope_type: DeadlineScopeType;
  scope_value: string | null;
  submit_within_days: number;
  reminder_days_before: number[] | null;
  created_at: string;
  updated_at: string;
}

export interface ExportTemplate {
  id: string;
  resource_type: CustomFieldResourceType;
  name: string;
  columns: string[];
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

export interface AvailableExportColumn {
  key: string;
  label: string;
}

/** Organization-level customization settings stored in organizations.settings. */
export interface OrganizationSettings {
  default_currency?: string;
  date_format?: "Y-m-d" | "d/m/Y" | "m/d/Y";
  claim_reference_prefix?: string;
}

export const DATE_FORMATS: { value: string; label: string }[] = [
  { value: "Y-m-d", label: "2026-11-19 (Y-m-d)" },
  { value: "d/m/Y", label: "19/11/2026 (d/m/Y)" },
  { value: "m/d/Y", label: "11/19/2026 (m/d/Y)" },
];

export interface ShipmentEvent {
  id: string;
  organization_id: string;
  shipment_id: string;
  event_category_id: string;
  category: { id: string; slug: string; label: string };
  event_date: string;
  start_time: string | null;
  end_time: string | null;
  duration_minutes: number | null;
  location: string | null;
  description: string | null;
  estimated_amount: string | null;
  currency: string | null;
  notes: string | null;
  status: EventStatus;
  status_label: string;
  reported_by: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

export interface EvidenceFile {
  id: string;
  organization_id: string;
  shipment_id: string;
  shipment_event_id: string | null;
  document_category: DocumentCategory;
  document_category_label: string;
  original_filename: string;
  mime_type: string;
  file_size: number;
  uploaded_by: { id: number; name: string } | null;
  description: string | null;
  verification_status: VerificationStatus;
  /** True for image/* and application/pdf; those files can be previewed inline. */
  previewable: boolean;
  created_at: string;
  updated_at: string;
}

export interface ImportPreviewRow {
  index: number;
  data: Record<string, string | number | null>;
  errors: Record<string, string[]>;
  is_duplicate: boolean;
  /** True when the row has no field errors and is not a duplicate. */
  valid: boolean;
}

export interface ImportPreview {
  preview_token: string;
  summary: {
    total: number;
    valid: number;
    invalid: number;
    duplicate: number;
  };
  columns: string[];
  rows: ImportPreviewRow[];
}

export interface ImportConfirmResult {
  imported: number;
  skipped: number;
  skipped_rows: {
    index: number;
    reason: string;
    is_duplicate: boolean;
    errors: Record<string, string[]>;
  }[];
}

/** One step of the guided onboarding checklist. */
export interface OnboardingStep {
  key: string;
  label: string;
  description: string;
  completed: boolean;
  href: string;
}

export interface OnboardingProgress {
  steps: OnboardingStep[];
  completed_count: number;
  total_count: number;
  all_completed: boolean;
  dismissed: boolean;
}

/** A saved set of list filters for shipments or claims. */
export interface SavedView {
  id: string;
  resource_type: "shipment" | "claim";
  name: string;
  filters: Record<string, string>;
  created_at: string;
  updated_at: string;
}

/** A note left by a reviewer on a claim under review. */
export interface ReviewNote {
  id: string;
  note: string;
  author: { id: number; name: string } | null;
  created_at: string;
}

export type ClaimStatus =
  | "potential"
  | "evidence_required"
  | "under_review"
  | "ready_to_submit"
  | "submitted"
  | "accepted"
  | "partially_accepted"
  | "rejected"
  | "paid"
  | "closed";

export type ClaimCategory =
  | "detention"
  | "layover"
  | "tonu"
  | "lumper"
  | "additional_stop"
  | "additional_miles"
  | "other";

export interface Claim {
  id: string;
  organization_id: string;
  claim_reference: string;
  shipment_id: string;
  shipment: {
    id: string;
    internal_reference: string;
    broker_or_customer_name: string;
    status: string;
  } | null;
  shipment_event_id: string | null;
  event: {
    id: string;
    event_date: string;
    description: string | null;
  } | null;
  claim_category: ClaimCategory;
  claim_category_label: string;
  description: string | null;
  requested_amount: string;
  accepted_amount: string | null;
  recovered_amount: string | null;
  currency: string;
  internal_notes: string | null;
  external_broker_reference: string | null;
  submission_deadline_at: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  paid_at: string | null;
  rejection_reason: string | null;
  payment_reference: string | null;
  status: ClaimStatus;
  status_label: string;
  allowed_transitions: { value: string; label: string }[];
  created_by: { id: number; name: string } | null;
  updated_by: { id: number; name: string } | null;
  evidence_count: number;
  active_deadline: ClaimDeadline | null;
  latest_document: GeneratedDocument | null;
  custom_field_values?: CustomFieldValues;
  created_at: string;
  updated_at: string;
}

export interface ClaimStatusHistory {
  id: string;
  claim_id: string;
  from_status: string | null;
  to_status: string;
  changed_by: { id: number; name: string } | null;
  reason: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export type ReadinessStatus = "not_started" | "partial" | "ready";

export interface Readiness {
  claim_id: string;
  required: { value: string; label: string }[];
  optional: { value: string; label: string }[];
  collected: { value: string; label: string }[];
  missing: { value: string; label: string }[];
  optional_collected: { value: string; label: string }[];
  readiness_percentage: number;
  readiness_status: ReadinessStatus;
  readiness_status_label: string;
  has_requirements: boolean;
  is_overridden: boolean;
  disclaimer: string;
  snapshots: {
    document_category: string;
    document_category_label: string;
    is_required: boolean;
    is_fulfilled: boolean;
    evidence_file_id: string | null;
    snapshot_at: string;
    overridden_by: { id: number; name: string } | null;
    override_reason: string | null;
    overridden_at: string | null;
  }[];
}

export interface EvidenceRequirement {
  id: string;
  organization_id: string;
  event_category_id: string | null;
  event_category: { id: string; slug: string; label: string } | null;
  document_category: DocumentCategory;
  document_category_label: string;
  is_required: boolean;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export type DeadlineStatus =
  | "upcoming"
  | "due_soon"
  | "overdue"
  | "met"
  | "missed";

export interface ClaimDeadline {
  id: string;
  organization_id: string;
  claim_id: string;
  claim: {
    id: string;
    claim_reference: string;
    status: string;
    status_label: string;
  };
  deadline_at: string;
  timezone: string;
  source_reference: string | null;
  reminder_days_before: number[];
  status: DeadlineStatus;
  status_label: string;
  superseded_at: string | null;
  created_at: string;
  updated_at: string;
}

export type GeneratedDocumentStatus = "queued" | "ready" | "failed";

export interface GeneratedDocument {
  id: string;
  claim_id: string;
  document_type: string;
  document_type_label: string;
  file_size: number | null;
  generated_by: { id: number; name: string } | null;
  includes_internal_notes: boolean;
  status: GeneratedDocumentStatus;
  status_label: string;
  error_message: string | null;
  generated_at: string | null;
  created_at: string;
}

/** Overall health of the application or of one subsystem. */
export type HealthStatus = "ok" | "degraded" | "down" | "skipped";

export interface HealthCheck {
  status: HealthStatus;
  latency_ms: number | null;
  detail: string | null;
}

export interface HealthChecks {
  database: HealthCheck;
  cache: HealthCheck;
  queue: HealthCheck;
  storage: HealthCheck;
}

export interface QueueTotals {
  pending: number;
  reserved: number;
  delayed: number;
  failed: number;
}

/** Authenticated health payload from GET /ops/health. */
export interface OpsHealth {
  status: "ok" | "degraded" | "down";
  checks: HealthChecks;
  checked_at: string;
  version: string;
  php_version: string;
  laravel_version: string;
  queue_totals: QueueTotals;
  queue_supported: boolean;
}

export interface QueueStatsRow {
  queue: string;
  pending: number;
  reserved: number;
  delayed: number;
  oldest_pending_age_seconds: number | null;
}

/** Queue breakdown from GET /ops/queue. */
export interface QueueStats {
  supported: boolean;
  driver: string;
  connection: string;
  queues: QueueStatsRow[];
  totals: QueueTotals;
}

/** One row of the failed-jobs table. The exception is truncated server-side. */
export interface FailedJob {
  uuid: string;
  connection: string;
  queue: string;
  job: string;
  failed_at: string;
  exception: string;
}

/** One parsed line from the application log. */
export interface RecentErrorEntry {
  datetime: string;
  level: string;
  message: string;
  exception: string | null;
  route: string | null;
  method: string | null;
  correlation_id: string | null;
}

/** Recent log errors from GET /ops/errors. */
export interface RecentErrors {
  entries: RecentErrorEntry[];
  scanned_lines: number;
}

/** One backup run from GET /backups. */
export interface BackupRun {
  id: string;
  organization_id: string | null;
  driver: string;
  status: "running" | "completed" | "failed";
  size_bytes: number | null;
  checksum: string | null;
  error_message: string | null;
  restored_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Result of re-verifying a backup's checksums. */
export interface BackupVerification {
  ok: boolean;
  mismatches: string[];
}

/** One production-readiness check from GET /security/check. */
export interface SecurityCheckItem {
  id: string;
  label: string;
  status: "pass" | "warn" | "fail";
  detail: string;
}

/** Production-readiness result from GET /security/check. */
export interface SecurityCheckResult {
  overall: "pass" | "warn" | "fail";
  checks: SecurityCheckItem[];
}

/** Retention in days per kind. 0 keeps forever. */
export interface RetentionDays {
  audit_logs: number;
  notifications: number;
  job_executions: number;
  failed_documents: number;
  security_events: number;
}

/** Retention settings from GET /retention. */
export interface RetentionSettings {
  effective: RetentionDays;
  defaults: RetentionDays;
  overrides: Partial<RetentionDays>;
}

/** One security event from GET /security/events. */
export interface SecurityEvent {
  id: string;
  organization_id: string | null;
  user_id: number | null;
  type: string;
  ip_address: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

/** One plan from GET /billing/plans. Prices are USD cents. */
export interface BillingPlan {
  key: string;
  name: string;
  monthly_cents: number;
  annual_cents: number;
  per_extra_carrier_cents: number;
  included_carriers: number;
  trucks: string | null;
  limits: Record<string, number | string | boolean | null>;
  description: string | null;
  listed: boolean;
}

/** Subscription statuses from the billing API. */
export type BillingSubscriptionStatus =
  | "trialing"
  | "active"
  | "past_due"
  | "canceled";

/** Invoice statuses from the billing API. */
export type BillingInvoiceStatus =
  | "draft"
  | "open"
  | "paid"
  | "past_due"
  | "void"
  | "uncollectible"
  | "refunded"
  | "partially_refunded";

/** Invoice line kinds from the billing API. */
export type BillingInvoiceLineKind =
  | "subscription"
  | "proration_credit"
  | "proration_charge"
  | "success_fee"
  | "cap_adjustment"
  | "credit_applied";

/** One line of an invoice from GET /billing/invoices. */
export interface BillingInvoiceLine {
  id: string;
  kind: BillingInvoiceLineKind;
  description: string;
  amount_cents: number;
}

/** One invoice from GET /billing/invoices. */
export interface BillingInvoice {
  id: string;
  number: string;
  status: BillingInvoiceStatus;
  currency: string;
  subtotal_cents: number;
  total_cents: number;
  amount_paid_cents: number;
  amount_refunded_cents: number;
  period_start: string | null;
  period_end: string | null;
  due_date: string | null;
  paid_at: string | null;
  payment_attempts: number;
  next_retry_at: string | null;
  failure_code: string | null;
  failure_message: string | null;
  memo: string | null;
  lines: BillingInvoiceLine[];
  created_at: string;
}

/** One refund from POST /billing/invoices/{id}/refund. */
export interface BillingRefund {
  id: string;
  invoice_id: string;
  amount_cents: number;
  reason: string | null;
  status: string;
  provider_refund_id: string | null;
  failure_message: string | null;
  created_at: string;
}

/** One billing event from GET /billing/events. */
export interface BillingEvent {
  id: string;
  type: string;
  status: string;
  payload: Record<string, unknown>;
  error_message: string | null;
  created_at: string;
}

/** Support ticket statuses, categories, and priorities from the API. */
export type SupportTicketStatus =
  | "open"
  | "in_progress"
  | "waiting_on_customer"
  | "resolved"
  | "closed";

export type SupportTicketCategory =
  | "billing"
  | "technical"
  | "feature_request"
  | "account"
  | "other";

export type SupportTicketPriority = "low" | "normal" | "high" | "urgent";

/** One message on a support ticket. */
export interface SupportTicketMessage {
  id: string;
  user: { id: string; name: string } | null;
  body: string;
  internal: boolean;
  created_at: string;
}

/** One support ticket from GET /support/tickets. */
export interface SupportTicket {
  id: string;
  subject: string;
  category: SupportTicketCategory;
  priority: SupportTicketPriority;
  status: SupportTicketStatus;
  requester: { id: string; name: string; email: string } | null;
  resolved_at: string | null;
  closed_at: string | null;
  created_at: string;
  updated_at: string;
  messages: SupportTicketMessage[];
}

/** One organization-specific inbound email address. */
export interface InboundEmailAddress {
  id: string;
  local_part: string;
  address: string;
  label: string | null;
  active: boolean;
  created_at: string;
  updated_at: string;
}

export type InboundEmailStatus =
  | "received"
  | "processed"
  | "matched"
  | "unmatched"
  | "failed"
  | "dismissed";

/** One extracted shipment reference candidate from an inbound email. */
export interface ExtractedReference {
  value: string;
  source: string;
  matched_shipment_id: string | null;
  matched_field: string | null;
}

/** One attachment decoded from an inbound email. */
export interface InboundEmailAttachment {
  id: string;
  filename: string;
  mime_type: string;
  size_bytes: number;
  evidence_file_id: string | null;
}

/** One inbound email from GET /email-intake/emails. */
export interface InboundEmail {
  id: string;
  from: string;
  to: string;
  subject: string | null;
  text_body: string | null;
  message_id: string;
  status: InboundEmailStatus;
  matched_shipment_id: string | null;
  matched_shipment: {
    id: string;
    internal_reference: string;
    external_load_reference: string | null;
    broker_or_customer_name: string;
  } | null;
  inbound_email_address_id: string | null;
  extracted_references: ExtractedReference[];
  match_reason: string | null;
  match_confidence: string | null;
  dismissed_reason: string | null;
  processing_notes: string | null;
  created_at: string;
  updated_at: string;
  attachments: InboundEmailAttachment[];
}

/** A saved CSV mapping from GET /imports/mappings (v1.3.1). */
export interface ImportMapping {
  id: string;
  name: string;
  target: "shipments";
  mapping: Record<string, string>;
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

export type ImportRunSource = "upload" | "schedule" | "sync";

export type ImportRunStatus =
  | "pending"
  | "running"
  | "completed"
  | "partial"
  | "failed";

/** One import run from GET /imports/runs (v1.3.1). */
export interface ImportRun {
  id: string;
  source: ImportRunSource;
  import_mapping_id: string | null;
  import_schedule_id: string | null;
  sync_run_id: string | null;
  filename: string | null;
  status: ImportRunStatus;
  total_rows: number;
  imported_rows: number;
  skipped_rows: number;
  duplicate_rows: number;
  error_rows: number;
  summary: Record<string, number> | null;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  rows?: ImportRow[];
}

export type ImportRowStatus =
  | "imported"
  | "skipped"
  | "duplicate"
  | "failed"
  | "corrected";

/** One CSV row of an import run (v1.3.1). */
export interface ImportRow {
  id: string;
  import_run_id: string;
  row_index: number;
  data: Record<string, unknown>;
  status: ImportRowStatus;
  errors: Record<string, string[]> | null;
  shipment_id: string | null;
  corrected_at: string | null;
  created_at: string;
}

export type ImportFrequency = "daily" | "weekly" | "manual";

/** A scheduled import from GET /imports/schedules (v1.3.1). */
export interface ImportSchedule {
  id: string;
  name: string;
  import_mapping_id: string | null;
  frequency: ImportFrequency;
  next_run_at: string | null;
  last_run_at: string | null;
  last_import_run_id: string | null;
  active: boolean;
  created_at: string;
  updated_at: string;
}

export type SyncRunStatus = "pending" | "running" | "completed" | "failed";

/** A synchronization workflow from GET /imports/sync-runs (v1.3.1). */
export interface SyncRun {
  id: string;
  name: string;
  import_mapping_id: string | null;
  status: SyncRunStatus;
  import_run_id: string | null;
  summary: Record<string, number> | null;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  updated_at: string;
}

/** One monitoring check run from GET /ops/monitoring. */
export interface MonitoringCheck {
  id: string;
  status: "ok" | "degraded" | "down";
  checks: Record<string, HealthCheck>;
  failed_jobs: number;
  queue_depth: number;
  checked_at: string;
}

/** One metered success-fee usage record from GET /billing/usage. */
export interface BillingUsageRecord {
  id: string;
  claim_id: string | null;
  amount_cents: number;
  fee_cents: number;
  recorded_at: string;
}

/** The current period's metered usage from GET /billing/usage. */
export interface BillingUsageSummary {
  plan: string;
  period_start: string | null;
  period_end: string | null;
  records: BillingUsageRecord[];
  recovered_cents: number;
  accrued_fee_cents: number;
  cap_cents: number | null;
  cap_remaining_cents: number | null;
  /** Plan limits and usage for the period (v1.2.1). */
  entitlements?: EntitlementSummary;
}

/** The current subscription from GET /billing/subscription. */
export interface BillingSubscription {
  id: string;
  plan: string;
  plan_name: string;
  status: BillingSubscriptionStatus;
  interval: "monthly" | "annual";
  current_period_start: string | null;
  current_period_end: string | null;
  trial_ends_at: string | null;
  cancel_at_period_end: boolean;
  canceled_at: string | null;
  pending_plan: string | null;
  pending_interval: string | null;
  pending_extra_carriers: number | null;
  provider: string;
  payment_method_brand: string | null;
  payment_method_last4: string | null;
  extra_carriers: number;
  credit_balance_cents: number;
  monthly_cents: number;
  period_cents: number;
  success_fee_capped: boolean;
  /** Included by GET /billing/subscription alongside the subscription. */
  usage?: BillingUsageSummary;
}

/** A mock checkout session from POST /billing/checkout. */
export interface BillingCheckoutSession {
  id: string;
  provider_session_id: string;
  plan: string;
  interval: "monthly" | "annual";
  extra_carriers: number;
  status: string;
  expires_at: string | null;
}

/** Proration for an immediate plan change, from POST /billing/plan-changes. */
export interface BillingProration {
  credit_cents: number;
  charge_cents: number;
  net_cents: number;
  remaining_days: number;
  period_days: number;
  is_upgrade: boolean;
}

/** A mock billing portal session from POST /billing/portal-sessions. */
export interface BillingPortalSession {
  url: string;
  token: string;
  expires_at: string;
}

export type TmsProviderName = "mock" | "trimble-tmw";

/** One TMS provider from GET /tms/providers (v1.3.2). */
export interface TmsProvider {
  name: TmsProviderName;
  label: string;
  live_available: boolean;
  capabilities: {
    shipments: boolean;
    status: boolean;
    appointments: boolean;
    documents: boolean;
    webhooks: boolean;
  };
  credential_fields: string[];
  live_note: string;
}

/** Integration sync settings from the TMS payload (v1.3.2). */
export interface TmsSettings {
  sync_shipments: boolean;
  sync_status: boolean;
  sync_appointments: boolean;
  sync_documents: boolean;
  auto_sync: boolean;
  sync_interval_hours: number;
}

/** One TMS integration from GET /tms/integrations (v1.3.2). */
export interface TmsIntegration {
  id: string;
  provider: TmsProviderName;
  provider_label: string;
  live_available: boolean;
  name: string;
  status: "active" | "disabled";
  settings: TmsSettings;
  has_credentials: boolean;
  credential_fields_present: string[];
  last_sync_at: string | null;
  last_error: string | null;
  created_at: string;
  updated_at: string;
}

export type TmsSyncKind =
  | "shipments"
  | "status"
  | "appointments"
  | "documents"
  | "full";

export type TmsSyncRunStatus = "pending" | "running" | "completed" | "failed";

/** One TMS sync run from GET /tms/sync-runs (v1.3.2). */
export interface TmsSyncRun {
  id: string;
  tms_integration_id: string;
  kind: TmsSyncKind;
  status: TmsSyncRunStatus;
  summary: {
    fetched: number;
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    documents_created: number;
    kinds: Record<string, Record<string, number>>;
  } | null;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Connection test result from POST /tms/integrations/{id}/test (v1.3.2). */
export interface TmsConnectionTest {
  ok: boolean;
  live_available: boolean;
  message: string;
  token: string | null;
  expires_at: string | null;
}

/** One communication provider from GET /comms/providers (v1.3.3). */
export interface CommunicationProvider {
  name: string;
  label: string;
  live_available: boolean;
  webhook_url_required: boolean;
  webhook_url_hint: string;
}

/** Events a communication channel can subscribe to (v1.3.3). */
export type CommunicationChannelEvent =
  | "claim_created"
  | "evidence_received"
  | "driver_link_used"
  | "sync_completed"
  | "delivery_failed";

/** One communication channel from GET /comms/channels (v1.3.3). */
export interface CommunicationChannel {
  id: string;
  provider: string;
  provider_label: string;
  live_available: boolean;
  name: string;
  status: "active" | "disabled";
  settings: Record<CommunicationChannelEvent, boolean>;
  has_webhook_url: boolean;
  last_error: string | null;
  last_tested_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Channel test result from POST /comms/channels/{id}/test (v1.3.3). */
export interface CommunicationChannelTest {
  ok: boolean;
  live_available: boolean;
  message: string;
  delivery_id: string | null;
}

/** Delivery status for a channel message (v1.3.3). */
export type CommunicationDeliveryStatus = "logged" | "sent" | "failed";

/** One recorded channel delivery from GET /comms/deliveries (v1.3.3). */
export interface CommunicationDelivery {
  id: string;
  communication_channel_id: string;
  event: CommunicationChannelEvent | "test";
  title: string;
  body: string;
  status: CommunicationDeliveryStatus;
  detail: string | null;
  payload: Record<string, unknown> | null;
  attempted_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Driver evidence link state (v1.3.3). */
export type DriverEvidenceLinkState = "usable" | "used" | "expired" | "revoked";

/** One driver evidence link from GET /comms/driver-links (v1.3.3). */
export interface DriverEvidenceLink {
  id: string;
  shipment_id: string | null;
  claim_id: string | null;
  shipment_reference: string | null;
  claim_reference: string | null;
  driver_name: string | null;
  driver_email: string | null;
  state: DriverEvidenceLinkState;
  expires_at: string;
  max_uses: number;
  used_count: number;
  uses_left: number;
  revoked_at: string | null;
  created_at: string;
  updated_at: string;
}

/** Create response: the raw token is returned once (v1.3.3). */
export interface DriverEvidenceLinkCreated extends DriverEvidenceLink {
  token: string;
  submit_url: string;
}

/** One driver evidence submission from GET /comms/driver-links/{id}/submissions (v1.3.3). */
export interface DriverEvidenceSubmission {
  id: string;
  driver_evidence_link_id: string;
  shipment_id: string | null;
  claim_id: string | null;
  evidence_file_id: string | null;
  driver_name: string | null;
  notes: string | null;
  submitted_at: string | null;
  created_at: string;
}

/** Public driver evidence page from GET /driver-evidence/{token} (v1.3.3). */
export interface DriverEvidencePage {
  organization_name: string;
  shipment_reference: string | null;
  claim_reference: string | null;
  driver_name: string | null;
  state: DriverEvidenceLinkState;
  expires_at: string;
  uses_left: number;
}

/** Payment method for a ledger payment (v1.5.0). */
export type ReconciliationPaymentMethod =
  | "wire_transfer"
  | "ach"
  | "cheque"
  | "cash"
  | "card"
  | "other";

/** One ledger payment from GET /reconciliation/ledger (v1.5.0). */
export interface ClaimPayment {
  id: string;
  organization_id: string;
  claim_id: string;
  amount: string;
  currency: string;
  payment_method: ReconciliationPaymentMethod;
  payment_method_label: string;
  payment_reference: string | null;
  paid_at: string;
  notes: string | null;
  recorded_by: string | null;
  created_at: string;
}

/** Balance status for a claim (v1.5.0). */
export type ReconciliationBalanceStatus =
  | "unpaid"
  | "partial"
  | "paid"
  | "overpaid";

/** One claim balance from GET /reconciliation/claims/{id} (v1.5.0). */
export interface ClaimBalance {
  claim_id: string;
  claim_reference: string;
  status: ClaimStatus;
  currency: string;
  requested_amount: string;
  approved_amount: string | null;
  due_amount: string;
  paid_amount: string;
  outstanding_amount: string;
  ledger_amount: string;
  legacy_amount: string;
  balance_status: ReconciliationBalanceStatus;
  payments: ClaimPayment[];
}

/** Organization-wide reconciliation totals (v1.5.0). */
export interface ReconciliationSummary {
  organization_id: string;
  claims_count: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
  by_balance_status: Record<ReconciliationBalanceStatus, number>;
  currencies: string[];
}

/** One discrepancy in a reconciliation report (v1.5.0). */
export interface ReconciliationDiscrepancy {
  kind: string;
  claim_id: string;
  claim_reference: string;
  detail: string;
}

/** One generated reconciliation report (v1.5.0). */
export interface ReconciliationReport {
  id: string;
  organization_id: string;
  period_start: string;
  period_end: string;
  totals: {
    claims_count: number;
    payments_count: number;
    requested_amount: string;
    approved_amount: string;
    paid_amount: string;
    outstanding_amount: string;
    discrepancies_count: number;
  };
  discrepancies: ReconciliationDiscrepancy[];
  generated_by: string | null;
  generated_at: string;
  created_at: string;
}

/** An average in days with its sample size (v1.5.1). A null days value means there was nothing to average. */
export interface AnalyticsAverage {
  days: number | null;
  sample_size: number;
}

/** Rates for a set of claims (v1.5.1). A null rate means its denominator was zero. */
export interface AnalyticsRates {
  recovery_rate_vs_requested: number | null;
  recovery_rate_vs_approved: number | null;
  rejection_rate: number | null;
}

/** Money totals as recorded, with no currency conversion (v1.5.1). */
export interface AnalyticsMoney {
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
}

/** Totals and rates for one window, for the period comparison (v1.5.1). */
export interface AnalyticsWindow {
  claims: number;
  submitted: number;
  paid: number;
  rejected: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
  recovery_rate_vs_requested: number | null;
  recovery_rate_vs_approved: number | null;
  rejection_rate: number | null;
}

/** The requested range against the preceding equal-length window (v1.5.1). Rate changes are in percentage points. */
export interface AnalyticsComparison {
  from: string;
  to: string;
  previous_from: string;
  previous_to: string;
  current: AnalyticsWindow;
  previous: AnalyticsWindow;
  change: {
    claims: number;
    submitted: number;
    paid: number;
    rejected: number;
    requested_amount: string;
    paid_amount: string;
    recovery_rate_vs_requested_pp: number | null;
    rejection_rate_pp: number | null;
  };
}

/** The analytics overview from GET /analytics/overview (v1.5.1). */
export interface AnalyticsOverview {
  organization_id: string;
  from: string;
  to: string;
  currencies: string[];
  counts: {
    claims: number;
    submitted: number;
    paid: number;
    rejected: number;
    accepted: number;
  };
  rates: AnalyticsRates;
  money: AnalyticsMoney;
  money_by_currency: (AnalyticsMoney & { currency: string })[];
  average_submission_days: AnalyticsAverage;
  average_payment_days: AnalyticsAverage;
  comparison: AnalyticsComparison;
}

/** One row of group performance (v1.5.1). */
export interface AnalyticsPerformanceRow {
  claims: number;
  submitted: number;
  paid: number;
  rejected: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
  currencies: string[];
  recovery_rate_vs_requested: number | null;
  rejection_rate: number | null;
  average_payment_days: AnalyticsAverage;
}

/** One broker row from GET /analytics/brokers (v1.5.1). */
export interface AnalyticsBrokerRow extends AnalyticsPerformanceRow {
  broker: string;
}

/** One carrier row from GET /analytics/carriers (v1.5.1). */
export interface AnalyticsCarrierRow extends AnalyticsPerformanceRow {
  carrier_id: string | null;
  carrier: string;
}

/** One event-type row from GET /analytics/event-types (v1.5.1). */
export interface AnalyticsEventTypeRow extends AnalyticsPerformanceRow {
  category: string;
  category_label: string;
}

/** One monthly series point from GET /analytics/monthly (v1.5.1). */
export interface AnalyticsMonthlyPoint {
  month: string;
  claims_created: number;
  submitted: number;
  paid: number;
  rejected: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  recovery_rate_vs_requested: number | null;
}

/** One yearly series point from GET /analytics/yearly (v1.5.1). */
export interface AnalyticsYearlyPoint {
  year: string;
  claims_created: number;
  submitted: number;
  paid: number;
  rejected: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  recovery_rate_vs_requested: number | null;
}

/** Export types for GET /analytics/export (v1.5.1). */
export type AnalyticsExportType =
  | "overview"
  | "brokers"
  | "carriers"
  | "event-types"
  | "monthly";

/** One monthly point of a detention trend (v1.5.2). Same shape as AnalyticsMonthlyPoint. */
export interface OperationsMonthlyPoint {
  month: string;
  claims_created: number;
  submitted: number;
  paid: number;
  rejected: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  recovery_rate_vs_requested: number | null;
}

/** Detention trends from GET /operations/detention-trends (v1.5.2). Detention claims only. */
export interface OperationsDetentionTrends {
  organization_id: string;
  from: string;
  to: string;
  currencies: string[];
  counts: {
    claims: number;
    submitted: number;
    paid: number;
    rejected: number;
    accepted: number;
  };
  rates: AnalyticsRates;
  money: AnalyticsMoney;
  money_by_currency: (AnalyticsMoney & { currency: string })[];
  average_submission_days: AnalyticsAverage;
  average_payment_days: AnalyticsAverage;
  monthly: OperationsMonthlyPoint[];
  comparison: AnalyticsComparison;
}

/** One facility row from GET /operations/facilities (v1.5.2). */
export interface OperationsFacilityRow extends AnalyticsPerformanceRow {
  facility: string;
}

/** One rejection-reason row from GET /operations/rejection-reasons (v1.5.2). */
export interface OperationsRejectionReasonRow {
  reason: string;
  rejected: number;
  share_of_rejected: number | null;
  requested_amount: string;
  currencies: string[];
}

/** Average readiness as a percentage with its sample size (v1.5.2). */
export interface OperationsReadinessAverage {
  percentage: number | null;
  sample_size: number;
}

/** One missing-evidence category row (v1.5.2). */
export interface OperationsMissingCategoryRow {
  category: string;
  category_label: string;
  claims_missing: number;
  share_of_incomplete: number | null;
}

/** One monthly point of the missing-evidence trend (v1.5.2). */
export interface OperationsMissingMonthlyPoint {
  month: string;
  claims_created: number;
  claims_with_requirements: number;
  incomplete: number;
  average_readiness_percentage: OperationsReadinessAverage;
}

/** Missing-evidence trends from GET /operations/missing-evidence (v1.5.2). */
export interface OperationsMissingEvidence {
  organization_id: string;
  from: string;
  to: string;
  claims: number;
  claims_with_requirements: number;
  claims_incomplete: number;
  average_readiness_percentage: OperationsReadinessAverage;
  by_category: OperationsMissingCategoryRow[];
  monthly: OperationsMissingMonthlyPoint[];
}

/** One broker-trend row from GET /operations/broker-trends (v1.5.2). */
export interface OperationsBrokerTrendRow extends AnalyticsPerformanceRow {
  broker: string;
  monthly: OperationsMonthlyPoint[];
  change: AnalyticsComparison["change"];
}

/** One balance-status row of the revenue leakage (v1.5.2). */
export interface OperationsLeakageStatusRow {
  balance_status: string;
  claims: number;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
}

/** One of the largest outstanding balances (v1.5.2). */
export interface OperationsLargestOutstanding {
  claim_id: string;
  claim_reference: string;
  broker: string;
  requested_amount: string;
  approved_amount: string | null;
  paid_amount: string;
  outstanding_amount: string;
}

/** One per-currency leakage row (v1.5.2). */
export interface OperationsLeakageCurrencyRow {
  currency: string;
  requested_amount: string;
  approved_amount: string;
  paid_amount: string;
  outstanding_amount: string;
  requested_vs_approved_gap: string;
}

/** Revenue leakage from GET /operations/revenue-leakage (v1.5.2). Gaps between recorded amounts only. */
export interface OperationsRevenueLeakage {
  organization_id: string;
  from: string;
  to: string;
  currencies: string[];
  submitted_claims: number;
  requested_total: string;
  approved_total: string;
  paid_total: string;
  outstanding_total: string;
  requested_vs_approved_gap: string;
  claims_cut_at_approval: number;
  overpaid_total: string;
  legacy_total: string;
  unsubmitted_claims: number;
  unsubmitted_requested_total: string;
  by_balance_status: OperationsLeakageStatusRow[];
  money_by_currency: OperationsLeakageCurrencyRow[];
  largest_outstanding: OperationsLargestOutstanding[];
}

/** One operational recommendation (v1.5.2). Evidence carries the numbers the rule was derived from. */
export interface OperationsRecommendation {
  type: string;
  title: string;
  detail: string;
  severity: string;
  evidence: Record<string, unknown>;
}

/** Recommendations from GET /operations/recommendations (v1.5.2). */
export interface OperationsRecommendations {
  organization_id: string;
  from: string;
  to: string;
  generated_from: string;
  recommendations: OperationsRecommendation[];
}

/** One condition of a workflow (v2.0.0). All conditions must match (AND) for the workflow to run. */
export interface WorkflowCondition {
  field: string;
  operator: string;
  value: string | number | boolean | string[] | null;
}

/** One step of a workflow definition (v2.0.0). Parameters depend on the step type. */
export interface WorkflowStep {
  type: string;
  message?: string;
  role?: string;
  permission?: string;
  to_status?: string;
  accepted_amount?: number;
  rejection_reason?: string;
  reason?: string;
  submitted_at?: string;
}

/** A workflow from GET /workflows (v2.0.0). */
export interface Workflow {
  id: string;
  name: string;
  description: string | null;
  trigger: string;
  trigger_label: string;
  is_active: boolean;
  conditions: WorkflowCondition[];
  steps: WorkflowStep[];
  deadline_days_before: number | null;
  created_by: string | null;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
}

/** One step of a workflow run (v2.0.0). */
export interface WorkflowRunStep {
  id: string;
  step_index: number;
  step_type: string;
  step_label: string;
  status: string;
  status_label: string;
  attempts: number;
  result: Record<string, unknown> | null;
  error: string | null;
  started_at: string | null;
  finished_at: string | null;
}

/** One approval checkpoint decision on a workflow run (v2.0.0). */
export interface WorkflowApproval {
  id: string;
  step_index: number;
  role: string;
  status: string;
  decided_by: string | null;
  note: string | null;
  decided_at: string | null;
  created_at: string;
}

/** One workflow run from GET /workflows/runs (v2.0.0). */
export interface WorkflowRun {
  id: string;
  workflow_id: string;
  workflow_name: string | null;
  claim_id: string;
  claim_reference: string | null;
  trigger: string;
  trigger_label: string;
  status: string;
  status_label: string;
  actor_user_id: string | null;
  current_step_index: number;
  failure_reason: string | null;
  context: Record<string, unknown> | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  steps: WorkflowRunStep[];
  approvals: WorkflowApproval[];
}

/** A dry-run evaluation from POST /workflows/{id}/test (v2.0.0). */
export interface WorkflowEvaluation {
  workflow_id: string;
  claim_id: string;
  trigger: string;
  conditions_match: boolean;
  would_run: boolean;
  steps: { index: number; type: string; label: string }[];
}

/** One extracted field from GET /documents (v2.0.1). */
export interface DocumentExtractionField {
  id: string;
  extraction_id: string;
  field_name: string;
  field_label: string;
  field_value: string | null;
  confidence: number;
  verified_value: string | null;
  verification_status: string;
  verification_status_label: string;
  verified_by: string | null;
  verified_at: string | null;
  verification_note: string | null;
}

/** One reference candidate from a document (v2.0.1). */
export interface DocumentMatchCandidate {
  value: string;
  source: string;
  matched_shipment_id: string | null;
  matched_field: string | null;
}

/** One processed document from GET /documents (v2.0.1). */
export interface DocumentExtraction {
  id: string;
  evidence_file_id: string | null;
  original_filename: string;
  mime_type: string | null;
  file_size: number | null;
  content_sha256: string | null;
  provider: string;
  classification: string | null;
  classification_label: string | null;
  classification_confidence: number | null;
  status: string;
  status_label: string;
  failure_reason: string | null;
  matched_shipment_id: string | null;
  matched_shipment_reference: string | null;
  match_confidence: number | null;
  match_method: string;
  match_candidates: DocumentMatchCandidate[];
  match_dismissed: boolean;
  is_duplicate: boolean;
  duplicate_of_id: string | null;
  duplicate_of_filename: string | null;
  near_duplicate_of_id: string | null;
  near_duplicate_reason: string | null;
  extracted_text: string | null;
  processed_at: string | null;
  verified_at: string | null;
  verified_by: string | null;
  created_at: string;
  updated_at: string;
  fields: DocumentExtractionField[];
}

/** One agreement bucket from GET /documents/accuracy (v2.0.1). */
export interface DocumentAccuracyBucket {
  decided: number;
  agreed: number;
  accuracy: number | null;
}

/** Measured extraction accuracy from GET /documents/accuracy (v2.0.1). */
export interface DocumentAccuracy {
  organization_id: string;
  overall: DocumentAccuracyBucket;
  by_field: Record<string, DocumentAccuracyBucket>;
  by_classification: Record<string, DocumentAccuracyBucket>;
  counts: {
    total_extractions: number;
    needs_verification: number;
    verified_extractions: number;
    duplicates: number;
    decided_fields: number;
  };
}

/** One public API key from GET /api-keys (v2.0.2). The full key is never returned: only the prefix. */
export interface ApiKey {
  id: string;
  organization_id: string;
  name: string;
  kind: "standard" | "broker";
  key_prefix: string;
  scopes: string[];
  status: "active" | "rotating" | "revoked";
  broker_name: string | null;
  grace_ends_at: string | null;
  last_used_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** The response to creating or rotating a key: the full key, shown exactly once (v2.0.2). */
export interface ApiKeyCreated {
  key: ApiKey;
  plain_text_key: string;
}

/** One webhook endpoint from GET /webhooks (v2.0.2). The URL and secret are never returned: only the host. */
export interface WebhookEndpoint {
  id: string;
  organization_id: string;
  name: string;
  url_host: string | null;
  events: string[];
  status: "active" | "disabled";
  last_delivered_at: string | null;
  last_error: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** The response to creating a webhook endpoint: the signing secret, shown exactly once (v2.0.2). */
export interface WebhookEndpointCreated {
  endpoint: WebhookEndpoint;
  plain_text_secret: string;
}

/** One webhook delivery from GET /webhooks/{id}/deliveries (v2.0.2). */
export interface WebhookDelivery {
  id: string;
  webhook_endpoint_id: string;
  organization_id: string;
  event_type: string;
  status: "pending" | "delivered" | "failed";
  attempts: number;
  http_status: number | null;
  response_body: string | null;
  next_retry_at: string | null;
  delivered_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** One modelled accounting provider from GET /accounting/providers (v2.0.2). Mock only: live_available is always false. */
export interface AccountingProviderOption {
  value: string;
  label: string;
  live_available: boolean;
}

/** One accounting connection from GET /accounting (v2.0.2). Mock only: live_available is always false. */
export interface AccountingConnection {
  id: string;
  organization_id: string;
  provider: string;
  provider_label: string;
  name: string;
  status: "connected" | "disconnected";
  company_name: string | null;
  live_available: boolean;
  last_sync_summary: AccountingSyncSummary | null;
  last_synced_at: string | null;
  last_error: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** The dummy sync summary from POST /accounting/{id}/sync (v2.0.2). No money moves. */
export interface AccountingSyncSummary {
  exported: number;
  skipped: number;
  records: {
    external_id: string;
    claim_payment_id: string;
    claim_id: string;
    amount: string;
    currency: string;
    payment_method: string | null;
    reference: string | null;
    recorded_at: string | null;
  }[];
}

/* ------------------------------------------------------------------ */
/* Enterprise readiness (v2.0.3)                                       */
/* ------------------------------------------------------------------ */

/**
 * Enterprise security policy from GET /security/policies.
 * Password rules are enforced on password change; IP ranges are
 * check-only (not enforced at the edge); two-factor is recorded
 * policy only, no 2FA exists yet.
 */
export interface SecurityPolicy {
  password_min_length: number;
  password_require_numbers: boolean;
  password_require_symbols: boolean;
  session_timeout_minutes: number;
  max_concurrent_sessions: number | null;
  allowed_ip_ranges: string[];
  require_two_factor: boolean;
  is_default: boolean;
}

/** Result of POST /security/policies/check-password. */
export interface PasswordCheckResult {
  valid: boolean;
  errors: string[];
}

/** Result of POST /security/policies/check-ip. */
export interface IpCheckResult {
  ip: string;
  allowed: boolean;
  in_range: boolean | null;
  ranges: string[];
}

/** Retention kinds (v2.0.3). Days: 0 keeps forever. */
export type RetentionKind =
  | "audit_logs"
  | "notifications"
  | "job_executions"
  | "failed_documents"
  | "security_events";

/** Retention days per kind. */
export type RetentionDaysByKind = Record<RetentionKind, number>;

/** One retention preview row: how many rows are older than the cutoff. */
export interface RetentionPreviewRow {
  count: number;
  cutoff: string | null;
}

/** Retention preview from GET /retention/preview. */
export interface RetentionPreview {
  effective: RetentionDaysByKind;
  defaults: RetentionDaysByKind;
  overrides: Partial<RetentionDaysByKind>;
  preview: Record<RetentionKind, RetentionPreviewRow>;
  generated_at: string;
}

/** Result of POST /retention/purge. */
export interface RetentionPurgeResult {
  dry_run: boolean;
  counts: Record<string, number>;
}

/** Disaster recovery drill kinds (v2.0.3). */
export type DisasterRecoveryDrillKind = "verify" | "restore_dry_run";

/** Disaster recovery drill statuses (v2.0.3). */
export type DisasterRecoveryDrillStatus = "completed" | "failed";

/** One disaster recovery drill from GET /enterprise/disaster-recovery/drills. */
export interface DisasterRecoveryDrill {
  id: string;
  kind: DisasterRecoveryDrillKind;
  status: DisasterRecoveryDrillStatus;
  result: Record<string, unknown>;
  performed_by: { id: number; name: string } | null;
  performed_at: string;
  created_at: string;
}

/** Disaster recovery status from GET /enterprise/disaster-recovery. */
export interface DisasterRecoveryStatus {
  rpo_minutes: number;
  rto_minutes: number;
  latest_backup: {
    id: string;
    status: string;
    created_at: string;
    checksum: string | null;
  } | null;
  last_verified_backup: { id: string; verified_at: string; ok: boolean } | null;
  latest_drill: DisasterRecoveryDrill | null;
  total_drills: number;
}

/** Load test targets (v2.0.3). */
export type LoadTestTarget = "health" | "shipments";

/** Load test run statuses (v2.0.3). */
export type LoadTestStatus = "completed" | "failed";

/** One load test run from GET /enterprise/load-tests. */
export interface LoadTestRun {
  id: string;
  name: string | null;
  target: LoadTestTarget;
  requests: number;
  status: LoadTestStatus;
  total_requests: number;
  success_count: number;
  error_count: number;
  avg_ms: number;
  p95_ms: number;
  max_ms: number;
  requests_per_second: number;
  detail: Record<string, unknown> | null;
  started_at: string;
  finished_at: string | null;
  created_at: string;
}

/** Alert rule metrics (v2.0.3). */
export type AlertMetric =
  | "queue_depth"
  | "failed_jobs"
  | "error_rate"
  | "disk_usage_percent"
  | "backup_age_hours";

/** Alert rule operators (v2.0.3). */
export type AlertOperator = "gt" | "gte";

/** Alert severities (v2.0.3). */
export type AlertSeverity = "warning" | "critical";

/** One alert rule from GET /enterprise/alert-rules. */
export interface AlertRule {
  id: string;
  name: string;
  metric: AlertMetric;
  operator: AlertOperator;
  threshold: number;
  window_minutes: number;
  enabled: boolean;
  severity: AlertSeverity;
  created_at: string;
  updated_at: string;
}

/** Alert statuses (v2.0.3). */
export type EnterpriseAlertStatus = "active" | "acknowledged" | "resolved";

/** One enterprise alert from GET /enterprise/alerts. */
export interface EnterpriseAlert {
  id: string;
  alert_rule_id: string | null;
  metric: string;
  observed_value: number;
  threshold: number;
  severity: AlertSeverity;
  status: EnterpriseAlertStatus;
  message: string;
  triggered_at: string;
  acknowledged_at: string | null;
  resolved_at: string | null;
  created_at: string;
}

/** Result of POST /enterprise/alert-rules/{id}/evaluate. */
export interface AlertEvaluation {
  rule_id: string;
  metric: string;
  observed_value: number | null;
  breached: boolean;
  alert: EnterpriseAlert | null;
}

/** SLO keys (v2.0.3). */
export type SloKey = "availability" | "error_rate" | "latency_p95";

/** SLO kinds (v2.0.3). Note the latency SLO's key is latency_p95 but its kind is latency. */
export type SloKind = "availability" | "error_rate" | "latency";

/** SLO statuses (v2.0.3). */
export type SloStatusValue =
  | "meeting"
  | "at_risk"
  | "breaching"
  | "insufficient_data";

/** One SLO status row from GET /enterprise/slos. */
export interface SloStatus {
  key: SloKey;
  name: string;
  kind: SloKind;
  target_value: number;
  target_unit: "%" | "ms";
  window_days: number;
  enabled: boolean;
  measured_value: number | null;
  sample_size: number;
  status: SloStatusValue;
  measured_at: string;
}

/** Assessment finding statuses (v2.0.3). */
export type AssessmentFindingStatus = "pass" | "warn" | "fail" | "open";

/** One finding inside a security assessment (v2.0.3). */
export interface AssessmentFinding {
  id: string;
  label: string;
  status: AssessmentFindingStatus;
  detail: string;
  category: string;
}

/** Assessment overall results (v2.0.3). */
export type AssessmentOverall = "pass" | "warn" | "fail";

/** One security assessment from GET /enterprise/assessments. */
export interface SecurityAssessment {
  id: string;
  overall: AssessmentOverall;
  open_findings: number;
  total_checks: number;
  passed: number;
  warnings: number;
  failures: number;
  findings: AssessmentFinding[];
  generated_by: { id: number; name: string } | null;
  generated_at: string;
  created_at: string;
}

