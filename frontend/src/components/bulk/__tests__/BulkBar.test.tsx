import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { BulkBar } from "../BulkBar";
import { Button } from "@/components/ui/Button";

describe("BulkBar", () => {
  it("renders nothing when nothing is selected and there is no summary", () => {
    const { container } = render(
      <BulkBar selectedCount={0} itemLabel="shipment" onClearSelection={vi.fn()}>
        <Button>Apply</Button>
      </BulkBar>,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it("shows the count, children, and a clear button when items are selected", async () => {
    const user = userEvent.setup();
    const onClear = vi.fn();
    render(
      <BulkBar selectedCount={2} itemLabel="shipment" onClearSelection={onClear}>
        <Button>Apply</Button>
      </BulkBar>,
    );
    expect(screen.getByText("2 shipments selected")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Apply" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Clear selection" }));
    expect(onClear).toHaveBeenCalledTimes(1);
  });

  it("shows the summary even after the selection is cleared", () => {
    render(
      <BulkBar
        selectedCount={0}
        itemLabel="claim"
        onClearSelection={vi.fn()}
        summary="3 claims updated."
      >
        <Button>Apply</Button>
      </BulkBar>,
    );
    expect(screen.getByText("3 claims updated.")).toBeInTheDocument();
  });
});
