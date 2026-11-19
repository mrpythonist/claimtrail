import { apiDownload, apiFetch } from "./api";
import type {
  AvailableExportColumn,
  CustomField,
  CustomFieldResourceType,
  CustomFieldType,
  CustomFieldValues,
  DeadlineScopeType,
  DeadlineSetting,
  EventCategory,
  EvidenceTemplate,
  ExportTemplate,
} from "./types";

/**
 * Typed client for the customization endpoints (GET /custom-fields,
 * /event-categories, /evidence-templates, /deadline-settings,
 * /export-templates, /claims/export, /shipments/export). Every call
 * is tenant-scoped: apiFetch attaches the stored organization id,
 * and each function here takes the org id explicitly so pages pass
 * the current organization rather than relying on stored state.
 * The backend requires the manage-customization permission (Owner,
 * Admin, Operations Manager) for the write endpoints; the UI gates
 * on canManageCustomization and still handles 403 responses
 * gracefully.
 */

export interface CustomFieldInput {
  resource_type: CustomFieldResourceType;
  label: string;
  field_type: CustomFieldType;
  is_required?: boolean;
  options?: string[] | null;
  sort_order?: number;
  field_key?: string;
}

export interface CustomFieldUpdate {
  label?: string;
  is_required?: boolean;
  options?: string[] | null;
  sort_order?: number;
  is_active?: boolean;
}

export async function getCustomFields(
  orgId: string,
  resourceType: CustomFieldResourceType,
): Promise<CustomField[]> {
  const params = new URLSearchParams({ resource_type: resourceType });
  const response = await apiFetch<{ data: CustomField[] }>(
    `/custom-fields?${params.toString()}`,
    { orgId },
  );
  return response.data;
}

export async function getCustomField(
  orgId: string,
  id: string,
): Promise<CustomField> {
  const response = await apiFetch<{ data: CustomField }>(
    `/custom-fields/${encodeURIComponent(id)}`,
    { orgId },
  );
  return response.data;
}

export async function createCustomField(
  orgId: string,
  input: CustomFieldInput,
): Promise<CustomField> {
  const response = await apiFetch<{ data: CustomField }>("/custom-fields", {
    method: "POST",
    body: input,
    orgId,
  });
  return response.data;
}

export async function updateCustomField(
  orgId: string,
  id: string,
  input: CustomFieldUpdate,
): Promise<CustomField> {
  const response = await apiFetch<{ data: CustomField }>(
    `/custom-fields/${encodeURIComponent(id)}`,
    { method: "PUT", body: input, orgId },
  );
  return response.data;
}

export async function deleteCustomField(
  orgId: string,
  id: string,
): Promise<void> {
  await apiFetch(`/custom-fields/${encodeURIComponent(id)}`, {
    method: "DELETE",
    orgId,
  });
}

export async function getEventCategories(orgId: string): Promise<EventCategory[]> {
  const response = await apiFetch<{ data: EventCategory[] }>(
    "/event-categories",
    { orgId },
  );
  return response.data;
}

export interface EventCategoryUpdate {
  label?: string;
  description?: string | null;
  is_active?: boolean;
  default_estimated_amount?: string | null;
  default_currency?: string | null;
}

export async function updateEventCategory(
  orgId: string,
  id: string,
  input: EventCategoryUpdate,
): Promise<EventCategory> {
  const response = await apiFetch<{ data: EventCategory }>(
    `/event-categories/${encodeURIComponent(id)}`,
    { method: "PUT", body: input, orgId },
  );
  return response.data;
}

export async function deleteEventCategory(
  orgId: string,
  id: string,
): Promise<void> {
  await apiFetch(`/event-categories/${encodeURIComponent(id)}`, {
    method: "DELETE",
    orgId,
  });
}

export interface EvidenceTemplateItemInput {
  document_category: string;
  label: string;
  is_required?: boolean;
  sort_order?: number;
}

export interface EvidenceTemplateInput {
  name: string;
  description?: string | null;
  items: EvidenceTemplateItemInput[];
}

export interface EvidenceTemplateUpdate {
  name?: string;
  description?: string | null;
  is_active?: boolean;
  items?: EvidenceTemplateItemInput[];
}

export async function getEvidenceTemplates(
  orgId: string,
): Promise<EvidenceTemplate[]> {
  const response = await apiFetch<{ data: EvidenceTemplate[] }>(
    "/evidence-templates",
    { orgId },
  );
  return response.data;
}

