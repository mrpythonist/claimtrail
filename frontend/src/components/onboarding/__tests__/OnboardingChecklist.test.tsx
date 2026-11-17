import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { OnboardingChecklist } from "../OnboardingChecklist";
import type { OnboardingProgress } from "@/lib/types";

const PROGRESS: OnboardingProgress = {
  steps: [
    {
      key: "create_organization",
      label: "Create your organization",
      description: "Set up the workspace for your company.",
      completed: true,
      href: "/settings/organization",
    },
    {
      key: "invite_member",
      label: "Invite a member",
      description: "Invite someone to work on claims with you.",
      completed: false,
      href: "/settings/members",
    },
    {
      key: "create_shipment",
      label: "Create a shipment",
      description: "Add your first load.",
      completed: false,
      href: "/shipments/new",
    },
  ],
  completed_count: 1,
  total_count: 3,
  all_completed: false,
  dismissed: false,
};

describe("OnboardingChecklist", () => {
  it("shows the progress count and a progressbar", () => {
    render(
      <OnboardingChecklist progress={PROGRESS} canDismiss={false} />,
    );
    expect(screen.getByText("1 of 3 done")).toBeInTheDocument();
    expect(
      screen.getByRole("progressbar", { name: "Onboarding progress" }),
    ).toHaveAttribute("aria-valuenow", "1");
  });

  it("marks completed steps and links incomplete steps to their href", () => {
    render(
      <OnboardingChecklist progress={PROGRESS} canDismiss={false} />,
    );
    expect(screen.getByText("Done")).toBeInTheDocument();
    const links = screen.getAllByRole("link", { name: "Go to this step" });
    expect(links).toHaveLength(2);
    expect(links[0]).toHaveAttribute("href", "/settings/members");
    expect(links[1]).toHaveAttribute("href", "/shipments/new");
  });

  it("shows the dismiss button only when the user can dismiss", async () => {
    const user = userEvent.setup();
    const onDismiss = vi.fn();
    const { rerender } = render(
      <OnboardingChecklist
        progress={PROGRESS}
        canDismiss
        onDismiss={onDismiss}
      />,
    );
    await user.click(screen.getByRole("button", { name: "Dismiss onboarding" }));
    expect(onDismiss).toHaveBeenCalledTimes(1);

    rerender(<OnboardingChecklist progress={PROGRESS} canDismiss={false} />);
    expect(
      screen.queryByRole("button", { name: "Dismiss onboarding" }),
    ).not.toBeInTheDocument();
  });
});
