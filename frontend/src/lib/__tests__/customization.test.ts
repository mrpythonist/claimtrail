import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiDownload, apiFetch } from "@/lib/api";
import {
  applyEvidenceTemplateToClaim,
  cleanCustomFieldValues,
  createCustomField,
  createDeadlineSetting,
  createEvidenceTemplate,
  createExportTemplate,
  deleteCustomField,
  deleteDeadlineSetting,
  deleteEvidenceTemplate,
  deleteEventCategory,
  deleteExportTemplate,
  exportClaimsCsv,
  exportShipmentsCsv,
  formatCustomFieldValue,
  formatSelectOptions,
  getAvailableExportColumns,
  getCustomField,
  getCustomFields,
  getDeadlineSettings,
  getEvidenceTemplates,
  getEventCategories,
  getExportTemplates,
  parseSelectOptions,
  resolveDeadlineSetting,
  setDefaultExportTemplate,
  updateCustomField,
  updateDeadlineSetting,
  updateEvidenceTemplate,
  updateEventCategory,
  updateExportTemplate,
  validateCustomFieldValues,
} from "../customization";
import type { CustomField } from "../types";

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn(),
  apiDownload: vi.fn(),
  ApiRequestError: class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  },
}));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedApiDownload = vi.mocked(apiDownload);

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiDownload.mockReset();
});

function field(overrides: Partial<CustomField> = {}): CustomField {
  return {
    id: "field-1",
    organization_id: "org-1",
    resource_type: "shipment",
    field_key: "trailer_number",
    label: "Trailer number",
    field_type: "text",
    is_required: false,
    options: null,
    sort_order: 0,
    is_active: true,
    created_at: "2026-11-19T00:00:00Z",
    updated_at: "2026-11-19T00:00:00Z",
    ...overrides,
  };
}

describe("getCustomFields", () => {
  it("calls /custom-fields with the resource type and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: [field()] });
    const result = await getCustomFields("org-1", "shipment");
    expect(result).toEqual([field()]);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/custom-fields?resource_type=shipment",
      { orgId: "org-1" },
    );
  });

  it("passes the claim resource type", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getCustomFields("org-1", "claim");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/custom-fields?resource_type=claim",
      { orgId: "org-1" },
    );
  });
});

describe("getCustomField", () => {
  it("calls /custom-fields/{id} and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: field() });
    const result = await getCustomField("org-1", "field-1");
    expect(result).toEqual(field());
    expect(mockedApiFetch).toHaveBeenCalledWith("/custom-fields/field-1", {
      orgId: "org-1",
    });
  });
});

describe("createCustomField", () => {
  it("POSTs the field and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: field() });
    const result = await createCustomField("org-1", {
      resource_type: "shipment",
      label: "Trailer number",
      field_type: "text",
      is_required: true,
    });
    expect(result).toEqual(field());
    expect(mockedApiFetch).toHaveBeenCalledWith("/custom-fields", {
      method: "POST",
      body: {
        resource_type: "shipment",
        label: "Trailer number",
        field_type: "text",
        is_required: true,
      },
      orgId: "org-1",
    });
  });
});

describe("updateCustomField", () => {
  it("PUTs the changed fields and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: field({ label: "Trailer No." }) });
    const result = await updateCustomField("org-1", "field-1", {
      label: "Trailer No.",
      is_active: false,
    });
    expect(result.label).toBe("Trailer No.");
    expect(mockedApiFetch).toHaveBeenCalledWith("/custom-fields/field-1", {
      method: "PUT",
      body: { label: "Trailer No.", is_active: false },
      orgId: "org-1",
    });
  });
});

