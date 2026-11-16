import type {
  DocumentCategory,
  EventStatus,
  ShipmentStatus,
  VerificationStatus,
} from "./types";

/**
 * Shared Milestone 2 helpers: shipment status flow, document category
 * labels, file handling, and badge tones. Nothing here calls the API.
 */

export const SHIPMENT_STATUS_CHAIN: ShipmentStatus[] = [
  "draft",
  "planned",
  "in_transit",
  "delivered",
  "completed",
];

export const SHIPMENT_STATUS_LABELS: Record<ShipmentStatus, string> = {
  draft: "Draft",
  planned: "Planned",
  in_transit: "In Transit",
  delivered: "Delivered",
  completed: "Completed",
  archived: "Archived",
};

/**
 * The next status in the forward-only chain, or null when the shipment
 * is completed or archived and has nowhere to go.
 */
export function nextShipmentStatus(status: ShipmentStatus): ShipmentStatus | null {
  const index = SHIPMENT_STATUS_CHAIN.indexOf(status);
  if (index === -1 || index === SHIPMENT_STATUS_CHAIN.length - 1) return null;
  return SHIPMENT_STATUS_CHAIN[index + 1];
}

export type BadgeTone = "neutral" | "success" | "warning" | "danger" | "info";

export function shipmentStatusTone(status: ShipmentStatus): BadgeTone {
  switch (status) {
    case "completed":
      return "success";
    case "in_transit":
    case "delivered":
      return "info";
    case "planned":
      return "warning";
    case "archived":
      return "danger";
    default:
      return "neutral";
  }
}

export const EVENT_STATUS_LABELS: Record<EventStatus, string> = {
  potential: "Potential",
  under_review: "Under Review",
  dismissed: "Dismissed",
};

export function eventStatusTone(status: EventStatus): BadgeTone {
  switch (status) {
    case "under_review":
      return "warning";
    case "dismissed":
      return "neutral";
    default:
      return "info";
  }
}

export const VERIFICATION_STATUS_LABELS: Record<VerificationStatus, string> = {
  pending: "Pending",
  verified: "Verified",
  rejected: "Rejected",
};

export function verificationStatusTone(status: VerificationStatus): BadgeTone {
  switch (status) {
    case "verified":
      return "success";
    case "rejected":
      return "danger";
    default:
      return "warning";
  }
}

export const DOCUMENT_CATEGORIES: { value: DocumentCategory; label: string }[] = [
  { value: "bill_of_lading", label: "Bill of Lading" },
  { value: "proof_of_delivery", label: "Proof of Delivery" },
  { value: "rate_confirmation", label: "Rate Confirmation" },
  { value: "arrival_departure_record", label: "Arrival / Departure Record" },
  { value: "appointment_confirmation", label: "Appointment Confirmation" },
  { value: "broker_communication", label: "Broker Communication" },
  { value: "driver_photo", label: "Driver Photo" },
  { value: "receipt", label: "Receipt" },
  { value: "lumper_receipt", label: "Lumper Receipt" },
  { value: "other", label: "Other" },
];

export function documentCategoryLabel(category: string): string {
  return (
    DOCUMENT_CATEGORIES.find((item) => item.value === category)?.label ?? category
  );
}

export const MAX_EVIDENCE_BYTES = 20 * 1024 * 1024;

export const ALLOWED_EVIDENCE_EXTENSIONS = [
  "pdf",
  "jpg",
  "jpeg",
  "png",
  "webp",
  "doc",
  "docx",
  "xls",
  "xlsx",
  "csv",
  "txt",
];

export function evidenceFileError(file: File): string | null {
  const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
  if (!ALLOWED_EVIDENCE_EXTENSIONS.includes(extension)) {
    return "That file type is not allowed. Use pdf, jpg, png, webp, doc, docx, xls, xlsx, csv, or txt.";
  }
  if (file.size > MAX_EVIDENCE_BYTES) {
    return "That file is larger than the 20 MB limit.";
  }
  return null;
}

/** Images and PDFs can be previewed inline in a new tab. */
export function isPreviewableMime(mimeType: string): boolean {
  return mimeType.startsWith("image/") || mimeType === "application/pdf";
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Triggers a browser download for a blob fetched from the API. */
export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/** Opens a blob in a new tab (used for image and PDF previews). */
export function openBlobInNewTab(blob: Blob): void {
  const url = URL.createObjectURL(blob);
  window.open(url, "_blank", "noopener");
  // Revoke after a delay so the new tab has time to load the blob.
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Short display form of an id (a number, or the first 8 chars of a UUID). */
export function shortId(id: number | string): string {
  const text = String(id);
  return text.length > 12 ? text.slice(0, 8) : text;
}

/** Converts an ISO datetime from the API to a datetime-local input value. */
export function isoToInputValue(iso: string | null): string {
  if (!iso) return "";
  return iso.slice(0, 16);
}

/** Converts a datetime-local input value to an ISO datetime for the API. */
export function inputValueToIso(value: string): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return date.toISOString();
}

/** Blank form strings become null so the API sees absent optional fields. */
export function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}
