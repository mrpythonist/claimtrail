"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  GRANT_SCOPES,
  PARTNER_LINK_STATUSES,
  assignPartnerPlan,
  createCommissionRule,
  createGrant,
  createPartnerLink,
  createReferralCode,
  deactivateReferralCode,
  deleteCommissionRule,
  deleteGrant,
  getAgencyDashboard,
  getCarrierClaim,
  getCommissions,
  getConsolidatedReport,
  getPartnerLink,
  getReferralCode,
  listAgencyCarriers,
  listCommissionRules,
  listCarrierClaims,
  listGrants,
  listPartnerLinks,
  listReferralCodes,
  reactivatePartnerLink,
  removePartnerLink,
  suspendPartnerLink,
  updateCommissionRule,
} from "@/lib/agency";
import { canManageAgency } from "@/lib/permissions";
import type {
  AgencyCarrierClaim,
  AgencyCarrierGrant,
  AgencyCarrierGrantScope,
  AgencyCarrierStats,
  AgencyCommissions,
  AgencyDashboard,
  CommissionRule,
  ConsolidatedReport,
  Organization,
  PaginatedMeta,
  PartnerLink,
  PartnerLinkStatus,
  ReferralCode,
  ReferralCodeDetail,
} from "@/lib/types";

/**
 * Agency management for one organization (v2.1.0): partner
 * links, carrier management, the agency dashboard, consolidated
 * reporting, referral codes, commission rules and computed
 * earnings, and reseller workflows.
 *
 * Everything here needs the manage-agency permission and the
 * Agency plan; the UI gates on canManageAgency and still
 * handles 403 and 402 responses gracefully. Honest limits,
 * stated where they apply: carrier claims are read-only from
 * the agency side (writing a claim stays with the carrier
 * organization); commission is computed only from recorded
 * payments and nothing here pays a partner out; money is
 * summed per currency, never converted; a partner link only
 * becomes active when the carrier organization approves it,
 * so an agency cannot claim a carrier unilaterally.
 */

type Tab =
  | "overview"
  | "carriers"
  | "claims"
  | "reports"
  | "referrals"
  | "commissions"
  | "reseller";

const TABS: { value: Tab; label: string }[] = [
  { value: "overview", label: "Overview" },
  { value: "carriers", label: "Carriers" },
  { value: "claims", label: "Claims" },
  { value: "reports", label: "Reports" },
  { value: "referrals", label: "Referrals" },
  { value: "commissions", label: "Commissions" },
  { value: "reseller", label: "Reseller" },
];

const EMPTY_META: PaginatedMeta = { current_page: 1, last_page: 1, per_page: 15, total: 0 };

function errorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiRequestError ? err.message : fallback;
}

function isPlanError(err: unknown): boolean {
  return err instanceof ApiRequestError && err.status === 402;
}

function isForbidden(err: unknown): boolean {
  return err instanceof ApiRequestError && err.status === 403;
}

function formatDate(value: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleString();
}

function linkTone(status: PartnerLinkStatus): "success" | "warning" | "danger" | "info" {
  switch (status) {
    case "approved":
      return "success";
    case "pending":
      return "warning";
    case "suspended":
    case "rejected":
    case "removed":
      return "danger";
    default:
      return "info";
  }
}