describe("deleteCustomField", () => {
  it("DELETEs the field", async () => {
    mockedApiFetch.mockResolvedValue({});
    await deleteCustomField("org-1", "field-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/custom-fields/field-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });
});

describe("event categories", () => {
  it("getEventCategories calls /event-categories and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getEventCategories("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/event-categories", {
      orgId: "org-1",
    });
  });

  it("updateEventCategory PUTs the changes", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "cat-1", label: "Renamed" } });
    await updateEventCategory("org-1", "cat-1", {
      label: "Renamed",
      is_active: false,
      default_estimated_amount: "150.00",
      default_currency: "USD",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/event-categories/cat-1", {
      method: "PUT",
      body: {
        label: "Renamed",
        is_active: false,
        default_estimated_amount: "150.00",
        default_currency: "USD",
      },
      orgId: "org-1",
    });
  });

  it("deleteEventCategory DELETEs the category", async () => {
    mockedApiFetch.mockResolvedValue({});
    await deleteEventCategory("org-1", "cat-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/event-categories/cat-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });
});

describe("evidence templates", () => {
  it("getEvidenceTemplates calls /evidence-templates and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getEvidenceTemplates("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/evidence-templates", {
      orgId: "org-1",
    });
  });

  it("createEvidenceTemplate POSTs the template with its items", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "tpl-1" } });
    await createEvidenceTemplate("org-1", {
      name: "Detention pack",
      items: [
        { document_category: "bill_of_lading", label: "BOL", is_required: true },
      ],
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/evidence-templates", {
      method: "POST",
      body: {
        name: "Detention pack",
        items: [
          { document_category: "bill_of_lading", label: "BOL", is_required: true },
        ],
      },
      orgId: "org-1",
    });
  });

  it("updateEvidenceTemplate PUTs the template", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "tpl-1" } });
    await updateEvidenceTemplate("org-1", "tpl-1", { is_active: false });
    expect(mockedApiFetch).toHaveBeenCalledWith("/evidence-templates/tpl-1", {
      method: "PUT",
      body: { is_active: false },
      orgId: "org-1",
    });
  });

  it("deleteEvidenceTemplate DELETEs the template", async () => {
    mockedApiFetch.mockResolvedValue({});
    await deleteEvidenceTemplate("org-1", "tpl-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/evidence-templates/tpl-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });

  it("applyEvidenceTemplateToClaim POSTs to the apply endpoint and unwraps counts", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { created: 2, skipped: 1, template_id: "tpl-1", claim_id: "claim-1" },
    });
    const result = await applyEvidenceTemplateToClaim("org-1", "tpl-1", "claim-1");
    expect(result).toEqual({
      created: 2,
      skipped: 1,
      template_id: "tpl-1",
      claim_id: "claim-1",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/evidence-templates/tpl-1/apply-to-claim/claim-1",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("deadline settings", () => {
  it("getDeadlineSettings calls /deadline-settings and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getDeadlineSettings("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/deadline-settings", {
      orgId: "org-1",
    });
  });

  it("createDeadlineSetting POSTs the setting", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "ds-1" } });
    await createDeadlineSetting("org-1", {
      scope_type: "claim_category",
      scope_value: "detention",
      submit_within_days: 30,
      reminder_days_before: [7, 3, 1],
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/deadline-settings", {
      method: "POST",
      body: {
        scope_type: "claim_category",
        scope_value: "detention",
        submit_within_days: 30,
        reminder_days_before: [7, 3, 1],
      },
      orgId: "org-1",
    });
  });

  it("updateDeadlineSetting PUTs the setting", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "ds-1" } });
    await updateDeadlineSetting("org-1", "ds-1", { submit_within_days: 14 });
    expect(mockedApiFetch).toHaveBeenCalledWith("/deadline-settings/ds-1", {
      method: "PUT",
      body: { submit_within_days: 14 },
      orgId: "org-1",
    });
  });

  it("deleteDeadlineSetting DELETEs the setting", async () => {
    mockedApiFetch.mockResolvedValue({});
    await deleteDeadlineSetting("org-1", "ds-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/deadline-settings/ds-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });

  it("resolveDeadlineSetting passes both filters", async () => {
    mockedApiFetch.mockResolvedValue({ data: null });
    const result = await resolveDeadlineSetting("org-1", {
      claim_category: "detention",
      event_category: "detention",
    });
    expect(result).toBeNull();
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/deadline-settings/resolve?claim_category=detention&event_category=detention",
      { orgId: "org-1" },
    );
  });

  it("resolveDeadlineSetting works with no filters", async () => {
    mockedApiFetch.mockResolvedValue({ data: null });
    await resolveDeadlineSetting("org-1", {});
    expect(mockedApiFetch).toHaveBeenCalledWith("/deadline-settings/resolve", {
      orgId: "org-1",
    });
  });
});

