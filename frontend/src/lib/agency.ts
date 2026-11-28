import { apiFetch } from "./api";
import type {
  AgencyCarrierClaim,
  AgencyCarrierGrant,
  AgencyCarrierGrantScope,
  AgencyCarrierStats,
  AgencyCommissions,
  AgencyDashboard,
  CommissionRule,
  ConsolidatedReport,
  PaginatedMeta,
  PartnerLink,
  PartnerLinkStatus,
  ReferralCode,
  ReferralCodeDetail,
} from "./types";

/**
 * Typed client for the agency management surface (v2.1.0):
 * partner links, carrier management, the agency dashboard,
 * consolidated reporting, referral codes, commission rules
 * and computed earnings, and reseller workflows.
 *
 * Every call is tenant-scoped under /api/v1/agency and needs
 * the manage-agency permission and the Agency plan: the UI
 * gates on canManageAgency and still handles 403 and 402
 * responses gracefully. Carrier claims are read-only from the
 * agency side; writing a claim stays with the carrier
 * organization. Commission is computed only from recorded
 * payments; nothing here pays a partner out. Money is summed
 * per currency, never converted.
 */

export const PARTNER_LINK_STATUSES: { value: PartnerLinkStatus; label: string }[] = [
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "suspended", label: "Suspended" },
  { value: "rejected", label: "Rejected" },
  { value: "removed", label: "Removed" },
];

export const GRANT_SCOPES: { value: AgencyCarrierGrantScope; label: string }[] = [
  { value: "view", label: "View" },
  { value: "manage", label: "Manage" },
];

export async function getAgencyDashboard(): Promise<AgencyDashboard> {
  const body = await apiFetch<{ data: AgencyDashboard }>("/agency/dashboard");
  return body.data;
}

export async function listAgencyCarriers(): Promise<AgencyCarrierStats[]> {
  const body = await apiFetch<{ data: AgencyCarrierStats[] }>("/agency/carriers");
  return body.data;
}

export async function listPartnerLinks(
  status?: PartnerLinkStatus,
): Promise<{ data: PartnerLink[]; meta: PaginatedMeta }> {
  const query = status ? `?status=${encodeURIComponent(status)}` : "";
  return apiFetch<{ data: PartnerLink[]; meta: PaginatedMeta }>(
    `/agency/partners${query}`,
  );
}

export async function createPartnerLink(input: {
  carrier_organization_id: string;
  referral_code?: string;
  notes?: string;
}): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>("/agency/partners", {
    method: "POST",
    body: input,
  });
  return body.data;
}

export async function getPartnerLink(id: string): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>(`/agency/partners/${id}`);
  return body.data;
}

export async function suspendPartnerLink(id: string): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>(
    `/agency/partners/${id}/suspend`,
    { method: "POST" },
  );
  return body.data;
}

export async function reactivatePartnerLink(id: string): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>(
    `/agency/partners/${id}/reactivate`,
    { method: "POST" },
  );
  return body.data;
}

export async function removePartnerLink(id: string): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>(
    `/agency/partners/${id}/remove`,
    { method: "POST" },
  );
  return body.data;
}

export async function assignPartnerPlan(id: string, plan: string): Promise<PartnerLink> {
  const body = await apiFetch<{ data: PartnerLink }>(
    `/agency/partners/${id}/plan`,
    { method: "POST", body: { plan } },
  );
  return body.data;
}

export async function listCarrierClaims(
  carrierOrganizationId: string,
  status?: string,
): Promise<{ data: AgencyCarrierClaim[]; meta: PaginatedMeta }> {
  const query = status ? `?status=${encodeURIComponent(status)}` : "";
  return apiFetch<{ data: AgencyCarrierClaim[]; meta: PaginatedMeta }>(
    `/agency/carriers/${carrierOrganizationId}/claims${query}`,
  );
}

export async function getCarrierClaim(
  carrierOrganizationId: string,
  claimId: string,
): Promise<AgencyCarrierClaim> {
  const body = await apiFetch<{ data: AgencyCarrierClaim }>(
    `/agency/carriers/${carrierOrganizationId}/claims/${claimId}`,
  );
  return body.data;
}

