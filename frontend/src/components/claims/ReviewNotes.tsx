"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Textarea } from "@/components/ui/Input";
import { apiFetch, ApiRequestError } from "@/lib/api";
import type { ReviewNote } from "@/lib/types";

/**
 * Reviewer notes on a claim: the list from GET review-notes plus an
 * add-note form. Only reviewers see the form; everyone on the review
 * page can read the notes.
 */
export function ReviewNotes({
  claimId,
  orgId,
  canAdd,
}: {
  claimId: string;
  orgId: string;
  canAdd: boolean;
}) {
  const [notes, setNotes] = useState<ReviewNote[]>([]);
  const [loading, setLoading] = useState(true);
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: ReviewNote[] }>(`/claims/${claimId}/review-notes`, {
      orgId,
    })
      .then((response) => {
        if (!cancelled) setNotes(response.data);
      })
      .catch(() => {
        if (!cancelled) setNotes([]);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [claimId, orgId]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!note.trim()) {
      setError("Write a note first.");
      return;
    }
    setError(null);
    setSaving(true);
    try {
      const response = await apiFetch<{ data: ReviewNote }>(
        `/claims/${claimId}/review-notes`,
        { method: "POST", body: { note: note.trim() }, orgId },
      );
      setNotes((current) => [response.data, ...current]);
      setNote("");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not save the note.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      {loading ? (
        <p className="text-sm text-slate-500">Loading notes...</p>
      ) : notes.length === 0 ? (
        <p className="text-sm text-slate-500">
          No reviewer notes yet. Notes here are visible to the team working
          on this claim.
        </p>
      ) : (
        <ol className="space-y-4">
          {notes.map((item) => (
            <li key={item.id} className="border-l-2 border-slate-200 pl-4">
              <p className="text-sm text-slate-900">{item.note}</p>
              <p className="mt-0.5 text-xs text-slate-500">
                {item.author?.name ?? "Unknown reviewer"} -{" "}
                {new Date(item.created_at).toLocaleString()}
              </p>
            </li>
          ))}
        </ol>
      )}

      {canAdd && (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          {error && <Alert tone="error">{error}</Alert>}
          <Textarea
            id="review-note"
            label="Add a reviewer note"
            rows={3}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="What should the next reviewer know about this claim?"
          />
          <Button type="submit" loading={saving}>
            Add note
          </Button>
        </form>
      )}
    </div>
  );
}
