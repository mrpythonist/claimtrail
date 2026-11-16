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
  | "organization.invitation";

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
  created_at: string;
  updated_at: string;
}

export interface EventCategory {
  id: string;
  slug: string;
  label: string;
  description: string | null;
  is_system: boolean;
}

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
  created_at: string;
  updated_at: string;
}

export interface ImportPreviewRow {
  index: number;
  data: Record<string, string | number | null>;
  errors: Record<string, string[]>;
  is_duplicate: boolean;
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
  skipped_rows: { index: number; reason: string }[];
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
