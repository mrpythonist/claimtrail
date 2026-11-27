import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuth } from "@/lib/auth";
import {
  getDocumentAccuracy,
  listDocuments,
  listVerificationQueue,
  processDocument,
  verifyDocumentField,
} from "@/lib/document-processing";
import type { DocumentAccuracy, DocumentExtraction, Organization } from "@/lib/types";
import DocumentsPage from "../page";

vi.mock("@/lib/document-processing", () => ({
  DOCUMENT_CLASSIFICATIONS: [
    { value: "bill_of_lading", label: "Bill of Lading" },
    { value: "other", label: "Other document" },
    { value: "unclassified", label: "Unclassified" },
  ],
  DOCUMENT_STATUSES: [
    { value: "completed", label: "Completed" },
    { value: "needs_verification", label: "Needs verification" },
  ],
  listDocuments: vi.fn(),
  listVerificationQueue: vi.fn(),
  getDocumentAccuracy: vi.fn(),
  getDocument: vi.fn(),
  processDocument: vi.fn(),
  verifyDocumentField: vi.fn(),
  verifyDocument: vi.fn(),
  rejectDocument: vi.fn(),
  linkDocumentShipment: vi.fn(),
  dismissDocumentMatch: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedUseAuth = vi.mocked(useAuth);
const mockedListDocuments = vi.mocked(listDocuments);
const mockedListQueue = vi.mocked(listVerificationQueue);
const mockedAccuracy = vi.mocked(getDocumentAccuracy);
const mockedProcess = vi.mocked(processDocument);
const mockedVerifyField = vi.mocked(verifyDocumentField);

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

const EXTRACTION: DocumentExtraction = {
  id: "doc-1",
  evidence_file_id: "ev-1",
  original_filename: "bol-plt-1001.txt",
  mime_type: "text/plain",
  file_size: 120,
  content_sha256: "abc123",
  provider: "local",
  classification: "bill_of_lading",
  classification_label: "Bill of Lading",
  classification_confidence: 53.85,
  status: "needs_verification",
  status_label: "Needs verification",
  failure_reason: null,
  matched_shipment_id: null,
  matched_shipment_reference: null,
  match_confidence: null,
  match_method: "candidate",
  match_candidates: [
    { value: "CT-7788", source: "body", matched_shipment_id: null, matched_field: null },
  ],
  match_dismissed: false,
  is_duplicate: false,
  duplicate_of_id: null,
  duplicate_of_filename: null,
  near_duplicate_of_id: null,
  near_duplicate_reason: null,
  extracted_text: "DETENTION NOTICE\nLoad: CT-7788\n",
  processed_at: "2026-11-27T00:00:00Z",
  verified_at: null,
  verified_by: null,
  created_at: "2026-11-27T00:00:00Z",
  updated_at: "2026-11-27T00:00:00Z",
  fields: [
    {
      id: "field-1",
      extraction_id: "doc-1",
      field_name: "detention_amount",
      field_label: "Detention amount",
      field_value: "450.00",
      confidence: 80,
      verified_value: null,
      verification_status: "pending",
      verification_status_label: "Pending review",
      verified_by: null,
      verified_at: null,
      verification_note: null,
    },
  ],
};

const ACCURACY: DocumentAccuracy = {
  organization_id: "org-1",
  overall: { decided: 4, agreed: 4, accuracy: 100 },
  by_field: {},
  by_classification: {},
  counts: {
    total_extractions: 1,
    needs_verification: 1,
    verified_extractions: 0,
    duplicates: 0,
    decided_fields: 4,
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as never);
  mockedListDocuments.mockResolvedValue([EXTRACTION]);
  mockedListQueue.mockResolvedValue([EXTRACTION]);
  mockedAccuracy.mockResolvedValue(ACCURACY);
});

describe("Documents page", () => {
  it("shows the accuracy panel and the document list", async () => {
    render(<DocumentsPage />);

    await waitFor(() => {
      expect(screen.getByText("Extraction accuracy")).toBeInTheDocument();
    });
    expect(screen.getByText("bol-plt-1001.txt")).toBeInTheDocument();
    expect(screen.getAllByText("Needs verification").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("100.00%")).toBeInTheDocument();
  });

  it("shows the verification queue when its tab is chosen", async () => {
    render(<DocumentsPage />);

    await waitFor(() => {
      expect(screen.getByText("bol-plt-1001.txt")).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Verification queue \(1\)/));
    await waitFor(() => {
      expect(screen.getByText("Verification queue")).toBeInTheDocument();
    });
  });

  it("opens the review detail and verifies a field", async () => {
    mockedVerifyField.mockResolvedValue({
      ...EXTRACTION.fields[0],
      verification_status: "verified",
      verified_value: "450.00",
    });
    mockedListDocuments.mockResolvedValue([EXTRACTION]);

    render(<DocumentsPage />);

    await waitFor(() => {
      expect(screen.getByText("bol-plt-1001.txt")).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText("Review"));

    await waitFor(() => {
      expect(screen.getByText("Detention amount")).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText("Verify"));

    await waitFor(() => {
      expect(mockedVerifyField).toHaveBeenCalledWith("doc-1", "field-1", "verified", undefined);
    });
  });

  it("processes a document from an evidence file id", async () => {
    mockedProcess.mockResolvedValue(EXTRACTION);

    render(<DocumentsPage />);

    await waitFor(() => {
      expect(screen.getByText("Process a document")).toBeInTheDocument();
    });

    fireEvent.change(screen.getByLabelText("Evidence file id"), { target: { value: "ev-1" } });
    fireEvent.click(screen.getByText("Process document"));

    await waitFor(() => {
      expect(mockedProcess).toHaveBeenCalledWith("ev-1");
    });
  });
});
