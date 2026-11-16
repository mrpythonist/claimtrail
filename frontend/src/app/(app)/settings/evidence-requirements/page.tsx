"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select, Textarea } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageEvidenceRequirements } from "@/lib/permissions";
import { blankToNull, DOCUMENT_CATEGORIES } from "@/lib/shipments";
import type { FieldErrors } from "@/lib/validation";
import { validateEvidenceRequirementForm } from "@/lib/validation";
import type {
  DocumentCategory,
  EventCategory,
  EvidenceRequirement,
  Organization,
} from "@/lib/types";

function RequirementsContent({ org }: { org: Organization }) {
  const [requirements, setRequirements] = useState<EvidenceRequirement[]>([]);
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [showAddForm, setShowAddForm] = useState(false);
  const [eventCategoryId, setEventCategoryId] = useState("");
  const [documentCategory, setDocumentCategory] =
    useState<DocumentCategory>("bill_of_lading");
  const [isRequired, setIsRequired] = useState(true);
  const [notes, setNotes] = useState("");
  const [formErrors, setFormErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editingRequired, setEditingRequired] = useState(true);
  const [updating, setUpdating] = useState(false);

  const [deleteTarget, setDeleteTarget] =
    useState<EvidenceRequirement | null>(null);
  const [deleting, setDeleting] = useState(false);

  const canManage = canManageEvidenceRequirements(org.current_user_role);

  useEffect(() => {
    let cancelled = false;

    const requirementsRequest = apiFetch<{ data: EvidenceRequirement[] }>(
      "/evidence-requirements?per_page=100",
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setRequirements(response.data);
    });

    const categoriesRequest = apiFetch<{ data: EventCategory[] }>(
      "/event-categories",
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setCategories(response.data);
    });

    Promise.all([requirementsRequest, categoriesRequest])
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load evidence requirements.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function showError(err: unknown, fallback: string) {
    setError(err instanceof ApiRequestError ? err.message : fallback);
  }

  async function handleAdd(event: FormEvent) {
    event.preventDefault();
    const errors = validateEvidenceRequirementForm({
      document_category: documentCategory,
    });
    setFormErrors(errors);
    if (Object.keys(errors).length > 0) return;

    setError(null);
    setNotice(null);
    setSaving(true);
    try {
      const response = await apiFetch<{ data: EvidenceRequirement }>(
        "/evidence-requirements",
        {
          method: "POST",
          body: {
            event_category_id: eventCategoryId ? eventCategoryId : null,
            document_category: documentCategory,
            is_required: isRequired,
            notes: blankToNull(notes),
          },
          orgId: org.id,
        },
      );
      setRequirements((current) => [response.data, ...current]);
      setNotice("Requirement added.");
      setEventCategoryId("");
      setDocumentCategory("bill_of_lading");
      setIsRequired(true);
      setNotes("");
      setShowAddForm(false);
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setFormErrors(mapped);
      } else {
        showError(err, "Could not add the requirement.");
      }
    } finally {
      setSaving(false);
    }
  }

  function openEdit(requirement: EvidenceRequirement) {
    setEditingId(requirement.id);
    setEditingRequired(requirement.is_required);
  }

  async function handleToggleRequired(requirement: EvidenceRequirement) {
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const response = await apiFetch<{ data: EvidenceRequirement }>(
        `/evidence-requirements/${requirement.id}`,
        {
          method: "PUT",
          body: { is_required: editingRequired },
          orgId: org.id,
        },
      );
      setRequirements((current) =>
        current.map((item) =>
          item.id === requirement.id ? response.data : item,
        ),
      );
      setNotice(
        `${response.data.document_category_label} is now ${response.data.is_required ? "required" : "optional"}.`,
      );
      setEditingId(null);
    } catch (err) {
      showError(err, "Could not update the requirement.");
    } finally {
      setUpdating(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await apiFetch(`/evidence-requirements/${deleteTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setRequirements((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Deleted the ${deleteTarget.document_category_label} requirement.`);
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the requirement.");
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  if (loading) {
    return <Spinner label="Loading evidence requirements" />;
  }

  return (
    <div className="space-y-6">
      {notice && <Alert tone="success">{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}

      {!canManage && (
        <Alert tone="info">
          Only owners, admins, and operations managers can change evidence
          requirements.
        </Alert>
      )}

      <Card>
        <CardHeader
          title="Evidence requirements"
          description="Which documents a claim needs before it is ready to submit. Requirements scoped to an event type apply only to claims from that type of event; the rest apply to every claim."
          action={
            canManage ? (
              <Button size="sm" onClick={() => setShowAddForm((show) => !show)}>
                {showAddForm ? "Hide form" : "Add requirement"}
              </Button>
            ) : undefined
          }
        />
        {showAddForm && canManage && (
          <CardBody className="border-b border-slate-200">
            <form onSubmit={handleAdd} className="space-y-4" noValidate>
              <div className="grid gap-4 sm:grid-cols-2">
                <Select
                  id="event_category_id"
                  label="Event type"
                  value={eventCategoryId}
                  onChange={(event) => setEventCategoryId(event.target.value)}
                  error={formErrors.event_category_id}
                  help="All event types means this document is required for every claim."
                >
                  <option value="">All event types</option>
                  {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                      {category.label}
                    </option>
                  ))}
                </Select>
                <Select
                  id="document_category"
                  label="Document category"
                  value={documentCategory}
                  onChange={(event) =>
                    setDocumentCategory(event.target.value as DocumentCategory)
                  }
                  error={formErrors.document_category}
                >
                  {DOCUMENT_CATEGORIES.map((category) => (
                    <option key={category.value} value={category.value}>
                      {category.label}
                    </option>
                  ))}
                </Select>
              </div>
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={isRequired}
                  onChange={(event) => setIsRequired(event.target.checked)}
                  className="h-4 w-4 rounded border-slate-300"
                />
                Required (uncheck to make it optional)
              </label>
              <Textarea
                id="requirement-notes"
                label="Notes"
                rows={2}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                error={formErrors.notes}
                placeholder="Why this document is needed, if that helps reviewers."
              />
              <Button type="submit" loading={saving}>
                Add requirement
              </Button>
            </form>
          </CardBody>
        )}
        {requirements.length === 0 ? (
          <EmptyState
            title="No evidence requirements yet"
            description="Add requirements to define what a claim needs before it is ready to submit. Until then, readiness uses the default document list."
          />
        ) : (
          <Table headers={["Document", "Event type", "Required", "Notes", ""]}>
            {requirements.map((requirement) => (
              <tr key={requirement.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">
                    {requirement.document_category_label}
                  </p>
                </TableCell>
                <TableCell>
                  {requirement.event_category?.label ?? "All event types"}
                </TableCell>
                <TableCell>
                  {editingId === requirement.id ? (
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                      <input
                        type="checkbox"
                        checked={editingRequired}
                        onChange={(event) =>
                          setEditingRequired(event.target.checked)
                        }
                        className="h-4 w-4 rounded border-slate-300"
                      />
                      Required
                    </label>
                  ) : (
                    <Badge tone={requirement.is_required ? "warning" : "neutral"}>
                      {requirement.is_required ? "Required" : "Optional"}
                    </Badge>
                  )}
                </TableCell>
                <TableCell>{requirement.notes ?? "None"}</TableCell>
                <TableCell>
                  {canManage && (
                    <div className="flex gap-1">
                      {editingId === requirement.id ? (
                        <>
                          <Button
                            variant="ghost"
                            size="sm"
                            loading={updating}
                            onClick={() => handleToggleRequired(requirement)}
                          >
                            Save
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditingId(null)}
                          >
                            Cancel
                          </Button>
                        </>
                      ) : (
                        <>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openEdit(requirement)}
                          >
                            Edit
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDeleteTarget(requirement)}
                          >
                            Delete
                          </Button>
                        </>
                      )}
                    </div>
                  )}
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Modal
        open={deleteTarget !== null}
        title="Delete requirement"
        description={
          deleteTarget
            ? `Delete the ${deleteTarget.document_category_label} requirement${deleteTarget.event_category ? ` for ${deleteTarget.event_category.label} events` : ""}? Claims will no longer count this document toward readiness.`
            : undefined
        }
        confirmLabel="Delete requirement"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </div>
  );
}

export default function EvidenceRequirementsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Evidence requirements"
        description={
          currentOrg
            ? `Document requirements for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Organization settings", href: "/settings/organization" },
          { label: "Evidence requirements" },
        ]}
      />
      {currentOrg ? (
        <RequirementsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to manage evidence requirements.
        </Alert>
      )}
    </div>
  );
}
