import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { CustomFieldInputs } from "../CustomFieldInputs";
import type { CustomField } from "@/lib/types";

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

describe("CustomFieldInputs", () => {
  it("renders nothing when there are no active fields", () => {
    const { container } = render(
      <CustomFieldInputs fields={[]} values={{}} onChange={vi.fn()} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it("skips inactive fields", () => {
    render(
      <CustomFieldInputs
        fields={[field({ is_active: false })]}
        values={{}}
        onChange={vi.fn()}
      />,
    );
    expect(screen.queryByLabelText("Trailer number")).toBeNull();
  });

  it("renders a text input and reports changes by field key", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <CustomFieldInputs
        fields={[field()]}
        values={{}}
        onChange={onChange}
      />,
    );
    await user.type(screen.getByLabelText("Trailer number"), "TR-1");
    // The component is controlled: each keystroke reports the new
    // value merged into the values it was given (empty here), so the
    // calls carry one character at a time.
    expect(onChange).toHaveBeenCalledTimes(4);
    expect(onChange).toHaveBeenNthCalledWith(1, { trailer_number: "T" });
    expect(onChange).toHaveBeenLastCalledWith({ trailer_number: "1" });
  });

  it("renders a number input, a date input, and a select from options", () => {
    render(
      <CustomFieldInputs
        fields={[
          field({ id: "f-num", field_key: "weight", label: "Weight", field_type: "number" }),
          field({ id: "f-date", field_key: "due", label: "Due date", field_type: "date" }),
          field({
            id: "f-sel",
            field_key: "region",
            label: "Region",
            field_type: "select",
            options: ["North", "South"],
          }),
        ]}
        values={{ weight: 10, due: "2026-11-19", region: "North" }}
        onChange={vi.fn()}
      />,
    );
    expect(screen.getByLabelText("Weight")).toHaveValue(10);
    expect(screen.getByLabelText("Due date")).toHaveValue("2026-11-19");
    expect(screen.getByLabelText("Region")).toHaveValue("North");
    expect(screen.getByText("South")).toBeInTheDocument();
  });

  it("renders a checkbox for boolean fields", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <CustomFieldInputs
        fields={[
          field({
            field_key: "hazmat",
            label: "Hazmat",
            field_type: "boolean",
          }),
        ]}
        values={{ hazmat: false }}
        onChange={onChange}
      />,
    );
    await user.click(screen.getByLabelText("Hazmat"));
    expect(onChange).toHaveBeenCalledWith({ hazmat: true });
  });

  it("shows the error for a field", () => {
    render(
      <CustomFieldInputs
        fields={[field({ is_required: true })]}
        values={{}}
        onChange={vi.fn()}
        errors={{
          "custom_field_values.trailer_number": "Trailer number is required.",
        }}
      />,
    );
    expect(screen.getByRole("alert")).toHaveTextContent(
      "Trailer number is required.",
    );
  });
});
