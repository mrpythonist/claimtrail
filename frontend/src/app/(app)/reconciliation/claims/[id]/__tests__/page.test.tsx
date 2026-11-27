import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { ClaimBalance, Organization } from "@/lib/types";
import ClaimBalancePage from "../page";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  }
  return { apiFetch: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "claim-1" }),
}));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedUseAuth = vi.mocked(useAuth);

const ORG: Organization = {
  id: "org-1",
  name: "Acme Trucking",
  slug: "acme",
  timezone: "UTC",
  settings: null,
  created_at: "2026-11-01T00:00:00Z",
  updated_at: "2026-11-01T00:00:00Z",
  current_user_role: "admin",
  current_user_status: "active",
};

const BALANCE: ClaimBalance = {
  claim_id: "claim-1",
  claim_reference: "CLM-2026-0001",
  status: "accepted",
  currency: "USD",
  requested_amount: "250.00",
  approved_amount: "200.00",
  due_amount: "200.00",
  paid_amount: "120.00",
  outstanding_amount: "80.00",
  ledger_amount: "120.00",
  legacy_amount: "0.00",
  balance_status: "partial",
  payments: [
    {
      id: "pay-1",
      organization_id: "org-1",
      claim_id: "claim-1",
      amount: "120.00",
      currency: "USD",
      payment_method: "wire_transfer",
      payment_method_label: "Wire transfer",
      payment_reference: "WIRE-1001",
      paid_at: "2026-11-27",
      notes: null,
      recorded_by: "user-1",
      created_at: "2026-11-27T10:00:00Z",
    },
  ],
};

function mockLoad(balance: ClaimBalance = BALANCE) {
  mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
    if (url.startsWith("/reconciliation/claims/claim-1/payments") && options?.method === "POST") {
      return Promise.resolve({
        data: { ...balance.payments[0], id: "pay-2", amount: "80.00", payment_reference: "WIRE-1002" },
        balance: { ...balance, paid_amount: "200.00", outstanding_amount: "0.00", balance_status: "paid" },
      });
    }
    if (url.startsWith("/reconciliation/claims/claim-1")) {
      return Promise.resolve({ data: balance });
    }
    if (url.startsWith("/reconciliation/ledger/") && options?.method === "DELETE") {
      return Promise.resolve({ data: { voided: true } });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("ClaimBalancePage", () => {
  it("shows the balance and the payment history", async () => {
    mockLoad();
    render(<ClaimBalancePage />);

    await waitFor(() => {
      expect(screen.getByText("CLM-2026-0001", { exact: false })).toBeInTheDocument();
      expect(screen.getByText("WIRE-1001", { exact: false })).toBeInTheDocument();
    });

    expect(screen.getByText("Partially paid")).toBeInTheDocument();
    expect(screen.getByText("Record a payment")).toBeInTheDocument();
  });

  it("records a payment from the form", async () => {
    mockLoad();
    const user = userEvent.setup();
    render(<ClaimBalancePage />);

    await waitFor(() => {
      expect(screen.getByText("Record a payment")).toBeInTheDocument();
    });

    await user.type(screen.getByLabelText(/Amount/), "80");
    await user.type(screen.getByLabelText("Payment reference"), "WIRE-1002");
    await user.click(screen.getByRole("button", { name: "Record payment" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/reconciliation/claims/claim-1/payments",
        expect.objectContaining({ method: "POST" }),
      );
    });
  });

  it("voids a payment from the history", async () => {
    mockLoad();
    const user = userEvent.setup();
    render(<ClaimBalancePage />);

    await waitFor(() => {
      expect(screen.getByRole("button", { name: "Void" })).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Void" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/reconciliation/ledger/pay-1",
        expect.objectContaining({ method: "DELETE" }),
      );
    });
  });
});
