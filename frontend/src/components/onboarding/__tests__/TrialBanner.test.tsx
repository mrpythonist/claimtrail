import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { TrialBanner } from "../TrialBanner";
import { getSubscription, startTrial } from "@/lib/billing";
import { setSignupPlan } from "@/lib/signup-plan";
import type { BillingSubscription } from "@/lib/types";

vi.mock("@/lib/billing", () => ({
  getSubscription: vi.fn(),
  startTrial: vi.fn(),
}));

const mockedGetSubscription = vi.mocked(getSubscription);
const mockedStartTrial = vi.mocked(startTrial);

const FREE_SUBSCRIPTION: BillingSubscription = {
  id: "sub-1",
  plan: "free",
  plan_name: "Free",
  status: "active",
  interval: "monthly",
  current_period_start: "2026-11-10T00:00:00Z",
  current_period_end: "2026-12-10T00:00:00Z",
  trial_ends_at: null,
  cancel_at_period_end: false,
  canceled_at: null,
  pending_plan: null,
  pending_interval: null,
  pending_extra_carriers: null,
  provider: "mock",
  payment_method_brand: null,
  payment_method_last4: null,
  extra_carriers: 0,
  credit_balance_cents: 0,
  monthly_cents: 0,
  period_cents: 0,
  success_fee_capped: false,
};

beforeEach(() => {
  window.localStorage.clear();
  mockedGetSubscription.mockReset();
  mockedStartTrial.mockReset();
  mockedGetSubscription.mockResolvedValue(FREE_SUBSCRIPTION);
  mockedStartTrial.mockResolvedValue({
    ...FREE_SUBSCRIPTION,
    plan: "growth",
    status: "trialing",
  });
});

describe("TrialBanner", () => {
  it("offers the trial for the plan chosen on the pricing page", async () => {
    setSignupPlan("growth");
    render(<TrialBanner orgId="org-1" />);

    expect(
      await screen.findByText("You chose the Growth plan"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Start the 30-day free trial" }),
    ).toBeInTheDocument();
  });

  it("starts the trial and clears the remembered plan", async () => {
    const user = userEvent.setup();
    setSignupPlan("growth");
    render(<TrialBanner orgId="org-1" />);

    await user.click(
      await screen.findByRole("button", { name: "Start the 30-day free trial" }),
    );

    await waitFor(() => {
      expect(mockedStartTrial).toHaveBeenCalledWith("org-1", null);
    });
    expect(
      await screen.findByText(/Your 30-day Growth trial has started/),
    ).toBeInTheDocument();
    expect(window.localStorage.getItem("claimtrail_signup_plan")).toBeNull();
  });

  it("stays hidden when no plan was chosen", () => {
    const { container } = render(<TrialBanner orgId="org-1" />);
    expect(container).toBeEmptyDOMElement();
  });

  it("stays hidden when the organization is already trialing", async () => {
    mockedGetSubscription.mockResolvedValue({
      ...FREE_SUBSCRIPTION,
      plan: "growth",
      status: "trialing",
    });
    setSignupPlan("growth");
    render(<TrialBanner orgId="org-1" />);

    await waitFor(() => {
      expect(mockedGetSubscription).toHaveBeenCalled();
    });
    expect(
      screen.queryByText("You chose the Growth plan"),
    ).not.toBeInTheDocument();
  });
});
