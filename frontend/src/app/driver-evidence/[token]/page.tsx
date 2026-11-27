"use client";

import { useParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input, Select } from "@/components/ui/Input";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { getDriverEvidencePage, submitDriverEvidence } from "@/lib/comms";
import { DOCUMENT_CATEGORIES } from "@/lib/shipments";
import type { DriverEvidencePage } from "@/lib/types";

/**
 * Public driver evidence page. The link token in the
 * URL is the only credential: the page shows only the
 * shipment or claim the link is scoped to, and an
 * unknown, expired, used-up, or revoked token shows the
 * same invalid message as a token that never
 * existed. No account, no sign-in, no access to
 * anything else in the organization.
 *
 * Categories are the app's evidence document
 * categories: the backend validates the submit against
 * the same list.
 */

function DriverEvidenceContent() {
  const params = useParams();
  const token = typeof params.token === "string" ? params.token : "";
  const [page, setPage] = useState<DriverEvidencePage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [category, setCategory] = useState("proof_of_delivery");
  const [driverName, setDriverName] = useState("");
  const [notes, setNotes] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    if (!token) {
      setLoading(false);
      setError("This evidence link is incomplete.");
      return;
    }
    let cancelled = false;
    getDriverEvidencePage(token)
      .then((response) => {
        if (!cancelled) setPage(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "This submission link is invalid, expired, revoked, or already used.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [token]);

  async function submit() {
    if (!file) {
      setError("Choose a file to upload.");
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      const form = new FormData();
      form.append("document_category", category);
      form.append("file", file);
      if (driverName.trim()) form.append("driver_name", driverName.trim());
      if (notes.trim()) form.append("notes", notes.trim());
      await submitDriverEvidence(token, form);
      setSubmitted(true);
    } catch (err) {
      setError(
        err instanceof ApiRequestError ? err.message : "Could not submit the evidence. Please try again.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return (
      <AuthLayout title="Driver evidence">
        <Spinner label="Checking the link" />
      </AuthLayout>
    );
  }

  if (error || !page) {
    return (
      <AuthLayout title="Driver evidence">
        <Alert tone="error">
          {error ?? "This submission link is invalid, expired, revoked, or already used."}
        </Alert>
      </AuthLayout>
    );
  }

  if (submitted) {
    return (
      <AuthLayout title="Evidence received">
        <Alert tone="success">
          Thank you. Your evidence was received and attached to the shipment. You can close this page.
        </Alert>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Submit evidence"
      description={
        page.shipment_reference
          ? `Shipment ${page.shipment_reference}${page.claim_reference ? ` · Claim ${page.claim_reference}` : ""}`
          : page.claim_reference
            ? `Claim ${page.claim_reference}`
            : "Evidence submission"
      }
    >
      <p className="text-sm text-slate-600">
        This link expires {page.expires_at} and can be used {page.uses_left} more
        {page.uses_left === 1 ? " time" : " times"}. It opens only this shipment or
        claim, nothing else.
      </p>
      <div className="mt-4 space-y-4">
        <Select
          label="Document category"
          id="driver-category"
          value={category}
          onChange={(event) => setCategory(event.target.value)}
        >
          {DOCUMENT_CATEGORIES.map((entry) => (
            <option key={entry.value} value={entry.value}>
              {entry.label}
            </option>
          ))}
        </Select>
        <div>
          <label htmlFor="driver-file" className="block text-sm font-medium text-slate-700">
            File
          </label>
          <input
            id="driver-file"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            className="mt-1 block w-full text-sm text-slate-700"
          />
          <p className="mt-1 text-xs text-slate-500">PDF, image, Office document, CSV, or text file, up to 20 MB.</p>
        </div>
        <Input
          label="Your name"
          id="driver-name"
          value={driverName}
          onChange={(event) => setDriverName(event.target.value)}
          placeholder={page.driver_name ?? "Your name"}
        />
        <Input
          label="Notes"
          id="driver-notes"
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          placeholder="Anything the dispatcher should know"
        />
        {error ? <Alert tone="error">{error}</Alert> : null}
        <Button onClick={submit} disabled={submitting || !file} className="w-full">
          {submitting ? "Submitting…" : "Submit evidence"}
        </Button>
      </div>
    </AuthLayout>
  );
}

export default function DriverEvidencePage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <DriverEvidenceContent />
    </Suspense>
  );
}
