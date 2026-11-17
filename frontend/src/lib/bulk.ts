/**
 * Shared helpers for the bulk action bars on the shipment and claim
 * lists. Nothing here calls the API; the pages own the requests.
 */

export interface BulkFailure {
  id: string;
  reason: string;
}

export function toggleId(selected: string[], id: string): string[] {
  return selected.includes(id)
    ? selected.filter((item) => item !== id)
    : [...selected, id];
}

export function toggleAllOnPage(
  selected: string[],
  pageIds: string[],
): string[] {
  const allSelected =
    pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
  if (allSelected) {
    return selected.filter((id) => !pageIds.includes(id));
  }
  return Array.from(new Set([...selected, ...pageIds]));
}

/**
 * One-line summary of a bulk result, plus the per-item failures so the
 * page can list them. `updatedLabel` is the noun for what was updated
 * (for example "shipments" or "claims").
 */
export function bulkResultSummary(
  updatedCount: number,
  failed: BulkFailure[],
  updatedLabel: string,
): string {
  const parts = [`${updatedCount} ${updatedLabel} updated.`];
  if (failed.length > 0) {
    parts.push(
      `${failed.length} failed. ${failed.map((item) => item.reason).join(" ")}`,
    );
  }
  return parts.join(" ");
}

/** Drops empty filter values so a saved view only stores what is set. */
export function nonEmptyFilters(
  filters: Record<string, string>,
): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(filters)) {
    if (value !== "") result[key] = value;
  }
  return result;
}

/** Which bulk claim targets need extra data from the user. */
export function bulkClaimTargetNeeds(target: string): {
  acceptedAmount: boolean;
  reason: boolean;
} {
  return {
    acceptedAmount: target === "accepted" || target === "partially_accepted",
    reason: target === "rejected" || target === "closed",
  };
}
