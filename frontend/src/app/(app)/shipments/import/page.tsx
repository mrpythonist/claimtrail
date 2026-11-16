"use client";

import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Table, TableCell } from "@/components/ui/Table";
import { apiFetch, apiUpload, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canImportShipments } from "@/lib/permissions";
import type {
  ImportConfirmResult,
  ImportPreview,
  ImportPreviewRow,
  Organization,
} from "@/lib/types";

type Step = "upload" | "preview" | "done";

function rowIsValid(row: ImportPreviewRow): boolean {
  return Object.keys(row.errors).length === 0 && !row.is_duplicate;
}

function rowIssues(row: ImportPreviewRow): string[] {
  const issues: string[] = [];
  for (const messages of Object.values(row.errors)) {
    issues.push(...messages);
  }
  if (row.is_duplicate) issues.push("Duplicate of an existing shipment.");
  return issues;
}

function rowField(row: ImportPreviewRow, field: string): string {
  const value = row.data[field];
  if (value === null || value === undefined || value === "") return "Not set";
  return String(value);
}

function ImportContent({ org }: { org: Organization }) {
  const [step, setStep] = useState<Step>("upload");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [result, setResult] = useState<ImportConfirmResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const allowed = canImportShipments(org.current_user_role);

  async function handlePreview(event: FormEvent) {
    event.preventDefault();
    if (!file) {
      setError("Choose a CSV file to import.");
      return;
    }
    if (!file.name.toLowerCase().endsWith(".csv")) {
      setError("Only CSV files can be imported.");
      return;
    }
    setError(null);
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      const response = await apiUpload<{ data: ImportPreview }>(
        "/shipments/import/preview",
        formData,
        { orgId: org.id },
      );
      setPreview(response.data);
      setSelected(
        new Set(
          response.data.rows
            .filter((row) => rowIsValid(row))
            .map((row) => row.index),
        ),
      );
      setStep("preview");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not preview the import file.",
      );
    } finally {
      setUploading(false);
    }
  }

  function toggleRow(index: number, checked: boolean) {
    setSelected((current) => {
      const next = new Set(current);
      if (checked) {
        next.add(index);
      } else {
        next.delete(index);
      }
      return next;
    });
  }

  async function handleConfirm() {
    if (!preview) return;
    setError(null);
    setConfirming(true);
    try {
      const response = await apiFetch<{ data: ImportConfirmResult }>(
        "/shipments/import/confirm",
        {
          method: "POST",
          body: {
            preview_token: preview.preview_token,
            row_indexes: Array.from(selected).sort((a, b) => a - b),
          },
          orgId: org.id,
        },
      );
      setResult(response.data);
      setStep("done");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not confirm the import.",
      );
    } finally {
      setConfirming(false);
    }
  }

  function reset() {
    setStep("upload");
    setFile(null);
    setPreview(null);
    setSelected(new Set());
    setResult(null);
    setError(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  if (!allowed) {
    return (
      <Alert tone="error">
        Your role does not allow importing shipments. Ask an owner or admin to
        change your role if you need access.
      </Alert>
    );
  }

  return (
    <div className="space-y-6">
      {error && <Alert tone="error">{error}</Alert>}

      {step === "upload" && (
        <Card>
          <CardHeader
            title="Step 1: Upload a CSV file"
            description="The file must have a header row. Each row becomes one shipment. Nothing is imported until you confirm the preview."
          />
          <CardBody>
            <form onSubmit={handlePreview} className="max-w-md space-y-4" noValidate>
              <div>
                <label
                  htmlFor="import-file"
                  className="mb-1 block text-sm font-medium text-slate-700"
                >
                  CSV file
                </label>
                <input
                  ref={fileInputRef}
                  id="import-file"
                  type="file"
                  accept=".csv"
                  onChange={(event) =>
                    setFile(event.target.files?.[0] ?? null)
                  }
                  className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
                <p className="mt-1 text-xs text-slate-500">
                  Expected columns include internal_reference,
                  broker_or_customer_name, pickup_location, delivery_location,
                  pickup_appointment, delivery_appointment, base_rate, and
                  currency.
                </p>
              </div>
              <Button type="submit" loading={uploading}>
                Preview import
              </Button>
            </form>
          </CardBody>
        </Card>
      )}

      {step === "preview" && preview && (
        <Card>
          <CardHeader
            title="Step 2: Review the preview"
            description={`${preview.summary.total} rows found: ${preview.summary.valid} valid, ${preview.summary.invalid} invalid, ${preview.summary.duplicate} duplicate. Valid rows are selected by default; invalid and duplicate rows cannot be imported.`}
            action={
              <Button
                loading={confirming}
                disabled={selected.size === 0}
                onClick={handleConfirm}
              >
                Import {selected.size} shipment{selected.size === 1 ? "" : "s"}
              </Button>
            }
          />
          {preview.rows.length === 0 ? (
            <CardBody>
              <p className="text-sm text-slate-500">
                The file had no data rows to import.
              </p>
              <div className="mt-4">
                <Button variant="secondary" onClick={reset}>
                  Upload a different file
                </Button>
              </div>
            </CardBody>
          ) : (
            <>
              <Table
                headers={[
                  "",
                  "Row",
                  "Internal reference",
                  "Broker / Customer",
                  "Pickup",
                  "Delivery",
                  "Issues",
                ]}
              >
                {preview.rows.map((row) => {
                  const valid = rowIsValid(row);
                  const issues = rowIssues(row);
                  return (
                    <tr
                      key={row.index}
                      className={valid ? undefined : "bg-red-50/50"}
                    >
                      <TableCell>
                        <input
                          type="checkbox"
                          aria-label={`Import row ${row.index}`}
                          checked={selected.has(row.index)}
                          disabled={!valid}
                          onChange={(event) =>
                            toggleRow(row.index, event.target.checked)
                          }
                          className="h-4 w-4 rounded border-slate-300"
                        />
                      </TableCell>
                      <TableCell>{row.index}</TableCell>
                      <TableCell>
                        <span className="font-medium text-slate-900">
                          {rowField(row, "internal_reference")}
                        </span>
                        {row.is_duplicate && (
                          <span className="ml-2">
                            <Badge tone="warning">Duplicate</Badge>
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        {rowField(row, "broker_or_customer_name")}
                      </TableCell>
                      <TableCell>{rowField(row, "pickup_location")}</TableCell>
                      <TableCell>{rowField(row, "delivery_location")}</TableCell>
                      <TableCell>
                        {issues.length === 0 ? (
                          <span className="text-slate-400">None</span>
                        ) : (
                          <ul className="list-disc space-y-0.5 pl-4 text-xs text-red-700">
                            {issues.map((issue, index) => (
                              <li key={`${row.index}-${index}`}>{issue}</li>
                            ))}
                          </ul>
                        )}
                      </TableCell>
                    </tr>
                  );
                })}
              </Table>
              <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3">
                <Button variant="secondary" size="sm" onClick={reset}>
                  Upload a different file
                </Button>
                <p className="text-sm text-slate-500">
                  {selected.size} of {preview.summary.total} rows selected.
                </p>
              </div>
            </>
          )}
        </Card>
      )}

      {step === "done" && result && (
        <Card>
          <CardHeader
            title="Step 3: Import complete"
            description="The selected rows have been imported."
          />
          <CardBody>
            <Alert tone="success">
              Imported {result.imported} shipment
              {result.imported === 1 ? "" : "s"}.
              {result.skipped > 0 && ` ${result.skipped} skipped.`}
            </Alert>
            {result.skipped_rows.length > 0 && (
              <div className="mt-4">
                <h3 className="text-sm font-semibold text-slate-900">
                  Skipped rows
                </h3>
                <ul className="mt-2 space-y-1 text-sm text-slate-600">
                  {result.skipped_rows.map((row) => (
                    <li key={row.index}>
                      Row {row.index}: {row.reason}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="mt-6 flex flex-wrap gap-2">
              <Link href="/shipments">
                <Button>View shipments</Button>
              </Link>
              <Button variant="secondary" onClick={reset}>
                Import another file
              </Button>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

export default function ImportShipmentsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Import shipments"
        description="Upload a CSV file, review the preview, then confirm the import."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Shipments", href: "/shipments" },
          { label: "Import" },
        ]}
      />
      {currentOrg ? (
        <ImportContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to import shipments.</Alert>
      )}
    </div>
  );
}
