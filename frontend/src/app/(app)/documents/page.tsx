"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageDocumentProcessing } from "@/lib/permissions";
import type {
  DocumentAccuracy,
  DocumentExtraction,
  Organization,
} from "@/lib/types";
import {
  DOCUMENT_CLASSIFICATIONS,
  DOCUMENT_STATUSES,
  dismissDocumentMatch,
  getDocumentAccuracy,
  linkDocumentShipment,
  listDocuments,
  listVerificationQueue,
  processDocument,
  rejectDocument,
  verifyDocument,
  verifyDocumentField,
} from "@/lib/document-processing";

/**
 * AI document processing for one organization: OCR extraction,
 * classification, field extraction, shipment matching,
 * duplicate detection, human verification, and measured
 * accuracy.
 *
 * There is no live OCR service: the local provider reads text
 * a document already carries and cannot read scanned images,
 * so a scanned image fails processing with a plain reason.
 * Only an exact reference match auto-links a shipment;
 * anything weaker waits in the verification queue. A
 * human-verified value is what downstream features use.
 * Processing and verification need manage-document-processing
 * and the Starter plan or better; the Free plan is refused
 * with a 402. Any member may read extractions and accuracy.
 */

const CLASSIFICATION_LABELS: Record<string, string> = Object.fromEntries(
  DOCUMENT_CLASSIFICATIONS.map((item) => [item.value, item.label]),
);

const STATUS_LABELS: Record<string, string> = Object.fromEntries(
  DOCUMENT_STATUSES.map((item) => [item.value, item.label]),
);

function accuracyText(accuracy: number | null): string {
  return accuracy === null ? "No decisions yet" : `${accuracy.toFixed(2)}%`;
}