export async function getConsolidatedReport(input?: {
  date_from?: string;
  date_to?: string;
  carrier_organization_id?: string;
}): Promise<ConsolidatedReport> {
  const params = new URLSearchParams();
  if (input?.date_from) params.set("date_from", input.date_from);
  if (input?.date_to) params.set("date_to", input.date_to);
  if (input?.carrier_organization_id) {
    params.set("carrier_organization_id", input.carrier_organization_id);
  }
  const query = params.toString() ? `?${params.toString()}` : "";
  const body = await apiFetch<{ data: ConsolidatedReport }>(
    `/agency/reports/consolidated${query}`,
  );
  return body.data;
}

export async function listReferralCodes(): Promise<{
  data: ReferralCode[];
  meta: PaginatedMeta;
}> {
  return apiFetch<{ data: ReferralCode[]; meta: PaginatedMeta }>(
    "/agency/referrals",
  );
}

export async function createReferralCode(input?: {
  notes?: string;
}): Promise<ReferralCode> {
  const body = await apiFetch<{ data: ReferralCode }>("/agency/referrals", {
    method: "POST",
    body: input ?? {},
  });
  return body.data;
}

export async function getReferralCode(id: string): Promise<ReferralCodeDetail> {
  const body = await apiFetch<{ data: ReferralCodeDetail }>(
    `/agency/referrals/${id}`,
  );
  return body.data;
}

export async function deactivateReferralCode(id: string): Promise<ReferralCode> {
  const body = await apiFetch<{ data: ReferralCode }>(
    `/agency/referrals/${id}/deactivate`,
    { method: "POST" },
  );
  return body.data;
}

export async function listCommissionRules(): Promise<CommissionRule[]> {
  const body = await apiFetch<{ data: CommissionRule[] }>(
    "/agency/commission-rules",
  );
  return body.data;
}

export async function createCommissionRule(input: {
  partner_link_id?: string | null;
  rate_percent: number;
  cap_cents?: number | null;
  effective_from?: string | null;
}): Promise<CommissionRule> {
  const body = await apiFetch<{ data: CommissionRule }>(
    "/agency/commission-rules",
    { method: "POST", body: input },
  );
  return body.data;
}

export async function updateCommissionRule(
  id: string,
  input: {
    rate_percent?: number;
    cap_cents?: number | null;
    active?: boolean;
    effective_from?: string | null;
  },
): Promise<CommissionRule> {
  const body = await apiFetch<{ data: CommissionRule }>(
    `/agency/commission-rules/${id}`,
    { method: "PUT", body: input },
  );
  return body.data;
}

export async function deleteCommissionRule(id: string): Promise<void> {
  await apiFetch(`/agency/commission-rules/${id}`, { method: "DELETE" });
}

export async function getCommissions(input?: {
  date_from?: string;
  date_to?: string;
}): Promise<AgencyCommissions> {
  const params = new URLSearchParams();
  if (input?.date_from) params.set("date_from", input.date_from);
  if (input?.date_to) params.set("date_to", input.date_to);
  const query = params.toString() ? `?${params.toString()}` : "";
  const body = await apiFetch<{ data: AgencyCommissions }>(
    `/agency/commissions${query}`,
  );
  return body.data;
}

export async function listGrants(
  partnerLinkId: string,
): Promise<AgencyCarrierGrant[]> {
  const body = await apiFetch<{ data: AgencyCarrierGrant[] }>(
    `/agency/partners/${partnerLinkId}/grants`,
  );
  return body.data;
}

export async function createGrant(
  partnerLinkId: string,
  input: { user_id: number; scope: AgencyCarrierGrantScope },
): Promise<AgencyCarrierGrant> {
  const body = await apiFetch<{ data: AgencyCarrierGrant }>(
    `/agency/partners/${partnerLinkId}/grants`,
    { method: "POST", body: input },
  );
  return body.data;
}

export async function deleteGrant(
  partnerLinkId: string,
  grantId: string,
): Promise<void> {
  await apiFetch(`/agency/partners/${partnerLinkId}/grants/${grantId}`, {
    method: "DELETE",
  });
}
