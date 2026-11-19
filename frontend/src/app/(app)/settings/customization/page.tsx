"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { CLAIM_CATEGORIES } from "@/lib/claims";
import {
  createCustomField,
  createDeadlineSetting,
  createEvidenceTemplate,
  createExportTemplate,
  deleteCustomField,
  deleteDeadlineSetting,
  deleteEvidenceTemplate,
  deleteEventCategory,
  deleteExportTemplate,
  formatSelectOptions,
  getAvailableExportColumns,
  getCustomFields,
  getDeadlineSettings,
  getEventCategories,
  getEvidenceTemplates,
  getExportTemplates,
  parseSelectOptions,
  setDefaultExportTemplate,
  updateCustomField,
  updateDeadlineSetting,
  updateEvidenceTemplate,
  updateEventCategory,
  updateExportTemplate,
  type EvidenceTemplateItemInput,
} from "@/lib/customization";
import { canManageCustomization } from "@/lib/permissions";
import { DOCUMENT_CATEGORIES, documentCategoryLabel } from "@/lib/shipments";
import type {
  AvailableExportColumn,
  CustomField,
  CustomFieldResourceType,
  CustomFieldType,
  DeadlineScopeType,
  DeadlineSetting,
  DocumentCategory,
  EventCategory,
  EvidenceTemplate,
  ExportTemplate,
  Organization,
} from "@/lib/types";

const FIELD_TYPES: { value: CustomFieldType; label: string }[] = [
  { value: "text", label: "Text" },
  { value: "number", label: "Number" },
  { value: "date", label: "Date" },
  { value: "select", label: "Dropdown" },
  { value: "boolean", label: "Yes / No" },
];

const SCOPE_TYPES: { value: DeadlineScopeType; label: string }[] = [
  { value: "global", label: "Every claim" },
  { value: "claim_category", label: "Claim category" },
  { value: "event_category", label: "Event category" },
];

function fieldTypeLabel(type: CustomFieldType): string {
  return FIELD_TYPES.find((item) => item.value === type)?.label ?? type;
}

function scopeLabel(setting: DeadlineSetting): string {
  if (setting.scope_type === "global") return "Every claim";
  if (setting.scope_type === "claim_category") {
    return `Claim category: ${CLAIM_CATEGORIES.find((item) => item.value === setting.scope_value)?.label ?? setting.scope_value ?? "Unknown"}`;
  }
  return `Event category: ${setting.scope_value ?? "Unknown"}`;
}

function parseReminderDays(value: string): number[] {
  return value
    .split(",")
    .map((part) => Number(part.trim()))
    .filter((day) => Number.isFinite(day) && day >= 0);
}

function showError(
  err: unknown,
  fallback: string,
  setError: (message: string | null) => void,
) {
  setError(err instanceof ApiRequestError ? err.message : fallback);
}

/* ---------------- Custom fields ---------------- */

