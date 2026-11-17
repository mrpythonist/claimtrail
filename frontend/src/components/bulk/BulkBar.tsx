"use client";

import type { ReactNode } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";

/**
 * Sticky bulk action bar shown above a list when one or more rows are
 * selected. The page supplies the controls (status select, apply and
 * archive/delete buttons) as children; this wrapper owns the count,
 * the clear-selection button, and the result summary.
 */
export function BulkBar({
  selectedCount,
  itemLabel,
  onClearSelection,
  summary,
  summaryTone = "success",
  children,
}: {
  selectedCount: number;
  itemLabel: string;
  onClearSelection: () => void;
  summary?: string | null;
  summaryTone?: "success" | "error" | "info";
  children: ReactNode;
}) {
  if (selectedCount === 0 && !summary) return null;

  return (
    <div className="space-y-3 border-b border-slate-200 bg-brand-50 px-5 py-4">
      {selectedCount > 0 && (
        <div className="flex flex-wrap items-end gap-2">
          <p className="flex min-h-11 items-center text-sm font-medium text-slate-900">
            {selectedCount} {itemLabel}
            {selectedCount === 1 ? "" : "s"} selected
          </p>
          <div className="flex flex-wrap items-end gap-2">{children}</div>
          <Button variant="ghost" size="sm" onClick={onClearSelection}>
            Clear selection
          </Button>
        </div>
      )}
      {summary && <Alert tone={summaryTone}>{summary}</Alert>}
    </div>
  );
}
