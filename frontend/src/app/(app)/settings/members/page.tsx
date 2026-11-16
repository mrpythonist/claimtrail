"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageInvitations, canManageMembers, roleLabel } from "@/lib/permissions";
import type {
  MembershipStatus,
  Organization,
  OrganizationInvitation,
  OrganizationMember,
  OrganizationRole,
  PaginatedMeta,
} from "@/lib/types";

const ROLES: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "dispatcher",
  "billing_staff",
  "viewer",
];

function invitationTone(status: string): "success" | "warning" | "neutral" {
  if (status === "accepted") return "success";
  if (status === "expired") return "warning";
  return "neutral";
}

/**
 * Members and invitations for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with fresh
 * state. The loading flag starts true and is only cleared in the fetch
 * callbacks, so the effect never sets state synchronously.
 */
function MembersContent({ org }: { org: Organization }) {
  const { user } = useAuth();
  const [members, setMembers] = useState<OrganizationMember[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [invitations, setInvitations] = useState<OrganizationInvitation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [savingMemberId, setSavingMemberId] = useState<number | null>(null);
  const [removeTarget, setRemoveTarget] = useState<OrganizationMember | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<OrganizationInvitation | null>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState<OrganizationRole>("viewer");
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [inviting, setInviting] = useState(false);

  const myRole = org.current_user_role;
  const canManage = canManageMembers(myRole);
  const canInvite = canManageInvitations(myRole);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (search) params.set("search", search);
    if (roleFilter) params.set("role", roleFilter);
    if (statusFilter) params.set("status", statusFilter);

    const membersRequest = apiFetch<{
      data: OrganizationMember[];
      meta: PaginatedMeta;
    }>(`/members?${params.toString()}`, { orgId: org.id }).then((response) => {
      if (!cancelled) {
        setMembers(response.data);
        setMeta(response.meta);
      }
    });

    const invitationsRequest = canInvite
      ? apiFetch<{ data: OrganizationInvitation[] }>("/invitations", {
          orgId: org.id,
        }).then((response) => {
          if (!cancelled) setInvitations(response.data);
        })
      : Promise.resolve().then(() => {
          if (!cancelled) setInvitations([]);
        });

    Promise.all([membersRequest, invitationsRequest])
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load members.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, canInvite, page, search, roleFilter, statusFilter]);

  async function updateMember(
    member: OrganizationMember,
    changes: { role?: OrganizationRole; status?: MembershipStatus },
  ) {
    setNotice(null);
    setError(null);
    setSavingMemberId(member.id);
    try {
      const response = await apiFetch<{ data: OrganizationMember }>(
        `/members/${member.id}`,
        { method: "PUT", body: changes, orgId: org.id },
      );
      setMembers((current) =>
        current.map((item) => (item.id === member.id ? response.data : item)),
      );
      setNotice(`Updated ${member.user.name}.`);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not update the member.",
      );
    } finally {
      setSavingMemberId(null);
    }
  }

  async function confirmRemove() {
    if (!removeTarget) return;
    setConfirmLoading(true);
    try {
      await apiFetch(`/members/${removeTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setMembers((current) =>
        current.filter((item) => item.id !== removeTarget.id),
      );
      setNotice(`Removed ${removeTarget.user.name}.`);
      setRemoveTarget(null);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not remove the member.",
      );
      setRemoveTarget(null);
    } finally {
      setConfirmLoading(false);
    }
  }

  async function confirmRevoke() {
    if (!revokeTarget) return;
    setConfirmLoading(true);
    try {
      await apiFetch(`/invitations/${revokeTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setInvitations((current) =>
        current.filter((item) => item.id !== revokeTarget.id),
      );
      setNotice(`Revoked the invitation to ${revokeTarget.email}.`);
      setRevokeTarget(null);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not revoke the invitation.",
      );
      setRevokeTarget(null);
    } finally {
      setConfirmLoading(false);
    }
  }

  async function handleInvite(event: FormEvent) {
    event.preventDefault();
    if (!inviteEmail.trim()) {
      setInviteError("Email is required.");
      return;
    }
    setInviteError(null);
    setNotice(null);
    setInviting(true);
    try {
      const response = await apiFetch<{ data: OrganizationInvitation }>(
        "/invitations",
        {
          method: "POST",
          body: { email: inviteEmail.trim(), role: inviteRole },
          orgId: org.id,
        },
      );
      setInvitations((current) => [response.data, ...current]);
      setInviteEmail("");
      setNotice(`Invitation sent to ${response.data.email}.`);
    } catch (err) {
      setInviteError(
        err instanceof ApiRequestError
          ? err.fieldError("email") ?? err.message
          : "Could not send the invitation.",
      );
    } finally {
      setInviting(false);
    }
  }

  return (
    <div>
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading members" />
      ) : (
        <div className="space-y-6">
          <Card>
            <CardHeader
              title="Members"
              description="Change roles, deactivate accounts, or remove members."
            />
            <div className="flex flex-wrap gap-3 border-b border-slate-200 px-5 py-4">
              <div className="min-w-48 flex-1">
                <Input
                  id="member-search"
                  label="Search"
                  type="search"
                  value={search}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                  }}
                  placeholder="Name or email"
                />
              </div>
              <div className="w-44">
                <Select
                  id="role-filter"
                  label="Role"
                  value={roleFilter}
                  onChange={(event) => {
                    setRoleFilter(event.target.value);
                    setPage(1);
                  }}
                >
                  <option value="">All roles</option>
                  {ROLES.map((role) => (
                    <option key={role} value={role}>
                      {roleLabel(role)}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="w-36">
                <Select
                  id="status-filter"
                  label="Status"
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value);
                    setPage(1);
                  }}
                >
                  <option value="">All</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </Select>
              </div>
            </div>
            {members.length === 0 ? (
              <EmptyState
                title="No members found"
                description="Try a different search or filter."
              />
            ) : (
              <>
                <Table headers={["Member", "Role", "Status", "Joined", ""]}>
                  {members.map((member) => (
                    <tr key={member.id}>
                      <TableCell>
                        <p className="font-medium text-slate-900">
                          {member.user.name}
                          {member.user_id === user?.id && (
                            <span className="ml-2 text-xs text-slate-500">(you)</span>
                          )}
                        </p>
                        <p className="text-xs text-slate-500">{member.user.email}</p>
                      </TableCell>
                      <TableCell>
                        {canManage ? (
                          <select
                            aria-label={`Role for ${member.user.name}`}
                            value={member.role}
                            disabled={savingMemberId === member.id}
                            onChange={(event) =>
                              updateMember(member, {
                                role: event.target.value as OrganizationRole,
                              })
                            }
                            className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm disabled:opacity-60"
                          >
                            {ROLES.map((role) => (
                              <option key={role} value={role}>
                                {roleLabel(role)}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <Badge tone="neutral">{member.role_label}</Badge>
                        )}
                      </TableCell>
                      <TableCell>
                        {canManage ? (
                          <select
                            aria-label={`Status for ${member.user.name}`}
                            value={member.status}
                            disabled={savingMemberId === member.id}
                            onChange={(event) =>
                              updateMember(member, {
                                status: event.target.value as MembershipStatus,
                              })
                            }
                            className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm disabled:opacity-60"
                          >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                          </select>
                        ) : (
                          <Badge
                            tone={member.status === "active" ? "success" : "neutral"}
                          >
                            {member.status}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell>
                        {member.joined_at
                          ? new Date(member.joined_at).toLocaleDateString()
                          : "Unknown"}
                      </TableCell>
                      <TableCell>
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setRemoveTarget(member)}
                          >
                            Remove
                          </Button>
                        )}
                      </TableCell>
                    </tr>
                  ))}
                </Table>
                {meta && meta.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                    <p className="text-slate-500">
                      Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                      members)
                    </p>
                    <div className="flex gap-2">
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={page <= 1}
                        onClick={() => setPage((current) => current - 1)}
                      >
                        Previous
                      </Button>
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={page >= meta.last_page}
                        onClick={() => setPage((current) => current + 1)}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </Card>

          {canInvite && (
            <Card>
              <CardHeader
                title="Invite a member"
                description="They receive an email with a link that expires in 7 days."
              />
              <CardBody>
                <form
                  onSubmit={handleInvite}
                  className="flex flex-wrap items-end gap-3"
                  noValidate
                >
                  <div className="min-w-64 flex-1">
                    <Input
                      id="invite-email"
                      label="Email"
                      type="email"
                      value={inviteEmail}
                      onChange={(event) => setInviteEmail(event.target.value)}
                      error={inviteError ?? undefined}
                      placeholder="colleague@example.com"
                    />
                  </div>
                  <div className="w-52">
                    <Select
                      id="invite-role"
                      label="Role"
                      value={inviteRole}
                      onChange={(event) =>
                        setInviteRole(event.target.value as OrganizationRole)
                      }
                    >
                      {ROLES.map((role) => (
                        <option key={role} value={role}>
                          {roleLabel(role)}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <Button type="submit" loading={inviting}>
                    Send invitation
                  </Button>
                </form>
              </CardBody>
            </Card>
          )}

          {canInvite && (
            <Card>
              <CardHeader
                title="Invitations"
                description="Pending, accepted, and expired invitations."
              />
              {invitations.length === 0 ? (
                <EmptyState
                  title="No invitations yet"
                  description="Invitations you send will appear here."
                />
              ) : (
                <Table headers={["Email", "Role", "Status", "Expires", ""]}>
                  {invitations.map((invitation) => (
                    <tr key={invitation.id}>
                      <TableCell>
                        <p className="font-medium text-slate-900">
                          {invitation.email}
                        </p>
                        {invitation.invited_by && (
                          <p className="text-xs text-slate-500">
                            Invited by {invitation.invited_by.name}
                          </p>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge tone="neutral">{invitation.role_label}</Badge>
                      </TableCell>
                      <TableCell>
                        <Badge tone={invitationTone(invitation.status)}>
                          {invitation.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {new Date(invitation.expires_at).toLocaleDateString()}
                      </TableCell>
                      <TableCell>
                        {invitation.status === "pending" && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setRevokeTarget(invitation)}
                          >
                            Revoke
                          </Button>
                        )}
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          )}
        </div>
      )}

      <Modal
        open={removeTarget !== null}
        title="Remove member"
        description={
          removeTarget
            ? `Remove ${removeTarget.user.name} (${removeTarget.user.email}) from ${org.name}? They will lose access immediately.`
            : undefined
        }
        confirmLabel="Remove member"
        danger
        loading={confirmLoading}
        onConfirm={confirmRemove}
        onClose={() => setRemoveTarget(null)}
      />
      <Modal
        open={revokeTarget !== null}
        title="Revoke invitation"
        description={
          revokeTarget
            ? `Revoke the invitation to ${revokeTarget.email}? The link will stop working.`
            : undefined
        }
        confirmLabel="Revoke invitation"
        danger
        loading={confirmLoading}
        onConfirm={confirmRevoke}
        onClose={() => setRevokeTarget(null)}
      />
    </div>
  );
}

export default function MembersPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Members"
        description={
          currentOrg
            ? `People with access to ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Members" },
        ]}
      />
      {currentOrg ? (
        <MembersContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to manage members.</Alert>
      )}
    </div>
  );
}
