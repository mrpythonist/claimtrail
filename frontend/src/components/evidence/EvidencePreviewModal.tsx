"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { apiDownload, ApiRequestError } from "@/lib/api";
import { downloadBlob } from "@/lib/shipments";
import type { EvidenceFile } from "@/lib/types";

/**
 * Loads the preview bytes for one previewable file and renders them.
 * Keyed by file id by the parent, so a new file mounts with fresh
 * state instead of flashing the previous file's preview.
 */
function PreviewContent({ file, orgId }: { file: EvidenceFile; orgId: string }) {
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    apiDownload(`/evidence/${file.id}/preview`, { orgId })
      .then((blob) => {
        if (cancelled) return;
        if (typeof URL.createObjectURL !== "function") {
          setError(
            "Preview is not supported in this browser. Download the file instead.",
          );
          return;
        }
        objectUrl = URL.createObjectURL(blob);
        setPreviewUrl(objectUrl);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the preview.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [file, orgId]);

  if (loading) return <Spinner label="Loading preview" />;
  if (error) return <Alert tone="error">{error}</Alert>;
  if (!previewUrl) {
    return (
      <p className="text-sm text-slate-500">
        Preview could not be loaded. Use the Download button below.
      </p>
    );
  }
  if (file.mime_type === "application/pdf") {
    return (
      <iframe
        src={previewUrl}
        title={`Preview of ${file.original_filename}`}
        className="h-[60vh] w-full rounded-md border border-slate-200"
      />
    );
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={previewUrl}
      alt={file.original_filename}
      className="max-h-[60vh] w-full rounded-md border border-slate-200 object-contain"
    />
  );
}

/**
 * Inline preview for an evidence file, shown in a modal. Images and
 * PDFs load from GET /evidence/{id}/preview; other file types show a
 * message with a download button instead. The object URL is revoked
 * when the preview unmounts.
 */
export function EvidencePreviewModal({
  file,
  orgId,
  onClose,
}: {
  file: EvidenceFile | null;
  orgId: string;
  onClose: () => void;
}) {
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const previewable = file?.previewable ?? false;

  const handleDownload = useCallback(async () => {
    if (!file) return;
    setDownloading(true);
    setDownloadError(null);
    try {
      const blob = await apiDownload(`/evidence/${file.id}/download`, {
        orgId,
      });
      downloadBlob(blob, file.original_filename);
    } catch (err) {
      setDownloadError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not download the file.",
      );
    } finally {
      setDownloading(false);
    }
  }, [file, orgId]);

  if (!file) return null;

  return (
    <Modal
      open
      title={file.original_filename}
      description={
        previewable
          ? undefined
          : "Preview is not available for this file type. Download it to view it."
      }
      confirmLabel="Download"
      cancelLabel="Close"
      loading={downloading}
      onConfirm={handleDownload}
      onClose={onClose}
    >
      {downloadError && <Alert tone="error">{downloadError}</Alert>}
      {previewable ? (
        <PreviewContent key={file.id} file={file} orgId={orgId} />
      ) : (
        <Button
          variant="secondary"
          loading={downloading}
          onClick={handleDownload}
        >
          Download {file.original_filename}
        </Button>
      )}
    </Modal>
  );
}