function AgencyContent({ org }: { org: Organization }) {
  const [tab, setTab] = useState<Tab>("overview");
  const canManage = canManageAgency(org.current_user_role);
  const [planBlocked, setPlanBlocked] = useState(false);
  const [forbidden, setForbidden] = useState(false);

  // Overview
  const [dashboardLoading, setDashboardLoading] = useState(true);
  const [dashboardError, setDashboardError] = useState<string | null>(null);
  const [dashboard, setDashboard] = useState<AgencyDashboard | null>(null);

  // Carriers
  const [linksLoading, setLinksLoading] = useState(true);
  const [linksError, setLinksError] = useState<string | null>(null);
  const [links, setLinks] = useState<PartnerLink[]>([]);
  const [linksMeta, setLinksMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [linkStatusFilter, setLinkStatusFilter] = useState<string>("");
  const [newCarrierId, setNewCarrierId] = useState("");
  const [newReferralCode, setNewReferralCode] = useState("");
  const [newLinkNotes, setNewLinkNotes] = useState("");
  const [linkBusy, setLinkBusy] = useState(false);
  const [linkNote, setLinkNote] = useState<string | null>(null);
  const [linkFormError, setLinkFormError] = useState<string | null>(null);

  // Claims
  const [carriers, setCarriers] = useState<AgencyCarrierStats[]>([]);
  const [selectedCarrierId, setSelectedCarrierId] = useState("");
  const [claimsLoading, setClaimsLoading] = useState(false);
  const [claimsError, setClaimsError] = useState<string | null>(null);
  const [claims, setClaims] = useState<AgencyCarrierClaim[]>([]);
  const [claimsMeta, setClaimsMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [selectedClaim, setSelectedClaim] = useState<AgencyCarrierClaim | null>(null);

  // Reports
  const [reportLoading, setReportLoading] = useState(true);
  const [reportError, setReportError] = useState<string | null>(null);
  const [report, setReport] = useState<ConsolidatedReport | null>(null);
  const [reportDateFrom, setReportDateFrom] = useState("");
  const [reportDateTo, setReportDateTo] = useState("");
  const [reportCarrierId, setReportCarrierId] = useState("");

  // Referrals
  const [codesLoading, setCodesLoading] = useState(true);
  const [codesError, setCodesError] = useState<string | null>(null);
  const [codes, setCodes] = useState<ReferralCode[]>([]);
  const [codesMeta, setCodesMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [codeNotes, setCodeNotes] = useState("");
  const [codeBusy, setCodeBusy] = useState(false);
  const [codeNote, setCodeNote] = useState<string | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [selectedCode, setSelectedCode] = useState<ReferralCodeDetail | null>(null);

  // Commissions
  const [commissionsLoading, setCommissionsLoading] = useState(true);
  const [commissionsError, setCommissionsError] = useState<string | null>(null);
  const [commissions, setCommissions] = useState<AgencyCommissions | null>(null);
  const [rules, setRules] = useState<CommissionRule[]>([]);
  const [ruleLinkId, setRuleLinkId] = useState("");
  const [ruleRate, setRuleRate] = useState("10");
  const [ruleCap, setRuleCap] = useState("");
  const [ruleBusy, setRuleBusy] = useState(false);
  const [ruleNote, setRuleNote] = useState<string | null>(null);
  const [ruleError, setRuleError] = useState<string | null>(null);

  // Reseller
  const [planLinkId, setPlanLinkId] = useState("");
  const [planKey, setPlanKey] = useState("starter");
  const [planBusy, setPlanBusy] = useState(false);
  const [planNote, setPlanNote] = useState<string | null>(null);
  const [planError, setPlanError] = useState<string | null>(null);
  const [grantLinkId, setGrantLinkId] = useState("");
  const [grantUserId, setGrantUserId] = useState("");
  const [grantScope, setGrantScope] = useState<AgencyCarrierGrantScope>("view");
  const [grantBusy, setGrantBusy] = useState(false);
  const [grantNote, setGrantNote] = useState<string | null>(null);
  const [grantError, setGrantError] = useState<string | null>(null);
  const [grants, setGrants] = useState<AgencyCarrierGrant[]>([]);

  async function loadDashboard() {
    setDashboardLoading(true);
    setDashboardError(null);
    try {
      setDashboard(await getAgencyDashboard());
    } catch (err) {
      if (isPlanError(err)) setPlanBlocked(true);
      else if (isForbidden(err)) setForbidden(true);
      else setDashboardError(errorMessage(err, "Could not load the agency dashboard."));
    } finally {
      setDashboardLoading(false);
    }
  }

  async function loadLinks(status?: string) {
    setLinksLoading(true);
    setLinksError(null);
    try {
      const result = await listPartnerLinks(
        status ? (status as PartnerLinkStatus) : undefined,
      );
      setLinks(result.data);
      setLinksMeta(result.meta);
    } catch (err) {
      if (isPlanError(err)) setPlanBlocked(true);
      else if (isForbidden(err)) setForbidden(true);
      else setLinksError(errorMessage(err, "Could not load partner links."));
    } finally {
      setLinksLoading(false);
    }
  }

  async function loadCarriers() {
    try {
      const rows = await listAgencyCarriers();
      setCarriers(rows);
      if (!selectedCarrierId && rows.length > 0) {
        setSelectedCarrierId(rows[0].carrier.organization_id);
      }
    } catch {
      // The carriers list is a convenience for the claims tab;
      // the dashboard already surfaces the same data.
    }
  }

  async function loadClaims(carrierId: string) {
    if (!carrierId) return;
    setClaimsLoading(true);
    setClaimsError(null);
    setSelectedClaim(null);
    try {
      const result = await listCarrierClaims(carrierId);
      setClaims(result.data);
      setClaimsMeta(result.meta);
    } catch (err) {
      if (isForbidden(err)) setClaimsError("You do not have access to this carrier.");
      else setClaimsError(errorMessage(err, "Could not load carrier claims."));
    } finally {
      setClaimsLoading(false);
    }
  }

  async function loadReport() {
    setReportLoading(true);
    setReportError(null);
    try {
      setReport(
        await getConsolidatedReport({
          date_from: reportDateFrom || undefined,
          date_to: reportDateTo || undefined,
          carrier_organization_id: reportCarrierId || undefined,
        }),
      );
    } catch (err) {
      if (isPlanError(err)) setPlanBlocked(true);
      else if (isForbidden(err)) setForbidden(true);
      else setReportError(errorMessage(err, "Could not load the consolidated report."));
    } finally {
      setReportLoading(false);
    }
  }

  async function loadCodes() {
    setCodesLoading(true);
    setCodesError(null);
    try {
      const result = await listReferralCodes();
      setCodes(result.data);
      setCodesMeta(result.meta);
    } catch (err) {
      if (isPlanError(err)) setPlanBlocked(true);
      else if (isForbidden(err)) setForbidden(true);
      else setCodesError(errorMessage(err, "Could not load referral codes."));
    } finally {
      setCodesLoading(false);
    }
  }

  async function loadCommissions() {
    setCommissionsLoading(true);
    setCommissionsError(null);
    try {
      setCommissions(await getCommissions());
      setRules(await listCommissionRules());
    } catch (err) {
      if (isPlanError(err)) setPlanBlocked(true);
      else if (isForbidden(err)) setForbidden(true);
      else setCommissionsError(errorMessage(err, "Could not load commissions."));
    } finally {
      setCommissionsLoading(false);
    }
  }

  async function loadGrants(linkId: string) {
    if (!linkId) {
      setGrants([]);
      return;
    }
    try {
      setGrants(await listGrants(linkId));
    } catch {
      setGrants([]);
    }
  }

  useEffect(() => {
    loadDashboard();
    loadLinks();
    loadCarriers();
    loadReport();
    loadCodes();
    loadCommissions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    loadClaims(selectedCarrierId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedCarrierId]);

  async function handleCreateLink(event: FormEvent) {
    event.preventDefault();
    setLinkBusy(true);
    setLinkNote(null);
    setLinkFormError(null);
    try {
      const link = await createPartnerLink({
        carrier_organization_id: newCarrierId.trim(),
        referral_code: newReferralCode.trim() || undefined,
        notes: newLinkNotes.trim() || undefined,
      });
      setLinkNote(
        `Partner link created (pending). The carrier organization must approve it before it becomes active. Link ${link.id}.`,
      );
      setNewCarrierId("");
      setNewReferralCode("");
      setNewLinkNotes("");
      await loadLinks(linkStatusFilter || undefined);
      await loadDashboard();
    } catch (err) {
      setLinkFormError(errorMessage(err, "Could not create the partner link."));
    } finally {
      setLinkBusy(false);
    }
  }

  async function handleLinkAction(
    id: string,
    action: "suspend" | "reactivate" | "remove",
  ) {
    setLinkBusy(true);
    setLinkNote(null);
    setLinkFormError(null);
    try {
      if (action === "suspend") await suspendPartnerLink(id);
      if (action === "reactivate") await reactivatePartnerLink(id);
      if (action === "remove") await removePartnerLink(id);
      setLinkNote(`Link ${action === "remove" ? "removed" : action === "suspend" ? "suspended" : "reactivated"}.`);
      await loadLinks(linkStatusFilter || undefined);
      await loadDashboard();
    } catch (err) {
      setLinkFormError(errorMessage(err, "Could not update the partner link."));
    } finally {
      setLinkBusy(false);
    }
  }

  async function handleCreateCode(event: FormEvent) {
    event.preventDefault();
    setCodeBusy(true);
    setCodeNote(null);
    setCodeError(null);
    try {
      const code = await createReferralCode({ notes: codeNotes.trim() || undefined });
      setCodeNote(`Referral code ${code.code} created.`);
      setCodeNotes("");
      await loadCodes();
    } catch (err) {
      setCodeError(errorMessage(err, "Could not create the referral code."));
    } finally {
      setCodeBusy(false);
    }
  }

  async function handleCreateRule(event: FormEvent) {
    event.preventDefault();
    setRuleBusy(true);
    setRuleNote(null);
    setRuleError(null);
    try {
      await createCommissionRule({
        partner_link_id: ruleLinkId || null,
        rate_percent: Number(ruleRate),
        cap_cents: ruleCap ? Math.round(Number(ruleCap) * 100) : null,
      });
      setRuleNote("Commission rule created.");
      setRuleRate("10");
      setRuleCap("");
      setRuleLinkId("");
      await loadCommissions();
      await loadDashboard();
    } catch (err) {
      setRuleError(errorMessage(err, "Could not create the commission rule."));
    } finally {
      setRuleBusy(false);
    }
  }

  async function handleAssignPlan(event: FormEvent) {
    event.preventDefault();
    setPlanBusy(true);
    setPlanNote(null);
    setPlanError(null);
    try {
      const link = await assignPartnerPlan(planLinkId, planKey);
      setPlanNote(
        `Plan ${link.assigned_plan} assigned to ${link.carrier_organization_name ?? "the carrier"}. Billing runs on the mock provider; no real money moves.`,
      );
      await loadLinks(linkStatusFilter || undefined);
    } catch (err) {
      setPlanError(errorMessage(err, "Could not assign the plan."));
    } finally {
      setPlanBusy(false);
    }
  }

  async function handleCreateGrant(event: FormEvent) {
    event.preventDefault();
    setGrantBusy(true);
    setGrantNote(null);
    setGrantError(null);
    try {
      await createGrant(grantLinkId, {
        user_id: Number(grantUserId),
        scope: grantScope,
      });
      setGrantNote("Grant saved.");
      setGrantUserId("");
      await loadGrants(grantLinkId);
    } catch (err) {
      setGrantError(errorMessage(err, "Could not save the grant."));
    } finally {
      setGrantBusy(false);
    }
  }

  if (!canManage) {
    return (
      <Alert tone="info">
        Agency management needs the manage-agency permission (owner, admin, or operations manager).
      </Alert>
    );
  }

  if (planBlocked) {
    return (
      <Alert tone="warning">
        Agency management is included in the Agency plan ($149/month base plus $12 per extra carrier).
        Upgrade to use partner links, the agency dashboard, consolidated reporting, referrals,
        commissions, and reseller workflows.
      </Alert>
    );
  }

  if (forbidden) {
    return <Alert tone="warning">You do not have access to agency management.</Alert>;
  }

  const activeLinks = links.filter((link) => link.status === "approved");

  return (
    <div>
      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Agency sections">
        {TABS.map((item) => (
          <Button
            key={item.value}
            type="button"
            variant={tab === item.value ? "primary" : "secondary"}
            onClick={() => setTab(item.value)}
            aria-selected={tab === item.value}
            role="tab"
          >
            {item.label}
          </Button>
        ))}
      </div>

      {tab === "overview" && (
        <div className="space-y-4">
          {dashboardLoading ? (
            <Spinner label="Loading the agency dashboard…" />
          ) : dashboardError ? (
            <Alert tone="error">{dashboardError}</Alert>
          ) : dashboard ? (
            <>
              <div className="grid gap-4 md:grid-cols-3">
                <Card>
                  <CardHeader title="Partner links" />
                  <CardBody>
                    <p className="text-2xl font-semibold">{dashboard.link_counts.total}</p>
                    <p className="text-sm text-slate-500">
                      {dashboard.link_counts.approved} approved · {dashboard.link_counts.pending} pending ·{" "}
                      {dashboard.link_counts.suspended} suspended
                    </p>
                  </CardBody>
                </Card>
                <Card>
                  <CardHeader title="Referrals" />
                  <CardBody>
                    <p className="text-2xl font-semibold">{dashboard.referrals.carriers_attributed}</p>
                    <p className="text-sm text-slate-500">
                      carriers attributed · {dashboard.referrals.codes_active} active codes of{" "}
                      {dashboard.referrals.codes_total}
                    </p>
                  </CardBody>
                </Card>
                <Card>
                  <CardHeader title="Commission earned" />
                  <CardBody>
                    {dashboard.commission.totals_by_currency.length === 0 ? (
                      <p className="text-sm text-slate-500">No recorded payments yet.</p>
                    ) : (
                      dashboard.commission.totals_by_currency.map((total) => (
                        <p key={total.currency} className="text-sm">
                          {total.currency} {total.earned} earned on {total.currency} {total.payments} recorded
                        </p>
                      ))
                    )}
                    <p className="text-sm text-slate-500">
                      Computed from recorded payments only. Nothing here pays a partner out.
                    </p>
                  </CardBody>
                </Card>
              </div>

              <Card>
                <CardHeader title="Consolidated totals by currency" />
                <CardBody>
                  {dashboard.totals_by_currency.length === 0 ? (
                    <EmptyState
                      title="No carrier activity yet"
                      description="Approve a partner link and the linked carriers' recorded claims and payments show up here."
                    />
                  ) : (
                    <Table headers={["Currency", "Requested", "Accepted", "Recovered", "Payments", "Outstanding"]}>
                      {dashboard.totals_by_currency.map((total) => (
                        <tr key={total.currency} className="border-b border-slate-100 last:border-0">
                          <TableCell>{total.currency}</TableCell>
                          <TableCell>{total.requested}</TableCell>
                          <TableCell>{total.accepted}</TableCell>
                          <TableCell>{total.recovered}</TableCell>
                          <TableCell>{total.payments}</TableCell>
                          <TableCell>{total.outstanding}</TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                  <p className="mt-2 text-sm text-slate-500">
                    Money is summed per currency, never converted: there is no exchange rate anywhere in the app.
                  </p>
                </CardBody>
              </Card>

              <Card>
                <CardHeader title="Carriers" />
                <CardBody>
                  {dashboard.carriers.length === 0 ? (
                    <EmptyState
                      title="No active carriers"
                      description="Create a partner link on the Carriers tab; it becomes active once the carrier organization approves it."
                    />
                  ) : (
                    <Table headers={["Carrier", "Claims", "Requested", "Recovered", "Payments", "Commission"]}>
                      {dashboard.carriers.map((row) => (
                        <tr key={row.carrier.organization_id} className="border-b border-slate-100 last:border-0">
                          <TableCell>{row.carrier.name}</TableCell>
                          <TableCell>{String(row.claims_total)}</TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.requested}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.recovered}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.payments}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.commission
                              ? row.commission.by_currency.map((b) => `${b.currency} ${b.earned}`).join(", ") || "—"
                              : "—"}
                          </TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                </CardBody>
              </Card>
            </>
          ) : null}
        </div>
      )}

      {tab === "carriers" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="New partner link" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                The link starts pending and grants nothing until an owner or admin of the carrier
                organization approves it. You need the carrier organization&apos;s id; the carrier
                sees the request in its pending partner links.
              </p>
              <form onSubmit={handleCreateLink} className="space-y-3">
                <Input id="agency-carrier-organization-id"
                  label="Carrier organization id"
                  value={newCarrierId}
                  onChange={(event) => setNewCarrierId(event.target.value)}
                  placeholder="UUID of the carrier organization"
                  required
                />
                <Input id="agency-referral-code-optional"
                  label="Referral code (optional)"
                  value={newReferralCode}
                  onChange={(event) => setNewReferralCode(event.target.value)}
                  placeholder="REF-XXXXXXXX"
                />
                <Input id="agency-link-notes"
                  label="Notes (optional)"
                  value={newLinkNotes}
                  onChange={(event) => setNewLinkNotes(event.target.value)}
                />
                <Button type="submit" disabled={linkBusy}>
                  {linkBusy ? "Creating…" : "Create partner link"}
                </Button>
              </form>
              {linkNote ? <Alert tone="success">{linkNote}</Alert> : null}
              {linkFormError ? <Alert tone="error">{linkFormError}</Alert> : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Partner links" />
            <CardBody>
              <div className="mb-3 flex gap-2">
                <Select id="agency-status-filter"
                  label="Status filter"
                  value={linkStatusFilter}
                  onChange={(event) => {
                    setLinkStatusFilter(event.target.value);
                    loadLinks(event.target.value || undefined);
                  }}
                >
                  <option value="">All statuses</option>
                  {PARTNER_LINK_STATUSES.map((status) => (
                    <option key={status.value} value={status.value}>
                      {status.label}
                    </option>
                  ))}
                </Select>
              </div>
              {linksLoading ? (
                <Spinner label="Loading partner links…" />
              ) : linksError ? (
                <Alert tone="error">{linksError}</Alert>
              ) : links.length === 0 ? (
                <EmptyState
                  title="No partner links yet"
                  description="Create the first link above."
                />
              ) : (
                <Table headers={["Carrier", "Status", "Referral code", "Assigned plan", "Created", "Actions"]}>
                  {links.map((link) => (
                    <tr key={link.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{link.carrier_organization_name ?? link.carrier_organization_id}</TableCell>
                      <TableCell>
                        <Badge tone={linkTone(link.status)}>{link.status}</Badge>
                      </TableCell>
                      <TableCell>{link.referred_by_code ?? "—"}</TableCell>
                      <TableCell>{link.assigned_plan ?? "—"}</TableCell>
                      <TableCell>{formatDate(link.created_at)}</TableCell>
                      <TableCell>
                        <span className="flex gap-2">
                          {link.status === "approved" ? (
                            <Button
                              type="button"
                              variant="secondary"
                              disabled={linkBusy}
                              onClick={() => handleLinkAction(link.id, "suspend")}
                            >
                              Suspend
                            </Button>
                          ) : null}
                          {link.status === "suspended" ? (
                            <Button
                              type="button"
                              variant="secondary"
                              disabled={linkBusy}
                              onClick={() => handleLinkAction(link.id, "reactivate")}
                            >
                              Reactivate
                            </Button>
                          ) : null}
                          {link.status !== "removed" ? (
                            <Button
                              type="button"
                              variant="secondary"
                              disabled={linkBusy}
                              onClick={() => handleLinkAction(link.id, "remove")}
                            >
                              Remove
                            </Button>
                          ) : null}
                        </span>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              )}
              <p className="mt-2 text-sm text-slate-500">
                {linksMeta.total} links. Removed links are kept as a record, never deleted, so referral
                attribution survives.
              </p>
            </CardBody>
          </Card>
        </div>
      )}

      {tab === "claims" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="Carrier claims (read-only)" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                Claims a linked carrier recorded. The agency can read them; writing a claim stays
                with the carrier organization itself. Owners and admins see every linked carrier;
                other members need a grant for the carrier they open.
              </p>
              <Select id="agency-claims-carrier"
                label="Carrier"
                value={selectedCarrierId}
                onChange={(event) => setSelectedCarrierId(event.target.value)}
              >
                {carriers.length === 0 ? <option value="">No active carriers</option> : null}
                {carriers.map((row) => (
                  <option key={row.carrier.organization_id} value={row.carrier.organization_id}>
                    {row.carrier.name}
                  </option>
                ))}
              </Select>
              {claimsLoading ? (
                <Spinner label="Loading carrier claims…" />
              ) : claimsError ? (
                <Alert tone="error">{claimsError}</Alert>
              ) : claims.length === 0 ? (
                <EmptyState
                  title="No claims for this carrier"
                  description="The carrier has not recorded any claims yet."
                />
              ) : (
                <Table headers={["Reference", "Category", "Status", "Requested", "Recovered", "Created"]}>
                  {claims.map((claim) => (
                    <tr key={claim.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>
                        <button
                          type="button"
                          className="underline"
                          onClick={async () => {
                            try {
                              setSelectedClaim(
                                await getCarrierClaim(selectedCarrierId, claim.id),
                              );
                            } catch (err) {
                              setClaimsError(errorMessage(err, "Could not load the claim."));
                            }
                          }}
                        >
                          {claim.claim_reference}
                        </button>
                      </TableCell>
                      <TableCell>{claim.claim_category}</TableCell>
                      <TableCell>
                        <Badge tone={claim.status === "paid" ? "success" : "info"}>{claim.status}</Badge>
                      </TableCell>
                      <TableCell>
                        {claim.currency} {claim.requested_amount}
                      </TableCell>
                      <TableCell>
                        {claim.currency} {claim.recovered_amount}
                      </TableCell>
                      <TableCell>{formatDate(claim.created_at)}</TableCell>
                    </tr>
                  ))}
                </Table>
              )}
              <p className="mt-2 text-sm text-slate-500">{claimsMeta.total} claims.</p>
              {selectedClaim ? (
                <Alert tone="info">
                  {selectedClaim.claim_reference}: {selectedClaim.currency}{" "}
                  {selectedClaim.requested_amount} requested, {selectedClaim.currency}{" "}
                  {selectedClaim.recovered_amount} recovered, status {selectedClaim.status}. Shipment{" "}
                  {selectedClaim.shipment_reference ?? "—"}.
                </Alert>
              ) : null}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === "reports" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="Consolidated report" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                Cross-carrier aggregates computed only from the linked carriers&apos; own recorded
                claims and payments. No external benchmarks.
              </p>
              <form
                className="mb-3 flex flex-wrap gap-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  loadReport();
                }}
              >
                <Input id="agency-from-date"
                  label="From (date)"
                  type="date"
                  value={reportDateFrom}
                  onChange={(event) => setReportDateFrom(event.target.value)}
                />
                <Input id="agency-to-date"
                  label="To (date)"
                  type="date"
                  value={reportDateTo}
                  onChange={(event) => setReportDateTo(event.target.value)}
                />
                <Select id="agency-report-carrier"
                  label="Carrier"
                  value={reportCarrierId}
                  onChange={(event) => setReportCarrierId(event.target.value)}
                >
                  <option value="">All carriers</option>
                  {carriers.map((row) => (
                    <option key={row.carrier.organization_id} value={row.carrier.organization_id}>
                      {row.carrier.name}
                    </option>
                  ))}
                </Select>
                <Button type="submit">Run report</Button>
              </form>
              {reportLoading ? (
                <Spinner label="Loading the consolidated report…" />
              ) : reportError ? (
                <Alert tone="error">{reportError}</Alert>
              ) : report ? (
                <>
                  {report.carriers.length === 0 ? (
                    <EmptyState
                      title="No carriers in this report"
                      description="Approve a partner link first, or widen the date window."
                    />
                  ) : (
                    <Table headers={["Carrier", "Claims", "Requested", "Accepted", "Recovered", "Payments", "Outstanding"]}>
                      {report.carriers.map((row) => (
                        <tr key={row.carrier.organization_id} className="border-b border-slate-100 last:border-0">
                          <TableCell>{row.carrier.name}</TableCell>
                          <TableCell>{String(row.claims_total)}</TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.requested}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.accepted}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.recovered}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.payments}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.totals.map((t) => `${t.currency} ${t.outstanding}`).join(", ") || "—"}
                          </TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                  <p className="mt-2 text-sm text-slate-500">{report.note}</p>
                </>
              ) : null}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === "referrals" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="New referral code" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                Codes are attribution only: a partner link created with a code records which code
                brought the carrier in. A code carries no discount and no payout.
              </p>
              <form onSubmit={handleCreateCode} className="space-y-3">
                <Input id="agency-code-notes"
                  label="Notes (optional)"
                  value={codeNotes}
                  onChange={(event) => setCodeNotes(event.target.value)}
                />
                <Button type="submit" disabled={codeBusy}>
                  {codeBusy ? "Creating…" : "Create referral code"}
                </Button>
              </form>
              {codeNote ? <Alert tone="success">{codeNote}</Alert> : null}
              {codeError ? <Alert tone="error">{codeError}</Alert> : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Referral codes" />
            <CardBody>
              {codesLoading ? (
                <Spinner label="Loading referral codes…" />
              ) : codesError ? (
                <Alert tone="error">{codesError}</Alert>
              ) : codes.length === 0 ? (
                <EmptyState title="No referral codes yet" description="Create the first code above." />
              ) : (
                <Table headers={["Code", "Status", "Uses", "Created", "Actions"]}>
                  {codes.map((code) => (
                    <tr key={code.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{code.code}</TableCell>
                      <TableCell>
                        <Badge tone={code.active ? "success" : "danger"}>
                          {code.active ? "active" : "deactivated"}
                        </Badge>
                      </TableCell>
                      <TableCell>{String(code.uses_count)}</TableCell>
                      <TableCell>{formatDate(code.created_at)}</TableCell>
                      <TableCell>
                        <span className="flex gap-2">
                          <Button
                            type="button"
                            variant="secondary"
                            onClick={async () => {
                              try {
                                setSelectedCode(await getReferralCode(code.id));
                              } catch (err) {
                                setCodeError(errorMessage(err, "Could not load the referral code."));
                              }
                            }}
                          >
                            Attribution
                          </Button>
                          {code.active ? (
                            <Button
                              type="button"
                              variant="secondary"
                              onClick={async () => {
                                try {
                                  await deactivateReferralCode(code.id);
                                  setCodeNote(`Code ${code.code} deactivated. Links that already cite it keep their attribution.`);
                                  await loadCodes();
                                } catch (err) {
                                  setCodeError(errorMessage(err, "Could not deactivate the code."));
                                }
                              }}
                            >
                              Deactivate
                            </Button>
                          ) : null}
                        </span>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              )}
              <p className="mt-2 text-sm text-slate-500">{codesMeta.total} codes.</p>
              {selectedCode ? (
                <Alert tone="info">
                  {selectedCode.code}: {selectedCode.uses_count} attributed carrier
                  {selectedCode.uses_count === 1 ? "" : "s"}.
                  {selectedCode.attributed_links.length > 0
                    ? ` ${selectedCode.attributed_links.map((link) => link.carrier_organization_name ?? link.carrier_organization_id).join(", ")}.`
                    : ""}
                </Alert>
              ) : null}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === "commissions" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="Commission rules" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                One default rule for the whole agency, plus optional per-carrier overrides. The rate
                applies to recorded payments only. Earned commission is a calculated figure; nothing
                here pays a partner out.
              </p>
              <form onSubmit={handleCreateRule} className="space-y-3">
                <Select id="agency-carrier-leave-empty-for-the-agency-default"
                  label="Carrier (leave empty for the agency default)"
                  value={ruleLinkId}
                  onChange={(event) => setRuleLinkId(event.target.value)}
                >
                  <option value="">Agency default</option>
                  {activeLinks.map((link) => (
                    <option key={link.id} value={link.id}>
                      {link.carrier_organization_name ?? link.carrier_organization_id}
                    </option>
                  ))}
                </Select>
                <Input id="agency-rate-percent"
                  label="Rate (percent)"
                  type="number"
                  min="0"
                  max="100"
                  step="0.01"
                  value={ruleRate}
                  onChange={(event) => setRuleRate(event.target.value)}
                  required
                />
                <Input id="agency-cap-amount-optional"
                  label="Cap (amount, optional)"
                  type="number"
                  min="0"
                  step="0.01"
                  value={ruleCap}
                  onChange={(event) => setRuleCap(event.target.value)}
                  placeholder="No cap"
                />
                <Button type="submit" disabled={ruleBusy}>
                  {ruleBusy ? "Creating…" : "Create commission rule"}
                </Button>
              </form>
              {ruleNote ? <Alert tone="success">{ruleNote}</Alert> : null}
              {ruleError ? <Alert tone="error">{ruleError}</Alert> : null}
              {rules.length === 0 ? (
                <EmptyState
                  title="No commission rules yet"
                  description="Without a rule, the configured default rate of 10% applies."
                />
              ) : (
                <Table headers={["Scope", "Rate", "Cap", "Active", "Actions"]}>
                  {rules.map((rule) => (
                    <tr key={rule.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>
                        {rule.is_default ? "Agency default" : rule.carrier_organization_name ?? "Carrier"}
                      </TableCell>
                      <TableCell>{rule.rate_percent}%</TableCell>
                      <TableCell>
                        {rule.cap_cents !== null ? `$${(rule.cap_cents / 100).toFixed(2)}` : "—"}
                      </TableCell>
                      <TableCell>{rule.active ? "Yes" : "No"}</TableCell>
                      <TableCell>
                        <span className="flex gap-2">
                          <Button
                            type="button"
                            variant="secondary"
                            onClick={async () => {
                              try {
                                await updateCommissionRule(rule.id, { active: !rule.active });
                                setRuleNote("Commission rule updated.");
                                await loadCommissions();
                              } catch (err) {
                                setRuleError(errorMessage(err, "Could not update the rule."));
                              }
                            }}
                          >
                            {rule.active ? "Deactivate" : "Activate"}
                          </Button>
                          <Button
                            type="button"
                            variant="secondary"
                            onClick={async () => {
                              try {
                                await deleteCommissionRule(rule.id);
                                setRuleNote("Commission rule deleted.");
                                await loadCommissions();
                              } catch (err) {
                                setRuleError(errorMessage(err, "Could not delete the rule."));
                              }
                            }}
                          >
                            Delete
                          </Button>
                        </span>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Earned commission" />
            <CardBody>
              {commissionsLoading ? (
                <Spinner label="Loading commissions…" />
              ) : commissionsError ? (
                <Alert tone="error">{commissionsError}</Alert>
              ) : commissions ? (
                <>
                  {commissions.carriers.length === 0 ? (
                    <EmptyState
                      title="No commission yet"
                      description="Commission appears once a linked carrier records a payment."
                    />
                  ) : (
                    <Table headers={["Carrier", "Rate", "Payments", "Earned"]}>
                      {commissions.carriers.map((row) => (
                        <tr key={row.carrier.organization_id} className="border-b border-slate-100 last:border-0">
                          <TableCell>{row.carrier.name}</TableCell>
                          <TableCell>{row.rate_percent}%</TableCell>
                          <TableCell>
                            {row.by_currency.map((b) => `${b.currency} ${b.payments}`).join(", ") || "—"}
                          </TableCell>
                          <TableCell>
                            {row.by_currency.map((b) => `${b.currency} ${b.earned}${b.capped ? " (capped)" : ""}`).join(", ") || "—"}
                          </TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                  <p className="mt-2 text-sm text-slate-500">{commissions.note}</p>
                </>
              ) : null}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === "reseller" && (
        <div className="space-y-4">
          <Card>
            <CardHeader title="Reseller plan assignment" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                Assign a plan to a linked carrier. The change runs through the normal billing flow
                on the mock provider: an upgrade takes effect immediately, a downgrade is scheduled
                for the next renewal. No real money moves.
              </p>
              <form onSubmit={handleAssignPlan} className="space-y-3">
                <Select id="agency-plan-carrier-link"
                  label="Carrier link"
                  value={planLinkId}
                  onChange={(event) => setPlanLinkId(event.target.value)}
                  required
                >
                  <option value="">Select a carrier</option>
                  {activeLinks.map((link) => (
                    <option key={link.id} value={link.id}>
                      {link.carrier_organization_name ?? link.carrier_organization_id}
                    </option>
                  ))}
                </Select>
                <Select id="agency-plan"
                  label="Plan"
                  value={planKey}
                  onChange={(event) => setPlanKey(event.target.value)}
                >
                  <option value="free">Free</option>
                  <option value="starter">Starter</option>
                  <option value="growth">Growth</option>
                  <option value="fleet">Fleet</option>
                  <option value="agency">Agency</option>
                </Select>
                <Button type="submit" disabled={planBusy || !planLinkId}>
                  {planBusy ? "Assigning…" : "Assign plan"}
                </Button>
              </form>
              {planNote ? <Alert tone="success">{planNote}</Alert> : null}
              {planError ? <Alert tone="error">{planError}</Alert> : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Carrier access grants" />
            <CardBody>
              <p className="mb-3 text-sm text-slate-500">
                Owners and admins see every linked carrier. Other agency members need a grant for
                each carrier: view reads the carrier&apos;s claims, dashboard, and reports; manage
                also covers commission rules for that carrier. Only owners and admins manage grants.
              </p>
              <form onSubmit={handleCreateGrant} className="space-y-3">
                <Select id="agency-grant-carrier-link"
                  label="Carrier link"
                  value={grantLinkId}
                  onChange={(event) => {
                    setGrantLinkId(event.target.value);
                    loadGrants(event.target.value);
                  }}
                  required
                >
                  <option value="">Select a carrier</option>
                  {activeLinks.map((link) => (
                    <option key={link.id} value={link.id}>
                      {link.carrier_organization_name ?? link.carrier_organization_id}
                    </option>
                  ))}
                </Select>
                <Input id="agency-member-user-id"
                  label="Member user id"
                  type="number"
                  value={grantUserId}
                  onChange={(event) => setGrantUserId(event.target.value)}
                  placeholder="Numeric user id of an agency member"
                  required
                />
                <Select id="agency-scope"
                  label="Scope"
                  value={grantScope}
                  onChange={(event) => setGrantScope(event.target.value as AgencyCarrierGrantScope)}
                >
                  {GRANT_SCOPES.map((scope) => (
                    <option key={scope.value} value={scope.value}>
                      {scope.label}
                    </option>
                  ))}
                </Select>
                <Button type="submit" disabled={grantBusy || !grantLinkId}>
                  {grantBusy ? "Saving…" : "Save grant"}
                </Button>
              </form>
              {grantNote ? <Alert tone="success">{grantNote}</Alert> : null}
              {grantError ? <Alert tone="error">{grantError}</Alert> : null}
              {grants.length > 0 ? (
                <Table headers={["Member", "Scope", "Granted"]}>
                  {grants.map((grant) => (
                    <tr key={grant.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>
                        {grant.user_name ?? `User ${grant.user_id}`} ({grant.user_email ?? "—"})
                      </TableCell>
                      <TableCell>{grant.scope}</TableCell>
                      <TableCell>{formatDate(grant.created_at)}</TableCell>
                    </tr>
                  ))}
                </Table>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Onboarding flow" />
            <CardBody>
              <ol className="list-decimal space-y-1 pl-5 text-sm">
                <li>Create a partner link on the Carriers tab (pending).</li>
                <li>
                  The carrier organization approves it from its own pending partner links. Until
                  then the link grants nothing.
                </li>
                <li>Assign a plan above, set commission rules on the Commissions tab, and grant carrier access above.</li>
                <li>Suspend or remove a link any time from the Carriers tab; history is kept.</li>
              </ol>
              <p className="mt-2 text-sm text-slate-500">
                White-label branding (custom logos, colors, domains, branded portals) is v2.1.1,
                not this version.
              </p>
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}

export default function AgencyPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Agency"
        description={
          currentOrg
            ? `Partner links, carrier management, consolidated reporting, referrals, commissions, and reseller workflows for ${currentOrg.name}. Agency tools need the Agency plan.`
            : "No organization selected."
        }
        crumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Agency" }]}
      />
      {currentOrg ? (
        <AgencyContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use the agency tools.</Alert>
      )}
    </div>
  );
}
