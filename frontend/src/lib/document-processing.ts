import { apiFetch } from "./api";
import type {
  DocumentAccuracy,
  DocumentExtraction,
  DocumentExtractionField,
} from "./types";

/**
 * Typed client for the AI document processing endpoints
 * (v2.0.1): OCR extraction, classification, field extraction,
 * shipment matching, duplicate detection, human verification,
 * and measured accuracy.
 *
 * There is no live OCR service: the local provider reads text
 * a document already carries and cannot read scanned images,
 * so a scanned image fails processing with a plain reason.
 * Only an exact reference match auto-links a shipment;
 * anything weaker goes to the verification queue. Processing
 * and verification need manage-document-processing and the
 * Starter plan or better; the backend refuses the Free plan
 * with a 402. The UI gates on canManageDocumentProcessing and
 * still handles 403 and 402 responses gracefully.
 */

export const DOCUMENT_CLASSIFICATIONS: { value: string; label: string }[] = [
  { value: "bill_of_lading", label: "Bill of Lading" },
  { value: "proof_of_delivery", label: "Proof of Delivery" },
  { value: "invoice", label: "Invoice" },
  { value: "rate_confirmation", label: "Rate Confirmation" },
  { value: "detention_notice", label: "Detention Notice" },
  { value: "other", label: "Other document" },
  { value: "unclassified", label: "Unclassified" },
];

export const DOCUMENT_STATUSES: { value: string; label: string }[] = [
  { value: "pending", label: "Pending" },
  { value: "processing", label: "Processing" },
  { value: "completed", label: "Completed" },
  { value: "needs_verification", label: "Needs verification" },
  { value: "verified", label: "Verified" },
  { value: "rejected", label: "Rejected" },
  { value: "failed", label: "Failed" },
];

export interface DocumentListFilters {
  status?: string;
  classification?: string;
  shipment_id?: string;
  is_duplicate?: boolean;
}

function queryString(filters: DocumentListFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.classification) params.set("classification", filters.classification);
  if (filters.shipment_id) params.set("shipment_id", filters.shipment_id);
  if (filters.is_duplicate !== undefined) params.set("is_duplicate", filters.is_duplicate ? "1" : "0");
  const query = params.toString();
  return query === "" ? "" : `?${query}`;
}

export async function processDocument(evidenceFileId: string): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>("/documents/process", {
    method: "POST",
    body: JSON.stringify({ evidence_file_id: evidenceFileId }),
  });
  return body.data;
}

export async function listDocuments(
  filters: DocumentListFilters = {},
): Promise<DocumentExtraction[]> {
  const body = await apiFetch<{ data: DocumentExtraction[] }>(
    `/documents${queryString(filters)}`,
  );
  return body.data;
}

export async function listVerificationQueue(): Promise<DocumentExtraction[]> {
  const body = await apiFetch<{ data: DocumentExtraction[] }>("/documents/queue");
  return body.data;
}

export async function getDocumentAccuracy(): Promise<DocumentAccuracy> {
  const body = await apiFetch<{ data: DocumentAccuracy }>("/documents/accuracy");
  return body.data;
}

export async function getDocument(id: string): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>(`/documents/${id}`);
  return body.data;
}

export async function verifyDocumentField(
  extractionId: string,
  fieldId: string,
  decision: "verified" | "corrected" | "rejected",
  correctedValue?: string,
  note?: string,
): Promise<DocumentExtractionField> {
  const body = await apiFetch<{ data: DocumentExtractionField }>(
    `/documents/${extractionId}/fields/${fieldId}/verify`,
    {
      method: "POST",
      body: JSON.stringify({
        decision,
        corrected_value: correctedValue ?? null,
        note: note ?? null,
      }),
    },
  );
  return body.data;
}

export async function verifyDocument(
  extractionId: string,
  classification?: string,
): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>(
    `/documents/${extractionId}/verify`,
    {
      method: "POST",
      body: JSON.stringify({ classification: classification ?? null }),
    },
  );
  return body.data;
}

export async function rejectDocument(
  extractionId: string,
  reason: string,
): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>(
    `/documents/${extractionId}/reject`,
    {
      method: "POST",
      body: JSON.stringify({ reason }),
    },
  );
  return body.data;
}

export async function linkDocumentShipment(
  extractionId: string,
  shipmentId: string,
): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>(
    `/documents/${extractionId}/link-shipment`,
    {
      method: "POST",
      body: JSON.stringify({ shipment_id: shipmentId }),
    },
  );
  return body.data;
}

export async function dismissDocumentMatch(extractionId: string): Promise<DocumentExtraction> {
  const body = await apiFetch<{ data: DocumentExtraction }>(
    `/documents/${extractionId}/dismiss-match`,
    { method: "POST" },
  );
  return body.data;
}
