import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { ImportRow, ImportRun, Organization } from "@/lib/types";
import ImportRunDetailPage from "../page";

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

vi.mock("next/navigation", () => ({ useParams: () => ({ id: "r-1" }) }));

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

const RUN: ImportRun = {
  id: "r-1",
  source: "upload",
  import_mapping_id: null,
  import_schedule_id: null,
  sync_run_id: null,
  filename: "shipments.csv",
  status: "partial",
  total_rows: 3,
  imported_rows: 1,
  skipped_rows: 2,
  duplicate_rows: 1,
  error_rows: 1,
  summary: null,
  error_message: null,
  started_at: "2026-11-26T10:00:00Z",
  finished_at: "2026-11-26T10:00:00Z",
  created_at: "2026-11-26T10:00:00Z",
};

const ROWS: ImportRow[] = [
  {
    id: "row-1",
    import_run_id: "r-1",
    row_index: 1,
    data: { internal_reference: "CT-IMP-1", broker_or_customer_name: "Heartland Foods" },
    status: "imported",
    errors: null,
    shipment_id: "s-1",
    corrected_at: null,
    created_at: "2026-11-26T10:00:00Z",
  },
  {
    id: "row-2",
    import_run_id: "r-1",
    row_index: 2,
    data: { internal_reference: "CT-EXISTS", broker_or_customer_name: "Ozark Supply" },
    status: "duplicate",
    errors: { internal_reference: ["A shipment with this reference already exists."] },
    shipment_id: null,
    corrected_at: null,
    created_at: "2026-11-26T10:00:00Z",
  },
  {
    id: "row-3",
    import_run_id: "r-1",
    row_index: 3,
    data: { broker_or_customer_name: "Missing Reference" },
    status: "skipped",
    errors: { internal_reference: ["The internal reference field is required."] },
    shipment_id: null,
    corrected_at: null,
    created_at: "2026-11-26T10:00:00Z",
  },
];

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
  mockedApiFetch.mockImplementation((url: string) => {
    if (url === "/imports/runs/r-1") {
      return Promise.resolve({ data: RUN });
    }
    if (url.startsWith("/imports/runs/r-1/rows")) {
      return Promise.resolve({
        data: ROWS,
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 3 },
      });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
});

describe("ImportRunDetailPage", () => {
  it("shows the run summary and its rows", async () => {
    render(<ImportRunDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("shipments.csv")).toBeInTheDocument();
      expect(screen.getByText("Row 1")).toBeInTheDocument();
      expect(screen.getByText("Row 2")).toBeInTheDocument();
      expect(screen.getByText("Row 3")).toBeInTheDocument();
    });

    expect(screen.getByText("Partial")).toBeInTheDocument();
    expect(
      screen.getByText("internal_reference: A shipment with this reference already exists."),
    ).toBeInTheDocument();
    expect(
      screen.getByText("internal_reference: The internal reference field is required."),
    ).toBeInTheDocument();
  });

  it("corrects a skipped row", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
      if (url === "/imports/runs/r-1/rows/row-3/correct" && options?.method === "POST") {
        return Promise.resolve({
          data: { ...ROWS[2], status: "corrected", errors: null, shipment_id: "s-9" },
        });
      }
      if (url === "/imports/runs/r-1") {
        return Promise.resolve({ data: RUN });
      }
      if (url.startsWith("/imports/runs/r-1/rows")) {
        return Promise.resolve({
          data: ROWS,
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 3 },
        });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });
    render(<ImportRunDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Row 3")).toBeInTheDocument();
    });

    await user.click(screen.getAllByRole("button", { name: "Correct and retry" })[1]);

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/imports/runs/r-1/rows/row-3/correct",
        expect.objectContaining({ method: "POST", orgId: "org-1" }),
      );
    });
  });
});