describe("export templates", () => {
  it("getExportTemplates calls /export-templates with the resource type", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getExportTemplates("org-1", "claim");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/export-templates?resource_type=claim",
      { orgId: "org-1" },
    );
  });

  it("getAvailableExportColumns calls the available-columns endpoint", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await getAvailableExportColumns("org-1", "shipment");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/export-templates/available-columns?resource_type=shipment",
      { orgId: "org-1" },
    );
  });

  it("createExportTemplate POSTs the template", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "et-1" } });
    await createExportTemplate("org-1", {
      resource_type: "shipment",
      name: "Broker summary",
      columns: ["internal_reference", "status"],
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/export-templates", {
      method: "POST",
      body: {
        resource_type: "shipment",
        name: "Broker summary",
        columns: ["internal_reference", "status"],
      },
      orgId: "org-1",
    });
  });

  it("updateExportTemplate PUTs the template", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "et-1" } });
    await updateExportTemplate("org-1", "et-1", { name: "Renamed" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/export-templates/et-1", {
      method: "PUT",
      body: { name: "Renamed" },
      orgId: "org-1",
    });
  });

  it("deleteExportTemplate DELETEs the template", async () => {
    mockedApiFetch.mockResolvedValue({});
    await deleteExportTemplate("org-1", "et-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/export-templates/et-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });

  it("setDefaultExportTemplate POSTs to set-default and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "et-1", is_default: true } });
    const result = await setDefaultExportTemplate("org-1", "et-1");
    expect(result).toEqual({ id: "et-1", is_default: true });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/export-templates/et-1/set-default",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("CSV exports", () => {
  it("exportShipmentsCsv calls /shipments/export without a template", async () => {
    mockedApiDownload.mockResolvedValue(new Blob(["a,b"]));
    await exportShipmentsCsv("org-1", "status=planned");
    expect(mockedApiDownload).toHaveBeenCalledWith(
      "/shipments/export?status=planned",
      { orgId: "org-1" },
    );
  });

  it("exportShipmentsCsv adds export_template_id when given", async () => {
    mockedApiDownload.mockResolvedValue(new Blob(["a,b"]));
    await exportShipmentsCsv("org-1", "status=planned", "et-1");
    expect(mockedApiDownload).toHaveBeenCalledWith(
      "/shipments/export?status=planned&export_template_id=et-1",
      { orgId: "org-1" },
    );
  });

  it("exportClaimsCsv calls /claims/export with the template", async () => {
    mockedApiDownload.mockResolvedValue(new Blob(["a,b"]));
    await exportClaimsCsv("org-1", "", "et-2");
    expect(mockedApiDownload).toHaveBeenCalledWith(
      "/claims/export?export_template_id=et-2",
      { orgId: "org-1" },
    );
  });
});

describe("parseSelectOptions", () => {
  it("splits on commas and trims whitespace", () => {
    expect(parseSelectOptions(" One , Two,Three ")).toEqual([
      "One",
      "Two",
      "Three",
    ]);
  });

  it("drops empty entries", () => {
    expect(parseSelectOptions("One,, ,Two")).toEqual(["One", "Two"]);
    expect(parseSelectOptions("")).toEqual([]);
  });
});

describe("formatSelectOptions", () => {
  it("joins options with a comma and space", () => {
    expect(formatSelectOptions(["One", "Two"])).toBe("One, Two");
    expect(formatSelectOptions(null)).toBe("");
  });
});

describe("formatCustomFieldValue", () => {
  it("formats booleans as Yes or No", () => {
    const booleanField = field({ field_type: "boolean" });
    expect(formatCustomFieldValue(booleanField, true)).toBe("Yes");
    expect(formatCustomFieldValue(booleanField, false)).toBe("No");
  });

  it("formats dates as a locale date", () => {
    const dateField = field({ field_type: "date" });
    expect(formatCustomFieldValue(dateField, "2026-11-19")).toBe(
      new Date("2026-11-19").toLocaleDateString(),
    );
  });

  it("shows Not set for empty values", () => {
    expect(formatCustomFieldValue(field(), null)).toBe("Not set");
    expect(formatCustomFieldValue(field(), undefined)).toBe("Not set");
    expect(formatCustomFieldValue(field(), "")).toBe("Not set");
  });

  it("passes text and numbers through as strings", () => {
    expect(formatCustomFieldValue(field(), "ABC-123")).toBe("ABC-123");
    expect(formatCustomFieldValue(field({ field_type: "number" }), 42)).toBe("42");
  });
});

describe("validateCustomFieldValues", () => {
  it("flags missing required fields by field key", () => {
    const required = field({ is_required: true });
    const optional = field({
      id: "field-2",
      field_key: "notes",
      label: "Notes",
      is_required: false,
    });
    expect(
      validateCustomFieldValues([required, optional], {}),
    ).toEqual({
      "custom_field_values.trailer_number": "Trailer number is required.",
    });
  });

  it("accepts a filled required field and skips inactive fields", () => {
    const required = field({ is_required: true });
    const inactive = field({
      id: "field-3",
      field_key: "old_field",
      label: "Old field",
      is_required: true,
      is_active: false,
    });
    expect(
      validateCustomFieldValues([required, inactive], {
        trailer_number: "TR-1",
      }),
    ).toEqual({});
  });

  it("treats false as missing for a required boolean field", () => {
    const required = field({ field_type: "boolean", is_required: true });
    expect(validateCustomFieldValues([required], { trailer_number: false })).toEqual({
      "custom_field_values.trailer_number": "Trailer number is required.",
    });
    expect(validateCustomFieldValues([required], { trailer_number: true })).toEqual({});
  });
});

describe("cleanCustomFieldValues", () => {
  it("drops null, undefined, and empty-string values", () => {
    const input = {
      a: "x",
      b: null,
      c: undefined,
      d: "",
      e: 0,
      f: false,
    } as unknown as Parameters<typeof cleanCustomFieldValues>[0];
    expect(cleanCustomFieldValues(input)).toEqual({ a: "x", e: 0, f: false });
  });
});
