import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch, apiUpload } from "@/lib/api";
import type { DriverEvidencePage as DriverEvidencePageData } from "@/lib/types";
import DriverEvidencePage from "../page";

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
  return { apiFetch: vi.fn(), apiUpload: vi.fn(), ApiRequestError };
});

vi.mock("next/navigation", () => ({
  useParams: () => ({ token: "tok-1" }),
}));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedApiUpload = vi.mocked(apiUpload);

const PAGE: DriverEvidencePageData = {
  organization_name: "Acme Trucking",
  shipment_reference: "REF-1",
  claim_reference: "CLM-1",
  driver_name: "Sam Driver",
  state: "usable",
  expires_at: "2026-11-30T00:00:00Z",
  uses_left: 1,
};

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiUpload.mockReset();
});

describe("DriverEvidencePage", () => {
  it("shows the scoped shipment and claim and submits a file", async () => {
    mockedApiFetch.mockResolvedValue({ data: PAGE });
    mockedApiUpload.mockResolvedValue({ data: { id: "sb-1" } });
    const user = userEvent.setup();
    render(<DriverEvidencePage />);

    await waitFor(() => {
      expect(screen.getByText(/Shipment REF-1/)).toBeInTheDocument();
    });

    const file = new File(["%PDF-1.4 fake"], "pod.pdf", { type: "application/pdf" });
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(input, file);
    await user.click(screen.getByRole("button", { name: "Submit evidence" }));

    await waitFor(() => {
      expect(screen.getByText("Evidence received")).toBeInTheDocument();
    });
    expect(mockedApiUpload).toHaveBeenCalledWith(
      "/driver-evidence/tok-1/submit",
      expect.any(FormData),
      { withOrg: false },
    );
  });

  it("shows the same invalid message for an unknown token", async () => {
    mockedApiFetch.mockRejectedValue(
      new (class extends Error {
        status = 404;
        constructor() {
          super("This evidence link is not valid. Ask the dispatcher to send a new one.");
        }
      })(),
    );
    render(<DriverEvidencePage />);

    await waitFor(() => {
      expect(screen.getByText(/invalid, expired, revoked/)).toBeInTheDocument();
    });
  });
});