export async function createEvidenceTemplate(
  orgId: string,
  input: EvidenceTemplateInput,
): Promise<EvidenceTemplate> {
  const response = await apiFetch<{ data: EvidenceTemplate }>(
    "/evidence-templates",
    { method: "POST", body: input, orgId },
  );
  return response.data;
}

export async function updateEvidenceTemplate(
  orgId: string,
  id: string,
  input: EvidenceTemplateUpdate,
): Promise<EvidenceTemplate> {
  const response = await apiFetch<{ data: EvidenceTemplate }>(
    `/evidence-templates/${encodeURIComponent(id)}`,
    { method: "PUT", body: input, orgId },
  );
  return response.data;
}

export async function deleteEvidenceTemplate(
  orgId: string,
  id: string,
): Promise<void> {
  await apiFetch(`/evidence-templates/${encodeURIComponent(id)}`, {
    method: "DELETE",
    orgId,
  });
}

export interface ApplyTemplateResult {
  created: number;
  skipped: number;
  template_id: string;
  claim_id: string;
}

export async function applyEvidenceTemplateToClaim(
  orgId: string,
  templateId: string,
  claimId: string,
): Promise<ApplyTemplateResult> {
  const response = await apiFetch<{ data: ApplyTemplateResult }>(
    `/evidence-templates/${encodeURIComponent(templateId)}/apply-to-claim/${encodeURIComponent(claimId)}`,
    { method: "POST", orgId },
  );
  return response.data;
}

export interface DeadlineSettingInput {
  scope_type: DeadlineScopeType;
  scope_value?: string | null;
  submit_within_days: number;
  reminder_days_before?: number[] | null;
}

export interface DeadlineSettingUpdate {
  scope_type?: DeadlineScopeType;
  scope_value?: string | null;
  submit_within_days?: number;
  reminder_days_before?: number[] | null;
}

export async function getDeadlineSettings(
  orgId: string,
): Promise<DeadlineSetting[]> {
  const response = await apiFetch<{ data: DeadlineSetting[] }>(
    "/deadline-settings",
    { orgId },
  );
  return response.data;
}

export async function createDeadlineSetting(
  orgId: string,
  input: DeadlineSettingInput,
): Promise<DeadlineSetting> {
  const response = await apiFetch<{ data: DeadlineSetting }>(
    "/deadline-settings",
    { method: "POST", body: input, orgId },
  );
  return response.data;
}

export async function updateDeadlineSetting(
  orgId: string,
  id: string,
  input: DeadlineSettingUpdate,
): Promise<DeadlineSetting> {
  const response = await apiFetch<{ data: DeadlineSetting }>(
    `/deadline-settings/${encodeURIComponent(id)}`,
    { method: "PUT", body: input, orgId },
  );
  return response.data;
}

export async function deleteDeadlineSetting(
  orgId: string,
  id: string,
): Promise<void> {
  await apiFetch(`/deadline-settings/${encodeURIComponent(id)}`, {
    method: "DELETE",
    orgId,
  });
}

export async function resolveDeadlineSetting(
  orgId: string,
  filters: { claim_category?: string; event_category?: string },
): Promise<DeadlineSetting | null> {
  const params = new URLSearchParams();
  if (filters.claim_category) params.set("claim_category", filters.claim_category);
  if (filters.event_category) params.set("event_category", filters.event_category);
  const query = params.toString();
  const response = await apiFetch<{ data: DeadlineSetting | null }>(
    `/deadline-settings/resolve${query ? `?${query}` : ""}`,
    { orgId },
  );
  return response.data;
}

export interface ExportTemplateInput {
  resource_type: CustomFieldResourceType;
  name: string;
  columns: string[];
  is_default?: boolean;
}

export interface ExportTemplateUpdate {
  name?: string;
  columns?: string[];
  is_default?: boolean;
}

export async function getExportTemplates(
  orgId: string,
  resourceType: CustomFieldResourceType,
): Promise<ExportTemplate[]> {
  const params = new URLSearchParams({ resource_type: resourceType });
  const response = await apiFetch<{ data: ExportTemplate[] }>(
    `/export-templates?${params.toString()}`,
    { orgId },
  );
  return response.data;
}

