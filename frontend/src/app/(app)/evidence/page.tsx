"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { EvidencePreviewModal } from "@/components/evidence/EvidencePreviewModal";
import { apiDownload, apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canDeleteEvidence, canVerifyEvidence } from "@/lib/permissions";
import {
  DOCUMENT_CATEGORIES,
  downloadBlob,
  formatFileSize,
  shortId,
  VERIFICATION_STATUS_LABELS,
  verificationStatusTone,
} from "@/lib/shipments";
import type {
  EvidenceFile,
  Organization,
  PaginatedMeta,
  Shipment,
  VerificationStatus,
} from "@/lib/types";

const VERIFICATION_STATUSES: VerificationStatus[] = [
  "pending",
  "verified",
  "rejected",
];

/**
 * Evidence library for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with fresh
 * state.
 */
function EvidenceContent({ org }: { org: Organization }) {
  const [files, setFiles] = useState<EvidenceFile[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [shipmentFilter, setShipmentFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [verificationFilter, setVerificationFilter] = useState("");
  const [page, setPage] = useState(1);
  const [verifyingId, setVerifyingId] = useState<string | null>(null);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<EvidenceFile | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [previewFile, setPreviewFile] = useState<EvidenceFile | null>(null);

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: Shipment[] }>("/shipments?per_page=100", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setShipments(response.data);
      })
      .catch(() => {
        // The shipment filter is a convenience; the library still works
        // without it.
        if (!cancelled) setShipments([]);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (shipmentFilter) params.set("shipment_id", shipmentFilter);
    if (categoryFilter) params.set("document_category", categoryFilter);
    if (verificationFilter) {
      params.set("verification_status", verificationFilter);
    }

    apiFetch<{ data: EvidenceFile[]; meta: PaginatedMeta }>(
      `/evidence?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setFiles(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load evidence.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, page, shipmentFilter, categoryFilter, verificationFilter]);

  async function handleDownload(file: EvidenceFile) {
    setError(null);
    setDownloadingId(file.id);
    try {
      const blob = await apiDownload(`/evidence/${file.id}/download`, {
        orgId: org.id,
      });
      downloadBlob(blob, file.original_filename);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not download the file.",
      );
    } finally {
      setDownloadingId(null);
    }
  }

  async function handleVerify(file: EvidenceFile, status: VerificationStatus) {
    setError(null);
    setNotice(null);
    setVerifyingId(file.id);
    try {
      const response = await apiFetch<{ data: EvidenceFile }>(
        `/evidence/${file.id}/verification`,
        {
          method: "PUT",
          body: { verification_status: status },
          orgId: org.id,
        },
      );
      setFiles((current) =>
        current.map((item) => (item.id === file.id ? response.data : item)),
      );
      setNotice(
        `${file.original_filename} marked ${VERIFICATION_STATUS_LABELS[status].toLowerCase()}.`,
      );
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not update the verification status.",
      );
    } finally {
      setVerifyingId(null);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await apiFetch(`/evidence/${deleteTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setFiles((current) =>
        current.filter((item) => item.id !== deleteTarget.id),
      );
      setNotice(`Deleted ${deleteTarget.original_filename}.`);
      setDeleteTarget(null);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not delete the file.",
      );
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  }

  function shipmentLabel(file: EvidenceFile): string {
    const shipment = shipments.find((item) => item.id === file.shipment_id);
    return shipment ? shipment.internal_reference : `#${shortId(file.shipment_id)}`;
  }

  return (
    <div>
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading evidence" />
      ) : (
        <Card>
          <CardHeader
            title="Evidence"
            description="Every uploaded document across all shipments. Upload new files from a shipment page."
          />
          <div className="grid gap-3 border-b border-slate-200 px-5 py-4 sm:grid-cols-3">
            <Select
              id="shipment-filter"
              label="Shipment"
              value={shipmentFilter}
              onChange={(event) => {
                setShipmentFilter(event.target.value);
                setPage(1);
              }}
            >
              <option value="">All shipments</option>
              {shipments.map((shipment) => (
                <option key={shipment.id} value={shipment.id}>
                  {shipment.internal_reference}
                </option>
              ))}
            </Select>
            <Select
              id="category-filter"
              label="Category"
              value={categoryFilter}
              onChange={(event) => {
                setCategoryFilter(event.target.value);
                setPage(1);
              }}
            >
              <option value="">All categories</option>
              {DOCUMENT_CATEGORIES.map((category) => (
                <option key={category.value} value={category.value}>
                  {category.label}
                </option>
              ))}
            </Select>
            <Select
              id="verification-filter"
              label="Verification status"
              value={verificationFilter}
              onChange={(event) => {
                setVerificationFilter(event.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              {VERIFICATION_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {VERIFICATION_STATUS_LABELS[status]}
                </option>
              ))}
            </Select>
          </div>

          {files.length === 0 ? (
            <EmptyState
              title="No evidence found"
              description="Try a different filter, or upload a file from a shipment page."
            />
          ) : (
            <>
              <Table
                headers={[
                  "File",
                  "Category",
                  "Shipment",
                  "Size",
                  "Uploaded by",
                  "Verification",
                  "",
                ]}
              >
                {files.map((file) => (
                  <tr key={file.id}>
                    <TableCell>
                      <p className="font-medium text-slate-900">
                        {file.original_filename}
                      </p>
                      {file.description && (
                        <p className="text-xs text-slate-500">
                          {file.description}
                        </p>
                      )}
                      <p className="text-xs text-slate-500">
                        {new Date(file.created_at).toLocaleDateString()}
                      </p>
                    </TableCell>
                    <TableCell>{file.document_category_label}</TableCell>
                    <TableCell>
                      <Link
                        href={`/shipments/${file.shipment_id}`}
                        className="text-brand-700 hover:underline"
                      >
                        {shipmentLabel(file)}
                      </Link>
                    </TableCell>
                    <TableCell>{formatFileSize(file.file_size)}</TableCell>
                    <TableCell>{file.uploaded_by?.name ?? "Unknown"}</TableCell>
                    <TableCell>
                      {canVerifyEvidence(myRole) ? (
                        <select
                          aria-label={`Verification status for ${file.original_filename}`}
                          value={file.verification_status}
                          disabled={verifyingId === file.id}
                          onChange={(event) =>
                            handleVerify(
                              file,
                              event.target.value as VerificationStatus,
                            )
                          }
                          className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm disabled:opacity-60"
                        >
                          {VERIFICATION_STATUSES.map((status) => (
                            <option key={status} value={status}>
                              {VERIFICATION_STATUS_LABELS[status]}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <Badge
                          tone={verificationStatusTone(file.verification_status)}
                        >
                          {VERIFICATION_STATUS_LABELS[file.verification_status]}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell>
                      <div className="flex gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={downloadingId === file.id}
                          onClick={() => setPreviewFile(file)}
                        >
                          Preview
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={downloadingId === file.id}
                          onClick={() => handleDownload(file)}
                        >
                          Download
                        </Button>
                        {canDeleteEvidence(myRole) && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDeleteTarget(file)}
                          >
                            Delete
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </tr>
                ))}
              </Table>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    files)
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page <= 1}
                      onClick={() => setPage((current) => current - 1)}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page >= meta.last_page}
                      onClick={() => setPage((current) => current + 1)}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </Card>
      )}

      <Modal
        open={deleteTarget !== null}
        title="Delete evidence"
        description={
          deleteTarget
            ? `Delete ${deleteTarget.original_filename}? The file will be removed permanently.`
            : undefined
        }
        confirmLabel="Delete file"
        danger
        loading={deleting}
        onConfirm={confirmDelete}
        onClose={() => setDeleteTarget(null)}
      />
      <EvidencePreviewModal
        file={previewFile}
        orgId={org.id}
        onClose={() => setPreviewFile(null)}
      />
    </div>
  );
}

export default function EvidencePage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Evidence"
        description={
          currentOrg
            ? `Documents for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Evidence" },
        ]}
      />
      {currentOrg ? (
        <EvidenceContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to view evidence.</Alert>
      )}
    </div>
  );
}
