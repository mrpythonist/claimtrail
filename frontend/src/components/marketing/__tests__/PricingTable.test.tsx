import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { PricingTable } from "../PricingTable";
import { MARKETING_PLANS } from "@/lib/marketing";

describe("PricingTable", () => {
  it("renders all five listed tiers with monthly prices", () => {
    render(<PricingTable plans={MARKETING_PLANS} />);

    for (const name of ["Free", "Starter", "Growth", "Fleet", "Agency"]) {
      expect(screen.getByRole("heading", { name })).toBeInTheDocument();
    }
    expect(screen.getByText("$0")).toBeInTheDocument();
    expect(screen.getByText("$19")).toBeInTheDocument();
    expect(screen.getByText("$49")).toBeInTheDocument();
    expect(screen.getByText("$99")).toBeInTheDocument();
    expect(screen.getByText("$149")).toBeInTheDocument();
  });

  it("highlights Growth as the default tier", () => {
    render(<PricingTable plans={MARKETING_PLANS} />);
    expect(screen.getByText("Default")).toBeInTheDocument();
  });

  it("switches to annual prices with two months free", async () => {
    const user = userEvent.setup();
    render(<PricingTable plans={MARKETING_PLANS} />);

    await user.click(screen.getByRole("button", { name: /Annual/ }));

    expect(screen.getByText("$190")).toBeInTheDocument();
    expect(screen.getByText("$490")).toBeInTheDocument();
    expect(screen.getByText("$990")).toBeInTheDocument();
    expect(screen.getByText("$1,490")).toBeInTheDocument();
    expect(
      screen.getByText("($49 per month, billed annually)"),
    ).toBeInTheDocument();
  });

  it("links each tier to register with the plan chosen", () => {
    render(<PricingTable plans={MARKETING_PLANS} />);

    const growthLink = screen
      .getAllByRole("link")
      .find((link) => link.getAttribute("href") === "/register?plan=growth");
    expect(growthLink).toBeDefined();
    const agencyLink = screen
      .getAllByRole("link")
      .find((link) => link.getAttribute("href") === "/register?plan=agency");
    expect(agencyLink).toBeDefined();
  });

  it("notes the Agency per-carrier price", () => {
    render(<PricingTable plans={MARKETING_PLANS} />);
    expect(
      screen.getByText(
        "Base for up to 10 carriers, then $12 per extra carrier per month.",
      ),
    ).toBeInTheDocument();
  });
});
