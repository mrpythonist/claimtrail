import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  dismissDocumentMatch,
  getDocument,
  getDocumentAccuracy,
  linkDocumentShipment,
  listDocuments,
  listVerificationQueue,
  processDocument,
  rejectDocument,
  verifyDocument,
  verifyDocumentField,
} from "../document-processing";
import type { DocumentAccuracy, DocumentExtraction } from "@/lib/types";

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

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

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
  status: "completed",
  status_label: "Completed",
  failure_reason: null,
  matched_shipment_id: "ship-1",
  matched_shipment_reference: "PLT-1001",
  match_confidence: 100,
  match_method: "exact",
  match_candidates: [
    { value: "PLT-1001", source: "body", matched_shipment_id: "ship-1", matched_field: "internal_reference" },
  ],
  match_dismissed: false,
  is_duplicate: false,
  duplicate_of_id: null,
  duplicate_of_filename: null,
  near_duplicate_of_id: null,
  near_duplicate_reason: null,
  extracted_text: "BILL OF LADING\nBOL No. PLT-1001\n",
  processed_at: "2026-11-27T00:00:00Z",
  verified_at: null,
  verified_by: null,
  created_at: "2026-11-27T00:00:00Z",
  updated_at: "2026-11-27T00:00:00Z",
  fields: [
    {
      id: "field-1",
      extraction_id: "doc-1",
      field_name: "shipper",
      field_label: "Shipper",
      field_value: "Acme Logistics LLC",
      confidence: 95,
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
  overall: { decided: 6, agreed: 5, accuracy: 83.33 },
  by_field: {
    amount_due: { decided: 1, agreed: 0, accuracy: 0 },
    invoice_number: { decided: 1, agreed: 1, accuracy: 100 },
  },
  by_classification: {
    invoice: { decided: 6, agreed: 5, accuracy: 83.33 },
  },
  counts: {
    total_extractions: 2,
    needs_verification: 0,
    verified_extractions: 1,
    duplicates: 0,
    decided_fields: 6,
  },
};

describe("document processing client", () => {
  it("processes a document by evidence file id", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: EXTRACTION });
    const result = await processDocument("ev-1");
    expect(result).toEqual(EXTRACTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/process", {
      method: "POST",
      body: JSON.stringify({ evidence_file_id: "ev-1" }),
    });
  });

  it("lists documents with filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [EXTRACTION] });
    const result = await listDocuments({ status: "needs_verification" });
    expect(result).toEqual([EXTRACTION]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents?status=needs_verification");
  });

  it("lists the verification queue", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [EXTRACTION] });
    const result = await listVerificationQueue();
    expect(result).toEqual([EXTRACTION]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/queue");
  });

  it("reads measured accuracy", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: ACCURACY });
    const result = await getDocumentAccuracy();
    expect(result.overall.accuracy).toBe(83.33);
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/accuracy");
  });

  it("reads one extraction", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: EXTRACTION });
    const result = await getDocument("doc-1");
    expect(result.id).toBe("doc-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1");
  });

  it("verifies a field with a corrected value", async () => {
    const corrected = { ...EXTRACTION.fields[0], verification_status: "corrected", verified_value: "850.00" };
    mockedApiFetch.mockResolvedValueOnce({ data: corrected });
    const result = await verifyDocumentField("doc-1", "field-1", "corrected", "850.00");
    expect(result.verified_value).toBe("850.00");
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1/fields/field-1/verify", {
      method: "POST",
      body: JSON.stringify({ decision: "corrected", corrected_value: "850.00", note: null }),
    });
  });

  it("verifies a whole document with a classification override", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { ...EXTRACTION, status: "verified" } });
    const result = await verifyDocument("doc-1", "other");
    expect(result.status).toBe("verified");
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1/verify", {
      method: "POST",
      body: JSON.stringify({ classification: "other" }),
    });
  });

  it("rejects a document with a reason", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { ...EXTRACTION, status: "rejected" } });
    const result = await rejectDocument("doc-1", "Wrong document for this load.");
    expect(result.status).toBe("rejected");
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1/reject", {
      method: "POST",
      body: JSON.stringify({ reason: "Wrong document for this load." }),
    });
  });

  it("links a shipment by hand", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: { ...EXTRACTION, match_method: "manual", matched_shipment_id: "ship-2" },
    });
    const result = await linkDocumentShipment("doc-1", "ship-2");
    expect(result.match_method).toBe("manual");
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1/link-shipment", {
      method: "POST",
      body: JSON.stringify({ shipment_id: "ship-2" }),
    });
  });

  it("dismisses a reference match", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { ...EXTRACTION, match_dismissed: true } });
    const result = await dismissDocumentMatch("doc-1");
    expect(result.match_dismissed).toBe(true);
    expect(mockedApiFetch).toHaveBeenCalledWith("/documents/doc-1/dismiss-match", { method: "POST" });
  });
});
