"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Textarea } from "@/components/ui/Input";
import { readinessStatusTone } from "@/lib/claims";
import type { Readiness } from "@/lib/types";

interface ReadinessPanelProps {
  readiness: Readiness;
  claimId: string;
  /** Whether the current user may override the readiness assessment. */
  canOverride: boolean;
  overriding: boolean;
  overrideError?: string | null;
  onOverride: (reason: string) => Promise<void>;
}

function CategoryList({
  title,
  items,
  emptyText,
}: {
  title: string;
  items: { value: string; label: string }[];
  emptyText: string;
}) {
  return (
    <div>
      <h3 className="text-sm font-medium text-slate-700">{title}</h3>
      {items.length === 0 ? (
        <p className="mt-1 text-sm text-slate-500">{emptyText}</p>
      ) : (
        <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-700">
          {items.map((item) => (
            <li key={item.value}>{item.label}</li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Readiness panel for a claim: percentage, required/collected/missing
 * document lists, the API disclaimer shown verbatim, and the override
 * form for reviewers.
 */
export function ReadinessPanel({
  readiness,
  claimId,
  canOverride,
  overriding,
  overrideError,
  onOverride,
}: ReadinessPanelProps) {
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string | null>(null);

  async function handleOverride(event: FormEvent) {
    event.preventDefault();
    if (!reason.trim()) {
      setReasonError("A reason is required to override readiness.");
      return;
    }
    setReasonError(null);
    await onOverride(reason.trim());
    setReason("");
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-4">
        <p className="text-4xl font-semibold text-slate-900">
          {readiness.readiness_percentage}%
        </p>
        <Badge tone={readinessStatusTone(readiness.readiness_status)}>
          {readiness.readiness_status_label}
        </Badge>
        {readiness.is_overridden && (
          <Badge tone="warning">Overridden by a reviewer</Badge>
        )}
      </div>

      <div
        className="h-2.5 w-full overflow-hidden rounded-full bg-slate-200"
        role="progressbar"
        aria-valuenow={readiness.readiness_percentage}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Readiness percentage"
      >
        <div
          className="h-full rounded-full bg-brand-600"
          style={{ width: `${readiness.readiness_percentage}%` }}
        />
      </div>

      <Alert tone="info">{readiness.disclaimer}</Alert>

      {!readiness.has_requirements && (
        <Alert tone="warning">
          No evidence requirements are configured for this claim, so
          readiness is based on the default document list.{" "}
          <Link
            href="/settings/evidence-requirements"
            className="font-medium underline hover:text-brand-900"
          >
            Manage evidence requirements
          </Link>
          .
        </Alert>
      )}

      <div className="grid gap-6 sm:grid-cols-2">
        <CategoryList
          title={`Required documents (${readiness.required.length})`}
          items={readiness.required}
          emptyText="No documents are required for this claim."
        />
        <CategoryList
          title={`Collected (${readiness.collected.length})`}
          items={readiness.collected}
          emptyText="No required documents are attached yet."
        />
        <CategoryList
          title={`Missing (${readiness.missing.length})`}
          items={readiness.missing}
          emptyText="Nothing is missing."
        />
        <CategoryList
          title={`Optional documents (${readiness.optional.length})`}
          items={readiness.optional}
          emptyText="No optional documents are configured."
        />
      </div>

      {readiness.optional_collected.length > 0 && (
        <CategoryList
          title={`Optional documents collected (${readiness.optional_collected.length})`}
          items={readiness.optional_collected}
          emptyText=""
        />
      )}

      {readiness.snapshots.length > 0 && (
        <div>
          <h3 className="text-sm font-medium text-slate-700">
            Requirement details
          </h3>
          <ul className="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
            {readiness.snapshots.map((snapshot) => (
              <li
                key={snapshot.document_category}
                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
              >
                <div>
                  <p className="font-medium text-slate-900">
                    {snapshot.document_category_label}
                  </p>
                  {snapshot.override_reason && (
                    <p className="mt-0.5 text-xs text-slate-500">
                      Overridden by {snapshot.overridden_by?.name ?? "a reviewer"}
                      : {snapshot.override_reason}
                    </p>
                  )}
                </div>
                <div className="flex gap-2">
                  <Badge tone={snapshot.is_required ? "warning" : "neutral"}>
                    {snapshot.is_required ? "Required" : "Optional"}
                  </Badge>
                  <Badge tone={snapshot.is_fulfilled ? "success" : "danger"}>
                    {snapshot.is_fulfilled ? "Fulfilled" : "Missing"}
                  </Badge>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="border-t border-slate-200 pt-4">
        <p className="text-sm text-slate-500">
          <Link
            href={`/claims/${claimId}`}
            className="text-brand-700 hover:underline"
          >
            Back to the claim
          </Link>{" "}
          to attach evidence, or{" "}
          <Link
            href="/settings/evidence-requirements"
            className="text-brand-700 hover:underline"
          >
            manage evidence requirements
          </Link>{" "}
          to change what a claim needs.
        </p>
      </div>

      {canOverride ? (
        <form
          onSubmit={handleOverride}
          className="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4"
          noValidate
        >
          <h3 className="text-sm font-semibold text-slate-900">
            Override readiness
          </h3>
          <p className="text-sm text-slate-600">
            Overriding marks this claim ready even when documents are
            missing. Your name and the reason below are recorded with the
            override.
          </p>
          {overrideError && <Alert tone="error">{overrideError}</Alert>}
          <Textarea
            id="override-reason"
            label="Reason"
            rows={3}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            error={reasonError ?? undefined}
            placeholder="Why is this claim ready to submit without the missing documents?"
          />
          <Button type="submit" loading={overriding}>
            Override readiness
          </Button>
        </form>
      ) : (
        <p className="text-sm text-slate-500">
          Only owners, admins, and operations managers can override
          readiness.
        </p>
      )}
    </div>
  );
}
