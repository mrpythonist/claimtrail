"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { nonEmptyFilters } from "@/lib/bulk";
import type { SavedView } from "@/lib/types";

/**
 * Saved views for a list page. Saves the current filter state under a
 * name, shows saved views as chips that apply the stored filters, and
 * lets the user delete a view. The page owns the filter state and
 * receives the applied filters through onApply.
 */
export function SavedViews({
  resourceType,
  orgId,
  currentFilters,
  onApply,
}: {
  resourceType: "shipment" | "claim";
  orgId: string;
  currentFilters: Record<string, string>;
  onApply: (filters: Record<string, string>) => void;
}) {
  const [views, setViews] = useState<SavedView[]>([]);
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState<string | null>(null);

  function loadViews() {
    apiFetch<{ data: SavedView[] }>(
      `/saved-views?resource_type=${resourceType}`,
      { orgId },
    )
      .then((response) => setViews(response.data))
      .catch(() => setViews([]));
  }

  useEffect(() => {
    loadViews();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resourceType, orgId]);

  async function handleSave(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Give the view a name first.");
      return;
    }
    setError(null);
    setSaving(true);
    try {
      const response = await apiFetch<{ data: SavedView }>("/saved-views", {
        method: "POST",
        body: {
          name: name.trim(),
          resource_type: resourceType,
          filters: nonEmptyFilters(currentFilters),
        },
        orgId,
      });
      setViews((current) => [...current, response.data]);
      setName("");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not save this view.",
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(view: SavedView) {
    setError(null);
    setDeletingId(view.id);
    try {
      await apiFetch(`/saved-views/${view.id}`, {
        method: "DELETE",
        orgId,
      });
      setViews((current) => current.filter((item) => item.id !== view.id));
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not delete this view.",
      );
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="space-y-3 border-b border-slate-200 px-5 py-4">
      <form
        onSubmit={handleSave}
        className="flex flex-wrap items-end gap-2"
        noValidate
      >
        <div className="min-w-48 flex-1">
          <Input
            id={`save-view-name-${resourceType}`}
            label="Save current view"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="View name, for example Detention this month"
          />
        </div>
        <Button type="submit" variant="secondary" loading={saving}>
          Save view
        </Button>
      </form>
      {error && <Alert tone="error">{error}</Alert>}
      {views.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm text-slate-500">Saved views:</span>
          {views.map((view) => (
            <span
              key={view.id}
              className="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-slate-50 py-1 pl-3 pr-1 text-sm text-slate-700"
            >
              <button
                type="button"
                onClick={() => onApply(view.filters)}
                className="min-h-6 font-medium hover:text-brand-700 hover:underline"
              >
                {view.name}
              </button>
              <button
                type="button"
                onClick={() => handleDelete(view)}
                disabled={deletingId === view.id}
                aria-label={`Delete view ${view.name}`}
                className="flex min-h-6 min-w-6 items-center justify-center rounded-full text-slate-500 hover:bg-slate-200 hover:text-slate-900 disabled:opacity-60"
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