function CustomFieldsSection({ org }: { org: Organization }) {
  const [fields, setFields] = useState<CustomField[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [resourceType, setResourceType] =
    useState<CustomFieldResourceType>("shipment");
  const [label, setLabel] = useState("");
  const [fieldType, setFieldType] = useState<CustomFieldType>("text");
  const [fieldKey, setFieldKey] = useState("");
  const [optionsText, setOptionsText] = useState("");
  const [sortOrder, setSortOrder] = useState("0");
  const [isRequired, setIsRequired] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<CustomField | null>(null);
  const [editLabel, setEditLabel] = useState("");
  const [editOptionsText, setEditOptionsText] = useState("");
  const [editSortOrder, setEditSortOrder] = useState("0");
  const [editRequired, setEditRequired] = useState(false);
  const [editActive, setEditActive] = useState(true);
  const [updating, setUpdating] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<CustomField | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      getCustomFields(org.id, "shipment"),
      getCustomFields(org.id, "claim"),
    ])
      .then(([shipmentFields, claimFields]) => {
        if (!cancelled) setFields([...shipmentFields, ...claimFields]);
      })
      .catch((err) => {
        if (!cancelled) showError(err, "Could not load custom fields.", setError);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function handleCreate(event: FormEvent) {
    event.preventDefault();
    if (!label.trim()) {
      setFormError("Label is required.");
      return;
    }
    if (fieldType === "select" && parseSelectOptions(optionsText).length === 0) {
      setFormError("Dropdown fields need at least one option.");
      return;
    }
    setFormError(null);
    setError(null);
    setNotice(null);
    setSaving(true);
    try {
      const created = await createCustomField(org.id, {
        resource_type: resourceType,
        label: label.trim(),
        field_type: fieldType,
        is_required: isRequired,
        options:
          fieldType === "select" ? parseSelectOptions(optionsText) : null,
        sort_order: Number(sortOrder) || 0,
        field_key: fieldKey.trim() ? fieldKey.trim() : undefined,
      });
      setFields((current) => [...current, created]);
      setNotice(`Custom field "${created.label}" added.`);
      setLabel("");
      setFieldKey("");
      setOptionsText("");
      setSortOrder("0");
      setIsRequired(false);
      setShowForm(false);
    } catch (err) {
      if (err instanceof ApiRequestError && err.fieldError("label")) {
        setFormError(err.fieldError("label") ?? err.message);
      } else {
        showError(err, "Could not add the custom field.", setError);
      }
    } finally {
      setSaving(false);
    }
  }

  function openEdit(field: CustomField) {
    setEditing(field);
    setEditLabel(field.label);
    setEditOptionsText(formatSelectOptions(field.options));
    setEditSortOrder(String(field.sort_order));
    setEditRequired(field.is_required);
    setEditActive(field.is_active);
  }

  async function handleUpdate() {
    if (!editing) return;
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const updated = await updateCustomField(org.id, editing.id, {
        label: editLabel.trim(),
        is_required: editRequired,
        options:
          editing.field_type === "select"
            ? parseSelectOptions(editOptionsText)
            : null,
        sort_order: Number(editSortOrder) || 0,
        is_active: editActive,
      });
      setFields((current) =>
        current.map((item) => (item.id === editing.id ? updated : item)),
      );
      setNotice(`Custom field "${updated.label}" updated.`);
      setEditing(null);
    } catch (err) {
      showError(err, "Could not update the custom field.", setError);
    } finally {
      setUpdating(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteCustomField(org.id, deleteTarget.id);
      setFields((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Custom field "${deleteTarget.label}" deleted.`);
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the custom field.", setError);
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  if (loading) return <Spinner label="Loading custom fields" />;

  return (
    <Card>
      <CardHeader
        title="Custom fields"
        description="Extra fields on shipments and claims. They appear on the create and edit forms and on the detail pages."
        action={
          <Button size="sm" onClick={() => setShowForm((show) => !show)}>
            {showForm ? "Hide form" : "Add custom field"}
          </Button>
        }
      />
      <CardBody className="space-y-4">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {showForm && (
          <form onSubmit={handleCreate} className="space-y-4 border-b border-slate-200 pb-4" noValidate>
            {formError && <Alert tone="error">{formError}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <Select
                id="custom-field-resource"
                label="Applies to"
                value={resourceType}
                onChange={(event) =>
                  setResourceType(event.target.value as CustomFieldResourceType)
                }
              >
                <option value="shipment">Shipment</option>
                <option value="claim">Claim</option>
              </Select>
              <Input
                id="custom-field-label"
                label="Label"
                type="text"
                value={label}
                onChange={(event) => setLabel(event.target.value)}
                placeholder="For example: Trailer number"
              />
              <Select
                id="custom-field-type"
                label="Field type"
                value={fieldType}
                onChange={(event) =>
                  setFieldType(event.target.value as CustomFieldType)
                }
              >
                {FIELD_TYPES.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </Select>
              <Input
                id="custom-field-key"
                label="Field key (optional)"
                type="text"
                value={fieldKey}
                onChange={(event) => setFieldKey(event.target.value)}
                placeholder="Left blank, a key is generated from the label"
              />
              {fieldType === "select" && (
                <Input
                  id="custom-field-options"
                  label="Options"
                  type="text"
                  value={optionsText}
                  onChange={(event) => setOptionsText(event.target.value)}
                  placeholder="Option one, option two"
                  help="Comma-separated list of options."
                />
              )}
              <Input
                id="custom-field-sort"
                label="Sort order"
                type="number"
                value={sortOrder}
                onChange={(event) => setSortOrder(event.target.value)}
              />
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={isRequired}
                onChange={(event) => setIsRequired(event.target.checked)}
                className="h-4 w-4 rounded border-slate-300"
              />
              Required
            </label>
            <Button type="submit" loading={saving}>
              Add custom field
            </Button>
          </form>
        )}
        {(["shipment", "claim"] as CustomFieldResourceType[]).map((resource) => {
          const list = fields
            .filter((field) => field.resource_type === resource)
            .sort((a, b) => a.sort_order - b.sort_order);
          return (
            <div key={resource}>
              <h3 className="text-sm font-semibold text-slate-900">
                {resource === "shipment" ? "Shipment fields" : "Claim fields"}
              </h3>
              {list.length === 0 ? (
                <p className="mt-1 text-sm text-slate-500">
                  No custom fields for {resource === "shipment" ? "shipments" : "claims"} yet.
                </p>
              ) : (
                <Table headers={["Label", "Key", "Type", "Required", "Active", ""]}>
                  {list.map((field) => (
                    <tr key={field.id}>
                      <TableCell>
                        <p className="font-medium text-slate-900">{field.label}</p>
                        {field.field_type === "select" && field.options && (
                          <p className="text-xs text-slate-500">
                            {field.options.join(", ")}
                          </p>
                        )}
                      </TableCell>
                      <TableCell>
                        <span className="font-mono text-xs">{field.field_key}</span>
                      </TableCell>
                      <TableCell>{fieldTypeLabel(field.field_type)}</TableCell>
                      <TableCell>
                        <Badge tone={field.is_required ? "warning" : "neutral"}>
                          {field.is_required ? "Required" : "Optional"}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <Badge tone={field.is_active ? "success" : "neutral"}>
                          {field.is_active ? "Active" : "Inactive"}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-1">
                          <Button variant="ghost" size="sm" onClick={() => openEdit(field)}>
                            Edit
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(field)}>
                            Delete
                          </Button>
                        </div>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              )}
            </div>
          );
        })}
      </CardBody>
      <Modal
        open={editing !== null}
        title="Edit custom field"
        description={editing ? `Edit ${editing.label}.` : undefined}
        confirmLabel="Save changes"
        loading={updating}
        onConfirm={handleUpdate}
        onClose={() => setEditing(null)}
      >
        <div className="space-y-4">
          <Input
            id="edit-custom-field-label"
            label="Label"
            type="text"
            value={editLabel}
            onChange={(event) => setEditLabel(event.target.value)}
          />
          {editing?.field_type === "select" && (
            <Input
              id="edit-custom-field-options"
              label="Options"
              type="text"
              value={editOptionsText}
              onChange={(event) => setEditOptionsText(event.target.value)}
              help="Comma-separated list of options."
            />
          )}
          <Input
            id="edit-custom-field-sort"
            label="Sort order"
            type="number"
            value={editSortOrder}
            onChange={(event) => setEditSortOrder(event.target.value)}
          />
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={editRequired}
              onChange={(event) => setEditRequired(event.target.checked)}
              className="h-4 w-4 rounded border-slate-300"
            />
            Required
          </label>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={editActive}
              onChange={(event) => setEditActive(event.target.checked)}
              className="h-4 w-4 rounded border-slate-300"
            />
            Active
          </label>
        </div>
      </Modal>
      <Modal
        open={deleteTarget !== null}
        title="Delete custom field"
        description={
          deleteTarget
            ? `Delete the custom field "${deleteTarget.label}"? Values already saved on shipments and claims are kept, but the field will no longer show on forms.`
            : undefined
        }
        confirmLabel="Delete field"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </Card>
  );
}

/* ---------------- Event categories ---------------- */

function EventCategoriesSection({ org }: { org: Organization }) {
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState<EventCategory | null>(null);
  const [editLabel, setEditLabel] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editActive, setEditActive] = useState(true);
  const [editAmount, setEditAmount] = useState("");
  const [editCurrency, setEditCurrency] = useState("");
  const [updating, setUpdating] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<EventCategory | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getEventCategories(org.id)
      .then((data) => {
        if (!cancelled) setCategories(data);
      })
      .catch((err) => {
        if (!cancelled) showError(err, "Could not load event categories.", setError);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function openEdit(category: EventCategory) {
    setEditing(category);
    setEditLabel(category.label);
    setEditDescription(category.description ?? "");
    setEditActive(category.is_active ?? true);
    setEditAmount(category.default_estimated_amount ?? "");
    setEditCurrency(category.default_currency ?? "");
  }

  async function handleUpdate() {
    if (!editing) return;
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const updated = await updateEventCategory(org.id, editing.id, {
        label: editLabel.trim(),
        description: editDescription.trim() ? editDescription.trim() : null,
        is_active: editActive,
        default_estimated_amount: editAmount.trim() ? editAmount.trim() : null,
        default_currency: editCurrency.trim()
          ? editCurrency.trim().toUpperCase()
          : null,
      });
      setCategories((current) =>
        current.map((item) => (item.id === editing.id ? updated : item)),
      );
      setNotice(`Category "${updated.label}" updated.`);
      setEditing(null);
    } catch (err) {
      showError(err, "Could not update the category.", setError);
    } finally {
      setUpdating(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteEventCategory(org.id, deleteTarget.id);
      setCategories((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Category "${deleteTarget.label}" deleted.`);
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the category.", setError);
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  if (loading) return <Spinner label="Loading event categories" />;

  return (
    <Card>
      <CardHeader
        title="Event categories"
        description="Categories for shipment events. System categories are built in and cannot be edited or deleted. Your own categories can be renamed, disabled, or given default amounts."
      />
      <CardBody className="space-y-4">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {categories.length === 0 ? (
          <EmptyState
            title="No event categories"
            description="Event categories will appear here."
          />
        ) : (
          <Table headers={["Category", "Type", "Active", "Default amount", ""]}>
            {categories.map((category) => (
              <tr key={category.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">{category.label}</p>
                  {category.description && (
                    <p className="text-xs text-slate-500">{category.description}</p>
                  )}
                </TableCell>
                <TableCell>
                  <Badge tone={category.is_system ? "neutral" : "info"}>
                    {category.is_system ? "System" : "Custom"}
                  </Badge>
                </TableCell>
                <TableCell>
                  <Badge tone={category.is_active ? "success" : "neutral"}>
                    {category.is_active ? "Active" : "Inactive"}
                  </Badge>
                </TableCell>
                <TableCell>
                  {category.default_estimated_amount
                    ? `${category.default_currency ?? "USD"} ${category.default_estimated_amount}`
                    : "Not set"}
                </TableCell>
                <TableCell>
                  {!category.is_system && (
                    <div className="flex gap-1">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(category)}>
                        Edit
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(category)}>
                        Delete
                      </Button>
                    </div>
                  )}
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </CardBody>
      <Modal
        open={editing !== null}
        title="Edit event category"
        description={editing ? `Edit ${editing.label}.` : undefined}
        confirmLabel="Save changes"
        loading={updating}
        onConfirm={handleUpdate}
        onClose={() => setEditing(null)}
      >
        <div className="space-y-4">
          <Input
            id="edit-category-label"
            label="Label"
            type="text"
            value={editLabel}
            onChange={(event) => setEditLabel(event.target.value)}
          />
          <Textarea
            id="edit-category-description"
            label="Description"
            rows={2}
            value={editDescription}
            onChange={(event) => setEditDescription(event.target.value)}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              id="edit-category-amount"
              label="Default estimated amount"
              type="text"
              inputMode="decimal"
              value={editAmount}
              onChange={(event) => setEditAmount(event.target.value)}
              placeholder="0.00"
            />
            <Input
              id="edit-category-currency"
              label="Default currency"
              type="text"
              value={editCurrency}
              onChange={(event) => setEditCurrency(event.target.value)}
              placeholder="USD"
              help="3-letter code."
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={editActive}
              onChange={(event) => setEditActive(event.target.checked)}
              className="h-4 w-4 rounded border-slate-300"
            />
            Active
          </label>
        </div>
      </Modal>
      <Modal
        open={deleteTarget !== null}
        title="Delete event category"
        description={
          deleteTarget
            ? `Delete the category "${deleteTarget.label}"? Categories in use by events cannot be deleted.`
            : undefined
        }
        confirmLabel="Delete category"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </Card>
  );
}

/* ---------------- Evidence templates ---------------- */

function emptyTemplateItem(): EvidenceTemplateItemInput {
  return { document_category: "bill_of_lading", label: "", is_required: true, sort_order: 0 };
}

function TemplateItemsEditor({
  items,
  onChange,
}: {
  items: EvidenceTemplateItemInput[];
  onChange: (items: EvidenceTemplateItemInput[]) => void;
}) {
  function updateItem(index: number, patch: Partial<EvidenceTemplateItemInput>) {
    onChange(items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
  }
  return (
    <div className="space-y-3">
      <p className="text-sm font-medium text-slate-700">Documents in this template</p>
      {items.map((item, index) => (
        <div key={index} className="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-2">
          <Select
            id={`template-item-category-${index}`}
            label="Document category"
            value={item.document_category}
            onChange={(event) =>
              updateItem(index, {
                document_category: event.target.value as DocumentCategory,
              })
            }
          >
            {DOCUMENT_CATEGORIES.map((category) => (
              <option key={category.value} value={category.value}>
                {category.label}
              </option>
            ))}
          </Select>
          <Input
            id={`template-item-label-${index}`}
            label="Label"
            type="text"
            value={item.label}
            onChange={(event) => updateItem(index, { label: event.target.value })}
            placeholder="For example: Signed bill of lading"
          />
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={item.is_required}
              onChange={(event) =>
                updateItem(index, { is_required: event.target.checked })
              }
              className="h-4 w-4 rounded border-slate-300"
            />
            Required
          </label>
          <Input
            id={`template-item-sort-${index}`}
            label="Sort order"
            type="number"
            value={String(item.sort_order ?? 0)}
            onChange={(event) =>
              updateItem(index, { sort_order: Number(event.target.value) || 0 })
            }
          />
          <div>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => onChange(items.filter((_, i) => i !== index))}
            >
              Remove document
            </Button>
          </div>
        </div>
      ))}
      <Button
        variant="secondary"
        size="sm"
        onClick={() => onChange([...items, emptyTemplateItem()])}
      >
        Add document
      </Button>
    </div>
  );
}

function EvidenceTemplatesSection({ org }: { org: Organization }) {
  const [templates, setTemplates] = useState<EvidenceTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [items, setItems] = useState<EvidenceTemplateItemInput[]>([emptyTemplateItem()]);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<EvidenceTemplate | null>(null);
  const [editName, setEditName] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editItems, setEditItems] = useState<EvidenceTemplateItemInput[]>([]);
  const [editActive, setEditActive] = useState(true);
  const [updating, setUpdating] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<EvidenceTemplate | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getEvidenceTemplates(org.id)
      .then((data) => {
        if (!cancelled) setTemplates(data);
      })
      .catch((err) => {
        if (!cancelled) showError(err, "Could not load evidence templates.", setError);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function validateItems(list: EvidenceTemplateItemInput[]): string | null {
    if (list.length === 0) return "Add at least one document to the template.";
    if (list.some((item) => !item.label.trim())) {
      return "Every document in the template needs a label.";
    }
    return null;
  }

  async function handleCreate(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setFormError("Name is required.");
      return;
    }
    const itemsError = validateItems(items);
    if (itemsError) {
      setFormError(itemsError);
      return;
    }
    setFormError(null);
    setError(null);
    setNotice(null);
    setSaving(true);
    try {
      const created = await createEvidenceTemplate(org.id, {
        name: name.trim(),
        description: description.trim() ? description.trim() : null,
        items: items.map((item, index) => ({
          ...item,
          label: item.label.trim(),
          sort_order: item.sort_order ?? index,
        })),
      });
      setTemplates((current) => [created, ...current]);
      setNotice(`Evidence template "${created.name}" added.`);
      setName("");
      setDescription("");
      setItems([emptyTemplateItem()]);
      setShowForm(false);
    } catch (err) {
      showError(err, "Could not add the evidence template.", setError);
    } finally {
      setSaving(false);
    }
  }

  function openEdit(template: EvidenceTemplate) {
    setEditing(template);
    setEditName(template.name);
    setEditDescription(template.description ?? "");
    setEditItems(
      [...template.items]
        .sort((a, b) => a.sort_order - b.sort_order)
        .map((item) => ({
          document_category: item.document_category,
          label: item.label,
          is_required: item.is_required,
          sort_order: item.sort_order,
        })),
    );
    setEditActive(template.is_active);
  }

  async function handleUpdate() {
    if (!editing) return;
    const itemsError = validateItems(editItems);
    if (itemsError) {
      setError(itemsError);
      return;
    }
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const updated = await updateEvidenceTemplate(org.id, editing.id, {
        name: editName.trim(),
        description: editDescription.trim() ? editDescription.trim() : null,
        is_active: editActive,
        items: editItems.map((item, index) => ({
          ...item,
          label: item.label.trim(),
          sort_order: item.sort_order ?? index,
        })),
      });
      setTemplates((current) =>
        current.map((item) => (item.id === editing.id ? updated : item)),
      );
      setNotice(`Evidence template "${updated.name}" updated.`);
      setEditing(null);
    } catch (err) {
      showError(err, "Could not update the evidence template.", setError);
    } finally {
      setUpdating(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteEvidenceTemplate(org.id, deleteTarget.id);
      setTemplates((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Evidence template "${deleteTarget.name}" deleted.`);
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the evidence template.", setError);
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  if (loading) return <Spinner label="Loading evidence templates" />;

  return (
    <Card>
      <CardHeader
        title="Evidence templates"
        description="Reusable document checklists. Apply one to a claim from the claim page to create its evidence requirements."
        action={
          <Button size="sm" onClick={() => setShowForm((show) => !show)}>
            {showForm ? "Hide form" : "Add template"}
          </Button>
        }
      />
      <CardBody className="space-y-4">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {showForm && (
          <form onSubmit={handleCreate} className="space-y-4 border-b border-slate-200 pb-4" noValidate>
            {formError && <Alert tone="error">{formError}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                id="template-name"
                label="Name"
                type="text"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="For example: Detention claim pack"
              />
              <Textarea
                id="template-description"
                label="Description"
                rows={2}
                value={description}
                onChange={(event) => setDescription(event.target.value)}
              />
            </div>
            <TemplateItemsEditor items={items} onChange={setItems} />
            <Button type="submit" loading={saving}>
              Add template
            </Button>
          </form>
        )}
        {templates.length === 0 ? (
          <EmptyState
            title="No evidence templates yet"
            description="Add a template to reuse the same document checklist across claims."
          />
        ) : (
          <Table headers={["Template", "Documents", "Active", ""]}>
            {templates.map((template) => (
              <tr key={template.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">{template.name}</p>
                  {template.description && (
                    <p className="text-xs text-slate-500">{template.description}</p>
                  )}
                </TableCell>
                <TableCell>
                  {template.items.length === 0
                    ? "No documents"
                    : [...template.items]
                        .sort((a, b) => a.sort_order - b.sort_order)
                        .map(
                          (item) =>
                            `${item.label} (${documentCategoryLabel(item.document_category)}${item.is_required ? ", required" : ", optional"})`,
                        )
                        .join("; ")}
                </TableCell>
                <TableCell>
                  <Badge tone={template.is_active ? "success" : "neutral"}>
                    {template.is_active ? "Active" : "Inactive"}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    <Button variant="ghost" size="sm" onClick={() => openEdit(template)}>
                      Edit
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(template)}>
                      Delete
                    </Button>
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </CardBody>
      <Modal
        open={editing !== null}
        title="Edit evidence template"
        description={editing ? `Edit ${editing.name}. Saving replaces its document list with the one below.` : undefined}
        confirmLabel="Save changes"
        loading={updating}
        onConfirm={handleUpdate}
        onClose={() => setEditing(null)}
      >
        <div className="space-y-4">
          <Input
            id="edit-template-name"
            label="Name"
            type="text"
            value={editName}
            onChange={(event) => setEditName(event.target.value)}
          />
          <Textarea
            id="edit-template-description"
            label="Description"
            rows={2}
            value={editDescription}
            onChange={(event) => setEditDescription(event.target.value)}
          />
          <TemplateItemsEditor items={editItems} onChange={setEditItems} />
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={editActive}
              onChange={(event) => setEditActive(event.target.checked)}
              className="h-4 w-4 rounded border-slate-300"
            />
            Active
          </label>
        </div>
      </Modal>
      <Modal
        open={deleteTarget !== null}
        title="Delete evidence template"
        description={
          deleteTarget
            ? `Delete the evidence template "${deleteTarget.name}"? Requirements already created from it are kept.`
            : undefined
        }
        confirmLabel="Delete template"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </Card>
  );
}

/* ---------------- Deadline settings ---------------- */

function DeadlineSettingsSection({ org }: { org: Organization }) {
  const [settings, setSettings] = useState<DeadlineSetting[]>([]);
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [scopeType, setScopeType] = useState<DeadlineScopeType>("global");
  const [scopeValue, setScopeValue] = useState("");
  const [submitWithinDays, setSubmitWithinDays] = useState("30");
  const [reminderDays, setReminderDays] = useState("7, 3, 1");
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<DeadlineSetting | null>(null);
  const [editScopeType, setEditScopeType] = useState<DeadlineScopeType>("global");
  const [editScopeValue, setEditScopeValue] = useState("");
  const [editSubmitWithinDays, setEditSubmitWithinDays] = useState("30");
  const [editReminderDays, setEditReminderDays] = useState("");
  const [updating, setUpdating] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<DeadlineSetting | null>(null);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([getDeadlineSettings(org.id), getEventCategories(org.id)])
      .then(([data, categoryData]) => {
        if (!cancelled) {
          setSettings(data);
          setCategories(categoryData);
        }
      })
      .catch((err) => {
        if (!cancelled) showError(err, "Could not load deadline settings.", setError);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function scopeValueFor(scope: DeadlineScopeType, value: string): string | null {
    return scope === "global" ? null : value ? value : null;
  }

  async function handleCreate(event: FormEvent) {
    event.preventDefault();
    if (scopeType !== "global" && !scopeValue) {
      setFormError("Choose a category for a scoped deadline setting.");
      return;
    }
    const days = Number(submitWithinDays);
    if (!Number.isFinite(days) || days < 0) {
      setFormError("Submit within days must be zero or more.");
      return;
    }
    setFormError(null);
    setError(null);
    setNotice(null);
    setSaving(true);
    try {
      const created = await createDeadlineSetting(org.id, {
        scope_type: scopeType,
        scope_value: scopeValueFor(scopeType, scopeValue),
        submit_within_days: days,
        reminder_days_before: parseReminderDays(reminderDays),
      });
      setSettings((current) => [created, ...current]);
      setNotice("Deadline setting added.");
      setScopeType("global");
      setScopeValue("");
      setSubmitWithinDays("30");
      setReminderDays("7, 3, 1");
      setShowForm(false);
    } catch (err) {
      showError(err, "Could not add the deadline setting.", setError);
    } finally {
      setSaving(false);
    }
  }

  function openEdit(setting: DeadlineSetting) {
    setEditing(setting);
    setEditScopeType(setting.scope_type);
    setEditScopeValue(setting.scope_value ?? "");
    setEditSubmitWithinDays(String(setting.submit_within_days));
    setEditReminderDays((setting.reminder_days_before ?? []).join(", "));
  }

  async function handleUpdate() {
    if (!editing) return;
    if (editScopeType !== "global" && !editScopeValue) {
      setError("Choose a category for a scoped deadline setting.");
      return;
    }
    const days = Number(editSubmitWithinDays);
    if (!Number.isFinite(days) || days < 0) {
      setError("Submit within days must be zero or more.");
      return;
    }
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const updated = await updateDeadlineSetting(org.id, editing.id, {
        scope_type: editScopeType,
        scope_value: scopeValueFor(editScopeType, editScopeValue),
        submit_within_days: days,
        reminder_days_before: parseReminderDays(editReminderDays),
      });
      setSettings((current) =>
        current.map((item) => (item.id === editing.id ? updated : item)),
      );
      setNotice("Deadline setting updated.");
      setEditing(null);
    } catch (err) {
      showError(err, "Could not update the deadline setting.", setError);
    } finally {
      setUpdating(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteDeadlineSetting(org.id, deleteTarget.id);
      setSettings((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice("Deadline setting deleted.");
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the deadline setting.", setError);
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  function scopeValueSelect(
    id: string,
    scope: DeadlineScopeType,
    value: string,
    onChange: (value: string) => void,
  ) {
    if (scope === "claim_category") {
      return (
        <Select id={id} label="Claim category" value={value} onChange={(event) => onChange(event.target.value)}>
          <option value="">Choose a category</option>
          {CLAIM_CATEGORIES.map((item) => (
            <option key={item.value} value={item.value}>
              {item.label}
            </option>
          ))}
        </Select>
      );
    }
    if (scope === "event_category") {
      return (
        <Select id={id} label="Event category" value={value} onChange={(event) => onChange(event.target.value)}>
          <option value="">Choose a category</option>
          {categories.map((category) => (
            <option key={category.id} value={category.slug}>
              {category.label}
            </option>
          ))}
        </Select>
      );
    }
    return null;
  }

  if (loading) return <Spinner label="Loading deadline settings" />;

  return (
    <Card>
      <CardHeader
        title="Deadline settings"
        description="Default submission windows for claims. The most specific matching setting wins: a category setting beats the global one."
        action={
          <Button size="sm" onClick={() => setShowForm((show) => !show)}>
            {showForm ? "Hide form" : "Add deadline setting"}
          </Button>
        }
      />
      <CardBody className="space-y-4">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {showForm && (
          <form onSubmit={handleCreate} className="space-y-4 border-b border-slate-200 pb-4" noValidate>
            {formError && <Alert tone="error">{formError}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <Select
                id="deadline-scope-type"
                label="Applies to"
                value={scopeType}
                onChange={(event) => {
                  setScopeType(event.target.value as DeadlineScopeType);
                  setScopeValue("");
                }}
              >
                {SCOPE_TYPES.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </Select>
              {scopeValueSelect("deadline-scope-value", scopeType, scopeValue, setScopeValue)}
              <Input
                id="deadline-submit-within"
                label="Submit within (days)"
                type="number"
                value={submitWithinDays}
                onChange={(event) => setSubmitWithinDays(event.target.value)}
              />
              <Input
                id="deadline-reminders"
                label="Reminder days before"
                type="text"
                value={reminderDays}
                onChange={(event) => setReminderDays(event.target.value)}
                placeholder="7, 3, 1"
                help="Comma-separated days before the deadline. Leave blank for no reminders."
              />
            </div>
            <Button type="submit" loading={saving}>
              Add deadline setting
            </Button>
          </form>
        )}
        {settings.length === 0 ? (
          <EmptyState
            title="No deadline settings yet"
            description="Add a global setting to give every claim a default submission window."
          />
        ) : (
          <Table headers={["Applies to", "Submit within", "Reminders", ""]}>
            {settings.map((setting) => (
              <tr key={setting.id}>
                <TableCell>{scopeLabel(setting)}</TableCell>
                <TableCell>
                  {setting.submit_within_days} day{setting.submit_within_days === 1 ? "" : "s"}
                </TableCell>
                <TableCell>
                  {(setting.reminder_days_before ?? []).length === 0
                    ? "None"
                    : setting.reminder_days_before
                        ?.map((day) => `${day} day${day === 1 ? "" : "s"} before`)
                        .join(", ")}
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    <Button variant="ghost" size="sm" onClick={() => openEdit(setting)}>
                      Edit
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(setting)}>
                      Delete
                    </Button>
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </CardBody>
      <Modal
        open={editing !== null}
        title="Edit deadline setting"
        confirmLabel="Save changes"
        loading={updating}
        onConfirm={handleUpdate}
        onClose={() => setEditing(null)}
      >
        <div className="space-y-4">
          <Select
            id="edit-deadline-scope-type"
            label="Applies to"
            value={editScopeType}
            onChange={(event) => {
              setEditScopeType(event.target.value as DeadlineScopeType);
              setEditScopeValue("");
            }}
          >
            {SCOPE_TYPES.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </Select>
          {scopeValueSelect("edit-deadline-scope-value", editScopeType, editScopeValue, setEditScopeValue)}
          <Input
            id="edit-deadline-submit-within"
            label="Submit within (days)"
            type="number"
            value={editSubmitWithinDays}
            onChange={(event) => setEditSubmitWithinDays(event.target.value)}
          />
          <Input
            id="edit-deadline-reminders"
            label="Reminder days before"
            type="text"
            value={editReminderDays}
            onChange={(event) => setEditReminderDays(event.target.value)}
            placeholder="7, 3, 1"
            help="Comma-separated days before the deadline. Leave blank for no reminders."
          />
        </div>
      </Modal>
      <Modal
        open={deleteTarget !== null}
        title="Delete deadline setting"
        description={
          deleteTarget
            ? `Delete the deadline setting for ${scopeLabel(deleteTarget).toLowerCase()}?`
            : undefined
        }
        confirmLabel="Delete setting"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </Card>
  );
}

/* ---------------- Export templates ---------------- */

function ExportTemplatesSection({ org }: { org: Organization }) {
  const [resourceType, setResourceType] =
    useState<CustomFieldResourceType>("shipment");
  const [templates, setTemplates] = useState<ExportTemplate[]>([]);
  const [columns, setColumns] = useState<AvailableExportColumn[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [name, setName] = useState("");
  const [selectedColumns, setSelectedColumns] = useState<string[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<ExportTemplate | null>(null);
  const [editName, setEditName] = useState("");
  const [editColumns, setEditColumns] = useState<string[]>([]);
  const [updating, setUpdating] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<ExportTemplate | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [settingDefaultId, setSettingDefaultId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      getExportTemplates(org.id, resourceType),
      getAvailableExportColumns(org.id, resourceType),
    ])
      .then(([templateData, columnData]) => {
        if (!cancelled) {
          setTemplates(templateData);
          setColumns(columnData);
        }
      })
      .catch((err) => {
        if (!cancelled) showError(err, "Could not load export templates.", setError);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, resourceType]);

  function toggleColumn(list: string[], key: string, onChange: (next: string[]) => void) {
    onChange(
      list.includes(key) ? list.filter((item) => item !== key) : [...list, key],
    );
  }

  async function handleCreate(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setFormError("Name is required.");
      return;
    }
    if (selectedColumns.length === 0) {
      setFormError("Pick at least one column.");
      return;
    }
    setFormError(null);
    setError(null);
    setNotice(null);
    setSaving(true);
    try {
      const created = await createExportTemplate(org.id, {
        resource_type: resourceType,
        name: name.trim(),
        columns: selectedColumns,
      });
      setTemplates((current) => [created, ...current]);
      setNotice(`Export template "${created.name}" added.`);
      setName("");
      setSelectedColumns([]);
      setShowForm(false);
    } catch (err) {
      showError(err, "Could not add the export template.", setError);
    } finally {
      setSaving(false);
    }
  }

  function openEdit(template: ExportTemplate) {
    setEditing(template);
    setEditName(template.name);
    setEditColumns(template.columns);
  }

  async function handleUpdate() {
    if (!editing) return;
    if (editColumns.length === 0) {
      setError("Pick at least one column.");
      return;
    }
    setError(null);
    setNotice(null);
    setUpdating(true);
    try {
      const updated = await updateExportTemplate(org.id, editing.id, {
        name: editName.trim(),
        columns: editColumns,
      });
      setTemplates((current) =>
        current.map((item) => (item.id === editing.id ? updated : item)),
      );
      setNotice(`Export template "${updated.name}" updated.`);
      setEditing(null);
    } catch (err) {
      showError(err, "Could not update the export template.", setError);
    } finally {
      setUpdating(false);
    }
  }

  async function handleSetDefault(template: ExportTemplate) {
    setError(null);
    setNotice(null);
    setSettingDefaultId(template.id);
    try {
      const updated = await setDefaultExportTemplate(org.id, template.id);
      setTemplates((current) =>
        current.map((item) =>
          item.id === updated.id ? updated : { ...item, is_default: false },
        ),
      );
      setNotice(`"${updated.name}" is now the default export template.`);
    } catch (err) {
      showError(err, "Could not set the default export template.", setError);
    } finally {
      setSettingDefaultId(null);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteExportTemplate(org.id, deleteTarget.id);
      setTemplates((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Export template "${deleteTarget.name}" deleted.`);
      setDeleteTarget(null);
    } catch (err) {
      showError(err, "Could not delete the export template.", setError);
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  return (
    <Card>
      <CardHeader
        title="Export templates"
        description="Saved column sets for CSV exports. The default template is used when no template is picked on the list page."
        action={
          <Button size="sm" onClick={() => setShowForm((show) => !show)}>
            {showForm ? "Hide form" : "Add export template"}
          </Button>
        }
      />
      <CardBody className="space-y-4">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        <Select
          id="export-resource"
          label="Applies to"
          value={resourceType}
          onChange={(event) => {
            setLoading(true);
            setResourceType(event.target.value as CustomFieldResourceType);
            setShowForm(false);
            setName("");
            setSelectedColumns([]);
          }}
        >
          <option value="shipment">Shipments</option>
          <option value="claim">Claims</option>
        </Select>
        {showForm && (
          <form onSubmit={handleCreate} className="space-y-4 border-b border-slate-200 pb-4" noValidate>
            {formError && <Alert tone="error">{formError}</Alert>}
            <Input
              id="export-template-name"
              label="Name"
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="For example: Broker summary"
            />
            <fieldset>
              <legend className="mb-1 block text-sm font-medium text-slate-700">
                Columns
              </legend>
              <div className="flex flex-wrap gap-4">
                {columns.map((column) => (
                  <label key={column.key} className="flex items-center gap-1.5 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={selectedColumns.includes(column.key)}
                      onChange={() =>
                        toggleColumn(selectedColumns, column.key, setSelectedColumns)
                      }
                      className="h-4 w-4 rounded border-slate-300"
                    />
                    {column.label}
                  </label>
                ))}
              </div>
            </fieldset>
            <Button type="submit" loading={saving}>
              Add export template
            </Button>
          </form>
        )}
        {loading ? (
          <Spinner label="Loading export templates" />
        ) : templates.length === 0 ? (
          <EmptyState
            title="No export templates yet"
            description={`Add a template to choose which columns ${resourceType === "shipment" ? "shipment" : "claim"} exports include.`}
          />
        ) : (
          <Table headers={["Template", "Columns", "Default", ""]}>
            {templates.map((template) => (
              <tr key={template.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">{template.name}</p>
                </TableCell>
                <TableCell>
                  {template.columns
                    .map(
                      (key) =>
                        columns.find((column) => column.key === key)?.label ?? key,
                    )
                    .join(", ")}
                </TableCell>
                <TableCell>
                  {template.is_default ? (
                    <Badge tone="success">Default</Badge>
                  ) : (
                    <Button
                      variant="ghost"
                      size="sm"
                      loading={settingDefaultId === template.id}
                      onClick={() => handleSetDefault(template)}
                    >
                      Set as default
                    </Button>
                  )}
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    <Button variant="ghost" size="sm" onClick={() => openEdit(template)}>
                      Edit
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(template)}>
                      Delete
                    </Button>
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </CardBody>
      <Modal
        open={editing !== null}
        title="Edit export template"
        description={editing ? `Edit ${editing.name}.` : undefined}
        confirmLabel="Save changes"
        loading={updating}
        onConfirm={handleUpdate}
        onClose={() => setEditing(null)}
      >
        <div className="space-y-4">
          <Input
            id="edit-export-template-name"
            label="Name"
            type="text"
            value={editName}
            onChange={(event) => setEditName(event.target.value)}
          />
          <fieldset>
            <legend className="mb-1 block text-sm font-medium text-slate-700">
              Columns
            </legend>
            <div className="flex flex-wrap gap-4">
              {columns.map((column) => (
                <label key={column.key} className="flex items-center gap-1.5 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={editColumns.includes(column.key)}
                    onChange={() =>
                      toggleColumn(editColumns, column.key, setEditColumns)
                    }
                    className="h-4 w-4 rounded border-slate-300"
                  />
                  {column.label}
                </label>
              ))}
            </div>
          </fieldset>
        </div>
      </Modal>
      <Modal
        open={deleteTarget !== null}
        title="Delete export template"
        description={
          deleteTarget
            ? `Delete the export template "${deleteTarget.name}"?`
            : undefined
        }
        confirmLabel="Delete template"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
    </Card>
  );
}

/* ---------------- Page ---------------- */

function CustomizationContent({ org }: { org: Organization }) {
  if (!canManageCustomization(org.current_user_role)) {
    return (
      <Alert tone="info">
        Only owners, admins, and operations managers can change
        customization settings.
      </Alert>
    );
  }
  return (
    <div className="space-y-6">
      <CustomFieldsSection org={org} />
      <EventCategoriesSection org={org} />
      <EvidenceTemplatesSection org={org} />
      <DeadlineSettingsSection org={org} />
      <ExportTemplatesSection org={org} />
    </div>
  );
}

export default function CustomizationSettingsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Customization"
        description={
          currentOrg
            ? `Custom fields, categories, templates, and defaults for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Organization settings", href: "/settings/organization" },
          { label: "Customization" },
        ]}
      />
      {currentOrg ? (
        <CustomizationContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to manage customization settings.
        </Alert>
      )}
    </div>
  );
}
