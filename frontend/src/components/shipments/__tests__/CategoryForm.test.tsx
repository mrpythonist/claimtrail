import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { CategoryForm } from "../CategoryForm";

function renderForm(overrides: Partial<Parameters<typeof CategoryForm>[0]> = {}) {
  const onSubmit = vi.fn(async () => undefined);
  const onCancel = vi.fn();
  render(
    <CategoryForm
      submitting={false}
      serverErrors={{}}
      serverMessage={null}
      submitLabel="Add category"
      onCancel={onCancel}
      onSubmit={onSubmit}
      {...overrides}
    />,
  );
  return { onSubmit, onCancel };
}

describe("CategoryForm", () => {
  it("shows a validation error instead of submitting an empty form", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.click(screen.getByRole("button", { name: "Add category" }));
    expect(screen.getByText("Category name is required.")).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("submits the filled form values", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.type(screen.getByLabelText("Category name"), "Weigh Station Fee");
    await user.type(
      screen.getByLabelText("Description (optional)"),
      "Charged when a load is weighed off route.",
    );
    await user.click(screen.getByRole("button", { name: "Add category" }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledWith({
      label: "Weigh Station Fee",
      description: "Charged when a load is weighed off route.",
    });
  });

  it("sends a null description when left blank", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.type(screen.getByLabelText("Category name"), "Weigh Station Fee");
    await user.click(screen.getByRole("button", { name: "Add category" }));

    expect(onSubmit).toHaveBeenCalledWith({
      label: "Weigh Station Fee",
      description: null,
    });
  });

  it("shows a server message such as a duplicate name", () => {
    renderForm({ serverMessage: "A category with this name already exists." });
    expect(
      screen.getByText("A category with this name already exists."),
    ).toBeInTheDocument();
  });

  it("calls onCancel when Cancel is clicked", async () => {
    const user = userEvent.setup();
    const { onCancel } = renderForm();
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});