function DocumentsContent({ org }: { org: Organization }) {
  const [documents, setDocuments] = useState<DocumentExtraction[]>([]);
  const [queue, setQueue] = useState<DocumentExtraction[]>([]);
  const [accuracy, setAccuracy] = useState<DocumentAccuracy | null>(null);
  const [selected, setSelected] = useState<DocumentExtraction | null>(null);
  const [view, setView] = useState<"all" | "queue">("all");
  const [statusFilter, setStatusFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [busyFieldId, setBusyFieldId] = useState<string | null>(null);

  const [evidenceFileId, setEvidenceFileId] = useState("");
  const [correctedValues, setCorrectedValues] = useState<Record<string, string>>({});
  const [shipmentId, setShipmentId] = useState("");
  const [rejectReason, setRejectReason] = useState("");
  const [verifyClassification, setVerifyClassification] = useState("");

  const isManager = canManageDocumentProcessing(org.current_user_role);

  async function refresh(): Promise<void> {
    setLoading(true);
    setError(null);
    try {
      const [docs, queued, acc] = await Promise.all([
        listDocuments(statusFilter === "" ? {} : { status: statusFilter }),
        listVerificationQueue(),
        getDocumentAccuracy(),
      ]);
      setDocuments(docs);
      setQueue(queued);
      setAccuracy(acc);
    } catch (err: unknown) {
      setError(err instanceof ApiRequestError ? err.message : "Could not load documents.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.all([
      listDocuments(statusFilter === "" ? {} : { status: statusFilter }),
      listVerificationQueue(),
      getDocumentAccuracy(),
    ])
      .then(([docs, queued, acc]) => {
        if (cancelled) return;
        setDocuments(docs);
        setQueue(queued);
        setAccuracy(acc);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load documents.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  async function handleProcess(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (evidenceFileId.trim() === "") return;
    setBusy(true);
    setFormError(null);
    try {
      const created = await processDocument(evidenceFileId.trim());
      setEvidenceFileId("");
      await refresh();
      setSelected(created);
      setView("all");
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not process the document.");
    } finally {
      setBusy(false);
    }
  }

  async function handleFieldDecision(
    fieldId: string,
    decision: "verified" | "corrected" | "rejected",
  ): Promise<void> {
    if (selected === null) return;
    const corrected = correctedValues[fieldId];
    if (decision === "corrected" && (corrected === undefined || corrected.trim() === "")) {
      setFormError("Enter the corrected value first.");
      return;
    }
    setBusyFieldId(fieldId);
    setFormError(null);
    try {
      await verifyDocumentField(
        selected.id,
        fieldId,
        decision,
        decision === "corrected" ? corrected.trim() : undefined,
      );
      const updated = await listDocuments(statusFilter === "" ? {} : { status: statusFilter });
      setDocuments(updated);
      const fresh = updated.find((item) => item.id === selected.id) ?? null;
      if (fresh !== null) setSelected(fresh);
      const queued = await listVerificationQueue();
      setQueue(queued);
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not save the decision.");
    } finally {
      setBusyFieldId(null);
    }
  }

  async function handleVerify(): Promise<void> {
    if (selected === null) return;
    setBusy(true);
    setFormError(null);
    try {
      const verified = await verifyDocument(
        selected.id,
        verifyClassification === "" ? undefined : verifyClassification,
      );
      setSelected(verified);
      await refresh();
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not verify the document.");
    } finally {
      setBusy(false);
    }
  }

  async function handleReject(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (selected === null || rejectReason.trim() === "") return;
    setBusy(true);
    setFormError(null);
    try {
      const rejected = await rejectDocument(selected.id, rejectReason.trim());
      setRejectReason("");
      setSelected(rejected);
      await refresh();
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not reject the document.");
    } finally {
      setBusy(false);
    }
  }

  async function handleLinkShipment(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (selected === null || shipmentId.trim() === "") return;
    setBusy(true);
    setFormError(null);
    try {
      const linked = await linkDocumentShipment(selected.id, shipmentId.trim());
      setShipmentId("");
      setSelected(linked);
      await refresh();
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not link the shipment.");
    } finally {
      setBusy(false);
    }
  }

  async function handleDismissMatch(): Promise<void> {
    if (selected === null) return;
    setBusy(true);
    setFormError(null);
    try {
      const dismissed = await dismissDocumentMatch(selected.id);
      setSelected(dismissed);
      await refresh();
    } catch (err: unknown) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not dismiss the match.");
    } finally {
      setBusy(false);
    }
  }

  const shown = view === "queue" ? queue : documents;
  const pendingFields =
    selected === null
      ? 0
      : selected.fields.filter((field) => field.verification_status === "pending").length;

  return (
    <div>
      {error !== null ? <Alert tone="error">{error}</Alert> : null}
      {formError !== null ? <Alert tone="error">{formError}</Alert> : null}

      {accuracy !== null ? (
        <Card>
          <CardHeader title="Extraction accuracy" />
          <CardBody>
            <p className="text-sm text-slate-600">
              Measured only from human decisions: a field counts as agreed when a person verified
              it as-is. Corrected and rejected fields count as disagreed. Rates are empty until
              someone has decided a field — never a claimed benchmark.
            </p>
            <div className="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
              <div>
                <div className="text-slate-500">Overall accuracy</div>
                <div className="text-lg font-semibold">{accuracyText(accuracy.overall.accuracy)}</div>
                <div className="text-slate-500">
                  {accuracy.overall.agreed} of {accuracy.overall.decided} fields agreed
                </div>
              </div>
              <div>
                <div className="text-slate-500">Documents processed</div>
                <div className="text-lg font-semibold">{accuracy.counts.total_extractions}</div>
              </div>
              <div>
                <div className="text-slate-500">Waiting for review</div>
                <div className="text-lg font-semibold">{accuracy.counts.needs_verification}</div>
              </div>
              <div>
                <div className="text-slate-500">Duplicates flagged</div>
                <div className="text-lg font-semibold">{accuracy.counts.duplicates}</div>
              </div>
            </div>
            {Object.keys(accuracy.by_field).length > 0 ? (
              <Table headers={["Field", "Decided", "Agreed", "Accuracy"]}>
                {Object.entries(accuracy.by_field).map(([name, bucket]) => (
                  <tr key={name} className="border-b border-slate-100 last:border-0">
                    <TableCell>{name}</TableCell>
                    <TableCell>{bucket.decided}</TableCell>
                    <TableCell>{bucket.agreed}</TableCell>
                    <TableCell>{accuracyText(bucket.accuracy)}</TableCell>
                  </tr>
                ))}
              </Table>
            ) : null}
          </CardBody>
        </Card>
      ) : null}

      {isManager ? (
        <Card>
          <CardHeader title="Process a document" />
          <CardBody>
            <p className="text-sm text-slate-600">
              Process an evidence file you already uploaded: paste its evidence file id. The
              local provider reads the text the document already carries; scanned images have no
              text layer and fail with a plain reason.
            </p>
            <form onSubmit={handleProcess} className="mt-3 flex flex-col gap-3 sm:flex-row">
              <Input
                id="evidence-file-id"
                label="Evidence file id"
                value={evidenceFileId}
                onChange={(event) => setEvidenceFileId(event.target.value)}
                placeholder="00000000-0000-0000-0000-000000000000"
              />
              <div className="flex items-end">
                <Button type="submit" disabled={busy || evidenceFileId.trim() === ""}>
                  {busy ? "Processing…" : "Process document"}
                </Button>
              </div>
            </form>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title={view === "queue" ? "Verification queue" : "Processed documents"} />
        <CardBody>
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <Button variant={view === "all" ? "primary" : "secondary"} onClick={() => setView("all")}>
              All documents
            </Button>
            <Button
              variant={view === "queue" ? "primary" : "secondary"}
              onClick={() => setView("queue")}
            >
              Verification queue ({queue.length})
            </Button>
            <select
              aria-label="Filter by status"
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
              className="rounded border border-slate-300 px-2 py-2 text-sm"
            >
              <option value="">All statuses</option>
              {DOCUMENT_STATUSES.map((status) => (
                <option key={status.value} value={status.value}>
                  {status.label}
                </option>
              ))}
            </select>
          </div>
          {loading ? (
            <Spinner />
          ) : shown.length === 0 ? (
            <EmptyState
              title={view === "queue" ? "Nothing waiting for review" : "No documents processed yet"}
              description={
                view === "queue"
                  ? "Documents that need a human land here: unclassified documents, low-confidence fields, unmatched references, and duplicates."
                  : "Process an evidence file to see its classification, extracted fields, and shipment match here."
              }
            />
          ) : (
            <Table headers={["Document", "Type", "Status", "Shipment", "Duplicate", ""]}>
              {shown.map((doc) => (
                <tr key={doc.id} className="border-b border-slate-100 last:border-0">
                  <TableCell>{doc.original_filename}</TableCell>
                  <TableCell>
                    {doc.classification_label ?? "—"}
                    {doc.classification_confidence !== null
                      ? ` (${doc.classification_confidence.toFixed(0)}%)`
                      : ""}
                  </TableCell>
                  <TableCell>{doc.status_label}</TableCell>
                  <TableCell>{doc.matched_shipment_reference ?? "—"}</TableCell>
                  <TableCell>{doc.is_duplicate ? "Yes" : "No"}</TableCell>
                  <TableCell>
                    <Button variant="secondary" onClick={() => setSelected(doc)}>
                      Review
                    </Button>
                  </TableCell>
                </tr>
              ))}
            </Table>
          )}
        </CardBody>
      </Card>

      {selected !== null ? (
        <Card>
          <CardHeader title={`Review: ${selected.original_filename}`} />
          <CardBody>
            <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
              <div>
                <div className="text-slate-500">Type</div>
                <div>{selected.classification_label ?? "—"}</div>
              </div>
              <div>
                <div className="text-slate-500">Status</div>
                <div>{selected.status_label}</div>
              </div>
              <div>
                <div className="text-slate-500">Provider</div>
                <div>{selected.provider}</div>
              </div>
              <div>
                <div className="text-slate-500">Shipment match</div>
                <div>
                  {selected.matched_shipment_reference ?? "—"}
                  {selected.match_method !== "none" ? ` (${selected.match_method})` : ""}
                </div>
              </div>
            </div>
            {selected.is_duplicate ? (
              <Alert tone="warning">
                Duplicate of {selected.duplicate_of_filename ?? "an earlier document"}: the same
                file contents were already processed. Duplicates are flagged, never deleted.
              </Alert>
            ) : null}
            {selected.near_duplicate_of_id !== null ? (
              <Alert tone="warning">{selected.near_duplicate_reason}</Alert>
            ) : null}
            {selected.failure_reason !== null ? (
              <Alert tone="error">{selected.failure_reason}</Alert>
            ) : null}

            <h3 className="mt-4 text-sm font-semibold">Extracted fields</h3>
            {selected.fields.length === 0 ? (
              <p className="text-sm text-slate-600">No fields were extracted from this document.</p>
            ) : (
              <Table headers={["Field", "Value", "Confidence", "Decision", ""]}>
                {selected.fields.map((field) => (
                  <tr key={field.id} className="border-b border-slate-100 last:border-0">
                    <TableCell>{field.field_label}</TableCell>
                    <TableCell>
                      {field.field_value ?? "—"}
                      {field.verified_value !== null && field.verified_value !== field.field_value ? (
                        <span className="block text-slate-500">
                          Corrected to: {field.verified_value}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell>{field.confidence.toFixed(0)}%</TableCell>
                    <TableCell>{field.verification_status_label}</TableCell>
                    <TableCell>
                      {isManager && field.verification_status === "pending" ? (
                        <div className="flex flex-col gap-2">
                          <div className="flex gap-2">
                            <Button
                              variant="secondary"
                              disabled={busyFieldId === field.id}
                              onClick={() => handleFieldDecision(field.id, "verified")}
                            >
                              Verify
                            </Button>
                            <Button
                              variant="secondary"
                              disabled={busyFieldId === field.id}
                              onClick={() => handleFieldDecision(field.id, "rejected")}
                            >
                              Reject
                            </Button>
                          </div>
                          <div className="flex gap-2">
                            <Input
                              id={`corrected-${field.id}`}
                              label="Corrected value"
                              value={correctedValues[field.id] ?? ""}
                              onChange={(event) =>
                                setCorrectedValues((current) => ({
                                  ...current,
                                  [field.id]: event.target.value,
                                }))
                              }
                            />
                            <div className="flex items-end">
                              <Button
                                variant="secondary"
                                disabled={busyFieldId === field.id}
                                onClick={() => handleFieldDecision(field.id, "corrected")}
                              >
                                Correct
                              </Button>
                            </div>
                          </div>
                        </div>
                      ) : null}
                    </TableCell>
                  </tr>
                ))}
              </Table>
            )}

            {selected.match_candidates.length > 0 && selected.matched_shipment_id === null ? (
              <div className="mt-4">
                <h3 className="text-sm font-semibold">Reference candidates</h3>
                <p className="text-sm text-slate-600">
                  No candidate matched a shipment exactly. Link one by hand, or dismiss the match
                  when this document belongs to no shipment.
                </p>
                <ul className="mt-2 list-disc pl-5 text-sm">
                  {selected.match_candidates.map((candidate) => (
                    <li key={candidate.value}>
                      {candidate.value} ({candidate.source})
                    </li>
                  ))}
                </ul>
                {isManager ? (
                  <form onSubmit={handleLinkShipment} className="mt-3 flex flex-col gap-3 sm:flex-row">
                    <Input
                      id="link-shipment-id"
                      label="Shipment id to link"
                      value={shipmentId}
                      onChange={(event) => setShipmentId(event.target.value)}
                      placeholder="00000000-0000-0000-0000-000000000000"
                    />
                    <div className="flex items-end gap-2">
                      <Button type="submit" disabled={busy || shipmentId.trim() === ""}>
                        Link shipment
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        disabled={busy}
                        onClick={handleDismissMatch}
                      >
                        Dismiss match
                      </Button>
                    </div>
                  </form>
                ) : null}
              </div>
            ) : null}

            {selected.extracted_text !== null && selected.extracted_text !== "" ? (
              <div className="mt-4">
                <h3 className="text-sm font-semibold">Extracted text</h3>
                <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-3 text-xs text-slate-700">
                  {selected.extracted_text}
                </pre>
              </div>
            ) : null}

            {isManager && selected.status === "needs_verification" ? (
              <div className="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4">
                <p className="text-sm text-slate-600">
                  {pendingFields} field{pendingFields === 1 ? "" : "s"} still need
                  {pendingFields === 1 ? "s" : ""} a decision.
                  {selected.classification === "unclassified"
                    ? " This document also needs a type before it can be verified."
                    : ""}
                </p>
                {selected.classification === "unclassified" ? (
                  <div className="flex gap-2">
                    <select
                      aria-label="Document type"
                      value={verifyClassification}
                      onChange={(event) => setVerifyClassification(event.target.value)}
                      className="rounded border border-slate-300 px-2 py-2 text-sm"
                    >
                      <option value="">Choose a type…</option>
                      {DOCUMENT_CLASSIFICATIONS.filter((item) => item.value !== "unclassified").map(
                        (item) => (
                          <option key={item.value} value={item.value}>
                            {item.label}
                          </option>
                        ),
                      )}
                    </select>
                  </div>
                ) : null}
                <div className="flex gap-2">
                  <Button onClick={handleVerify} disabled={busy}>
                    {busy ? "Verifying…" : "Verify document"}
                  </Button>
                </div>
                <form onSubmit={handleReject} className="flex flex-col gap-3 sm:flex-row">
                  <Input
                    id="reject-reason"
                    label="Reject reason"
                    value={rejectReason}
                    onChange={(event) => setRejectReason(event.target.value)}
                    placeholder="Why is this extraction not usable?"
                  />
                  <div className="flex items-end">
                    <Button
                      type="submit"
                      variant="secondary"
                      disabled={busy || rejectReason.trim() === ""}
                    >
                      Reject document
                    </Button>
                  </div>
                </form>
              </div>
            ) : null}
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}

export default function DocumentsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Document Processing"
        description="Extract text, type, fields, and shipment references from your documents — with a human in the loop. The local provider reads text a document already carries; it cannot OCR scanned images, and only an exact reference match auto-links a shipment."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Document Processing" },
        ]}
      />
      {currentOrg ? (
        <DocumentsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use document processing.</Alert>
      )}
    </div>
  );
}
