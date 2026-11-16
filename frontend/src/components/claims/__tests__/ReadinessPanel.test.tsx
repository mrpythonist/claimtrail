import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ReadinessPanel } from "../ReadinessPanel";
import type { Readiness } from "@/lib/types";

const DISCLAIMER =
  "Readiness counts attached evidence against configured requirements. It does not certify that the claim is valid or will be accepted.";

const READINESS: Readiness = {
  claim_id: "claim-1",
  required: [
    { value: "bill_of_lading", label: "Bill of Lading" },
    { value: "rate_confirmation", label: "Rate Confirmation" },
  ],
  optional: [{ value: "driver_photo", label: "Driver Photo" }],
  collected: [{ value: "bill_of_lading", label: "Bill of Lading" }],
  missing: [{ value: "rate_confirmation", label: "Rate Confirmation" }],
  optional_collected: [],
  readiness_percentage: 50,
  readiness_status: "partial",
  readiness_status_label: "Partial",
  has_requirements: true,
  is_overridden: false,
  disclaimer: DISCLAIMER,
  snapshots: [
    {
      document_category: "bill_of_lading",
      document_category_label: "Bill of Lading",
      is_required: true,
      is_fulfilled: true,
      evidence_file_id: "file-1",
      snapshot_at: "2026-11-16T10:00:00Z",
      overridden_by: null,
      override_reason: null,
      overridden_at: null,
    },
    {
      document_category: "rate_confirmation",
      document_category_label: "Rate Confirmation",
      is_required: true,
      is_fulfilled: false,
      evidence_file_id: null,
      snapshot_at: "2026-11-16T10:00:00Z",
      overridden_by: null,
      override_reason: null,
      overridden_at: null,
    },
  ],
};

function renderPanel(overrides: Partial<Parameters<typeof ReadinessPanel>[0]> = {}) {
  const onOverride = vi.fn(async () => undefined);
  render(
    <ReadinessPanel
      readiness={READINESS}
      claimId="claim-1"
      canOverride
      overriding={false}
      onOverride={onOverride}
      {...overrides}
    />,
  );
  return { onOverride };
}

describe("ReadinessPanel", () => {
  it("shows the percentage and status", () => {
    renderPanel();
    expect(screen.getByText("50%")).toBeInTheDocument();
    expect(screen.getByText("Partial")).toBeInTheDocument();
    const progress = screen.getByRole("progressbar", {
      name: "Readiness percentage",
    });
    expect(progress).toHaveAttribute("aria-valuenow", "50");
  });

  it("lists required, collected, and missing documents", () => {
    renderPanel();
    expect(screen.getByText("Required documents (2)")).toBeInTheDocument();
    expect(screen.getByText("Collected (1)")).toBeInTheDocument();
    expect(screen.getByText("Missing (1)")).toBeInTheDocument();
    expect(screen.getByText("Optional documents (1)")).toBeInTheDocument();
  });

  it("shows the API disclaimer verbatim", () => {
    renderPanel();
    expect(screen.getByText(DISCLAIMER)).toBeInTheDocument();
  });

  it("shows the override badge when overridden", () => {
    renderPanel({
      readiness: { ...READINESS, is_overridden: true },
    });
    expect(screen.getByText("Overridden by a reviewer")).toBeInTheDocument();
  });

  it("requires a reason before overriding", async () => {
    const user = userEvent.setup();
    const { onOverride } = renderPanel();
    await user.click(screen.getByRole("button", { name: "Override readiness" }));
    expect(
      screen.getByText("A reason is required to override readiness."),
    ).toBeInTheDocument();
    expect(onOverride).not.toHaveBeenCalled();
  });

  it("calls onOverride with the reason", async () => {
    const user = userEvent.setup();
    const { onOverride } = renderPanel();
    await user.type(
      screen.getByLabelText("Reason"),
      "Broker confirmed by phone that the rate confirmation is on file.",
    );
    await user.click(screen.getByRole("button", { name: "Override readiness" }));
    expect(onOverride).toHaveBeenCalledTimes(1);
    expect(onOverride).toHaveBeenCalledWith(
      "Broker confirmed by phone that the rate confirmation is on file.",
    );
  });

  it("hides the override form when the user cannot override", () => {
    renderPanel({ canOverride: false });
    expect(
      screen.queryByRole("button", { name: "Override readiness" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByText(/Only owners, admins, and operations managers/),
    ).toBeInTheDocument();
  });

  it("links to evidence requirements when none are configured", () => {
    renderPanel({
      readiness: { ...READINESS, has_requirements: false },
    });
    const links = screen.getAllByRole("link", {
      name: /manage evidence requirements/i,
    });
    expect(links.length).toBeGreaterThan(0);
    for (const link of links) {
      expect(link).toHaveAttribute("href", "/settings/evidence-requirements");
    }
  });
});
