import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { EvidencePreviewModal } from "../EvidencePreviewModal";
import type { EvidenceFile } from "@/lib/types";

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
  return { apiFetch: vi.fn(), apiDownload: vi.fn(), ApiRequestError };
});

import { apiDownload } from "@/lib/api";

const mockedApiDownload = vi.mocked(apiDownload);

function makeFile(overrides: Partial<EvidenceFile> = {}): EvidenceFile {
  return {
    id: "file-1",
    organization_id: "org-1",
    shipment_id: "ship-1",
    shipment_event_id: null,
    document_category: "bill_of_lading",
    document_category_label: "Bill of Lading",
    original_filename: "bol.png",
    mime_type: "image/png",
    file_size: 1234,
    uploaded_by: { id: 1, name: "Aqib" },
    description: null,
    verification_status: "pending",
    previewable: true,
    created_at: "2026-11-16T10:00:00Z",
    updated_at: "2026-11-16T10:00:00Z",
    ...overrides,
  };
}

beforeEach(() => {
  mockedApiDownload.mockReset();
  vi.stubGlobal("URL", {
    createObjectURL: vi.fn(() => "blob:preview"),
    revokeObjectURL: vi.fn(),
  });
});

describe("EvidencePreviewModal", () => {
  it("renders nothing when no file is given", () => {
    const { container } = render(
      <EvidencePreviewModal file={null} orgId="org-1" onClose={vi.fn()} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it("fetches the preview and renders an image for image files", async () => {
    mockedApiDownload.mockResolvedValue(new Blob(["x"], { type: "image/png" }));
    render(
      <EvidencePreviewModal
        file={makeFile()}
        orgId="org-1"
        onClose={vi.fn()}
      />,
    );
    const img = (await screen.findByRole("img", { name: "bol.png" })) as HTMLImageElement;
    expect(img.getAttribute("src")).toBe("blob:preview");
    expect(mockedApiDownload).toHaveBeenCalledWith("/evidence/file-1/preview", {
      orgId: "org-1",
    });
  });

  it("renders an iframe for PDF files", async () => {
    mockedApiDownload.mockResolvedValue(
      new Blob(["x"], { type: "application/pdf" }),
    );
    render(
      <EvidencePreviewModal
        file={makeFile({ original_filename: "doc.pdf", mime_type: "application/pdf" })}
        orgId="org-1"
        onClose={vi.fn()}
      />,
    );
    await waitFor(() => {
      const frame = document.querySelector("iframe");
      expect(frame).not.toBeNull();
      expect(frame?.getAttribute("src")).toBe("blob:preview");
    });
  });

  it("shows a message and a download button for non-previewable files", async () => {
    render(
      <EvidencePreviewModal
        file={makeFile({
          original_filename: "sheet.xlsx",
          mime_type:
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
          previewable: false,
        })}
        orgId="org-1"
        onClose={vi.fn()}
      />,
    );
    expect(
      screen.getByText(/Preview is not available for this file type/),
    ).toBeInTheDocument();
    expect(mockedApiDownload).not.toHaveBeenCalled();
  });

  it("downloads the file when the Download button is clicked", async () => {
    const user = userEvent.setup();
    mockedApiDownload.mockResolvedValue(new Blob(["x"], { type: "image/png" }));
    render(
      <EvidencePreviewModal
        file={makeFile()}
        orgId="org-1"
        onClose={vi.fn()}
      />,
    );
    await screen.findByRole("img", { name: "bol.png" });
    mockedApiDownload.mockClear();
    mockedApiDownload.mockResolvedValue(new Blob(["x"], { type: "image/png" }));
    await user.click(screen.getByRole("button", { name: "Download" }));
    await waitFor(() => {
      expect(mockedApiDownload).toHaveBeenCalledWith("/evidence/file-1/download", {
        orgId: "org-1",
      });
    });
  });

  it("shows the error message when the preview request fails", async () => {
    mockedApiDownload.mockRejectedValue(new Error("Preview failed"));
    render(
      <EvidencePreviewModal
        file={makeFile()}
        orgId="org-1"
        onClose={vi.fn()}
      />,
    );
    expect(await screen.findByText("Could not load the preview.")).toBeInTheDocument();
  });
});
