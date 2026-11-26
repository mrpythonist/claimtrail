import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { DemoWalkthrough } from "../DemoWalkthrough";

describe("DemoWalkthrough", () => {
  it("starts on the shipment step and advances through the steps", async () => {
    const user = userEvent.setup();
    render(<DemoWalkthrough />);

    expect(
      screen.getByRole("heading", { name: "Step 1: The shipment" }),
    ).toBeInTheDocument();
    expect(screen.getByText(/PLT-1001/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Next step" }));
    expect(
      screen.getByRole("heading", { name: "Step 2: The event" }),
    ).toBeInTheDocument();
    expect(screen.getByText(/3 hours 35 minutes/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Previous" }));
    expect(
      screen.getByRole("heading", { name: "Step 1: The shipment" }),
    ).toBeInTheDocument();
  });

  it("jumps to a step from the step list and ends at payment", async () => {
    const user = userEvent.setup();
    render(<DemoWalkthrough />);

    await user.click(screen.getByRole("button", { name: "7. Payment" }));
    expect(
      screen.getByRole("heading", { name: "Step 7: Payment" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "End of the walkthrough" }),
    ).toBeDisabled();
  });
});