export async function getAvailableExportColumns(
  orgId: string,
  resourceType: CustomFieldResourceType,
): Promise<AvailableExportColumn[]> {
  const params = new URLSearchParams({ resource_type: resourceType });
  const response = await apiFetch<{ data: AvailableExportColumn[] }>(
    `/export-templates/available-columns?${params.toString()}`,
    { orgId },
  );
  return response.data;
}

export async function createExportTemplate(
  orgId: string,
  input: ExportTemplateInput,
): Promise<ExportTemplate> {
  const response = await apiFetch<{ data: ExportTemplate }>(
    "/export-templates",
    { method: "POST", body: input, orgId },
  );
  return response.data;
}

export async function updateExportTemplate(
  orgId: string,
  id: string,
  input: ExportTemplateUpdate,
): Promise<ExportTemplate> {
  const response = await apiFetch<{ data: ExportTemplate }>(
    `/export-templates/${encodeURIComponent(id)}`,
    { method: "PUT", body: input, orgId },
  );
  return response.data;
}

export async function deleteExportTemplate(
  orgId: string,
  id: string,
): Promise<void> {
  await apiFetch(`/export-templates/${encodeURIComponent(id)}`, {
    method: "DELETE",
    orgId,
  });
}

export async function setDefaultExportTemplate(
  orgId: string,
  id: string,
): Promise<ExportTemplate> {
  const response = await apiFetch<{ data: ExportTemplate }>(
    `/export-templates/${encodeURIComponent(id)}/set-default`,
    { method: "POST", orgId },
  );
  return response.data;
}

/**
 * Downloads the shipments CSV. `query` carries the list filters
 * (for example "status=planned"); `exportTemplateId` adds the
 * chosen export template when one is selected.
 */
export async function exportShipmentsCsv(
  orgId: string,
  query = "",
  exportTemplateId?: string,
): Promise<Blob> {
  const params = new URLSearchParams(query);
  if (exportTemplateId) params.set("export_template_id", exportTemplateId);
  const suffix = params.toString();
  return apiDownload(`/shipments/export${suffix ? `?${suffix}` : ""}`, {
    orgId,
  });
}

/** Downloads the claims CSV, with the same filter/template options. */
export async function exportClaimsCsv(
  orgId: string,
  query = "",
  exportTemplateId?: string,
): Promise<Blob> {
  const params = new URLSearchParams(query);
  if (exportTemplateId) params.set("export_template_id", exportTemplateId);
  const suffix = params.toString();
  return apiDownload(`/claims/export${suffix ? `?${suffix}` : ""}`, {
    orgId,
  });
}

/**
 * Pure helpers, kept here so they are unit-testable without a
 * browser. The select-type options editor stores options as a
 * comma-separated string; this parses it back to an array.
 */
export function parseSelectOptions(value: string): string[] {
  return value
    .split(",")
    .map((option) => option.trim())
    .filter((option) => option !== "");
}

/** Joins select options for the comma-separated editor. */
export function formatSelectOptions(options: string[] | null): string {
  return (options ?? []).join(", ");
}

/** Human-readable value for a custom field on a detail page. */
export function formatCustomFieldValue(
  field: CustomField,
  value: string | number | boolean | null | undefined,
): string {
  if (value === null || value === undefined || value === "") return "Not set";
  if (field.field_type === "boolean") return value === true ? "Yes" : "No";
  if (field.field_type === "date" && typeof value === "string") {
    const parsed = new Date(value);
    if (!Number.isNaN(parsed.getTime())) return parsed.toLocaleDateString();
  }
  return String(value);
}

/**
 * Client-side validation for required custom fields. Returns
 * errors keyed by `custom_field_values.<field_key>`, matching the
 * shape the backend returns for the same fields.
 */
export function validateCustomFieldValues(
  fields: CustomField[],
  values: CustomFieldValues,
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of fields) {
    if (!field.is_active || !field.is_required) continue;
    const value = values[field.field_key];
    const empty =
      value === null ||
      value === undefined ||
      value === "" ||
      value === false;
    if (empty) {
      errors[`custom_field_values.${field.field_key}`] =
        `${field.label} is required.`;
    }
  }
  return errors;
}

/** Drops empty custom field values so the payload only carries what was set. */
export function cleanCustomFieldValues(
  values: CustomFieldValues,
): CustomFieldValues {
  const cleaned: CustomFieldValues = {};
  for (const [key, value] of Object.entries(values)) {
    if (value === null || value === undefined || value === "") continue;
    cleaned[key] = value;
  }
  return cleaned;
}
