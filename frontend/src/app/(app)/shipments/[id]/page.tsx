"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select, Textarea } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import {
  CategoryForm,
  type CategoryPayload,
} from "@/components/shipments/CategoryForm";
import { EventForm, type EventPayload } from "@/components/shipments/EventForm";
import {
  apiDownload,
  apiFetch,
  apiUpload,
  ApiRequestError,
} from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  canArchiveShipments,
  canDeleteEvents,
  canDeleteEvidence,
  canEditShipments,
  canManageEvents,
  canUploadEvidence,
  canVerifyEvidence,
} from "@/lib/permissions";
import {
  blankToNull,
  DOCUMENT_CATEGORIES,
  downloadBlob,
  evidenceFileError,
  eventStatusTone,
  formatFileSize,
  isPreviewableMime,
  nextShipmentStatus,
  openBlobInNewTab,
  SHIPMENT_STATUS_LABELS,
  shipmentStatusTone,
  VERIFICATION_STATUS_LABELS,
  verificationStatusTone,
} from "@/lib/shipments";
import type { FieldErrors } from "@/lib/validation";
import type {
  DocumentCategory,
  EventCategory,
  EvidenceFile,
  Organization,
  Shipment,
  ShipmentEvent,
  VerificationStatus,
} from "@/lib/types";

function formatDateTime(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleString();
}

function formatDate(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleDateString();
}

function formatDuration(minutes: number | null): string {
  if (minutes === null || minutes === undefined) return "Not set";
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

function formatMoney(amount: string | null, currency: string | null): string {
  if (amount === null || amount === "") return "Not set";
  return `${currency ?? "USD"} ${amount}`;
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-b border-slate-100 py-2 last:border-b-0 sm:grid sm:grid-cols-3 sm:gap-4">
      <dt className="text-sm font-medium text-slate-500">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900 sm:mt-0 sm:col-span-2">{value}</dd>
    </div>
  );
}

function ShipmentDetailContent({
  org,
  shipmentId,
}: {
  org: Organization;
  shipmentId: string;
}) {
  const [shipment, setShipment] = useState<Shipment | null>(null);
  const [events, setEvents] = useState<ShipmentEvent[]>([]);
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [evidence, setEvidence] = useState<EvidenceFile[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [transitioning, setTransitioning] = useState(false);
  const [archiveTarget, setArchiveTarget] = useState(false);
  const [archiving, setArchiving] = useState(false);

  // Events state.
  const [showEventForm, setShowEventForm] = useState(false);
  const [editingEvent, setEditingEvent] = useState<ShipmentEvent | null>(null);
  const [eventFieldErrors, setEventFieldErrors] = useState<FieldErrors>({});
  const [savingEvent, setSavingEvent] = useState(false);

  // Custom event category state.
  const [showCategoryForm, setShowCategoryForm] = useState(false);
  const [categoryFieldErrors, setCategoryFieldErrors] = useState<FieldErrors>({});
  const [categoryMessage, setCategoryMessage] = useState<string | null>(null);
  const [savingCategory, setSavingCategory] = useState(false);
  const [deleteEventTarget, setDeleteEventTarget] = useState<ShipmentEvent | null>(null);
  const [deletingEvent, setDeletingEvent] = useState(false);

  // Evidence state.
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadCategory, setUploadCategory] = useState<DocumentCategory>("other");
  const [uploadDescription, setUploadDescription] = useState("");
  const [uploadEventId, setUploadEventId] = useState("");
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [deleteEvidenceTarget, setDeleteEvidenceTarget] =
    useState<EvidenceFile | null>(null);
  const [deletingEvidence, setDeletingEvidence] = useState(false);
  const [verifyingId, setVerifyingId] = useState<string | null>(null);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const myRole = org.current_user_role;
  const archived = shipment?.status === "archived";
  const nextStatus = shipment ? nextShipmentStatus(shipment.status) : null;

  useEffect(() => {
    let cancelled = false;

    const shipmentRequest = apiFetch<{ data: Shipment }>(
      `/shipments/${shipmentId}`,
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setShipment(response.data);
    });

    const eventsRequest = apiFetch<{ data: ShipmentEvent[] }>(
      `/shipments/${shipmentId}/events`,
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setEvents(response.data);
    });

    const categoriesRequest = apiFetch<{ data: EventCategory[] }>(
      "/event-categories",
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setCategories(response.data);
    });

    const evidenceRequest = apiFetch<{ data: EvidenceFile[] }>(
      `/evidence?shipment_id=${shipmentId}&per_page=100`,
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setEvidence(response.data);
    });

    Promise.all([shipmentRequest, eventsRequest, categoriesRequest, evidenceRequest])
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the shipment.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, shipmentId]);

  function showError(err: unknown, fallback: string) {
    setError(
      err instanceof ApiRequestError ? err.message : fallback,
    );
  }

  async function handleTransition() {
    if (!shipment || !nextStatus) return;
    setError(null);
    setNotice(null);
    setTransitioning(true);
    try {
      const response = await apiFetch<{ data: Shipment }>(
        `/shipments/${shipmentId}`,
        { method: "PUT", body: { status: nextStatus }, orgId: org.id },
      );
      setShipment(response.data);
      setNotice(`Status changed to ${response.data.status_label}.`);
    } catch (err) {
      showError(err, "Could not change the status.");
    } finally {
      setTransitioning(false);
    }
  }

  async function confirmArchive() {
    if (!shipment) return;
    setArchiving(true);
    try {
      const response = await apiFetch<{ data: Shipment }>(
        `/shipments/${shipmentId}/archive`,
        { method: "POST", orgId: org.id },
      );
      setShipment(response.data);
      setNotice("Shipment archived.");
      setArchiveTarget(false);
    } catch (err) {
      showError(err, "Could not archive the shipment.");
      setArchiveTarget(false);
    } finally {
      setArchiving(false);
    }
  }

  function openAddEvent() {
    setEditingEvent(null);
    setEventFieldErrors({});
    setShowEventForm(true);
  }

  function openEditEvent(event: ShipmentEvent) {
    setEditingEvent(event);
    setEventFieldErrors({});
    setShowEventForm(true);
  }

  function closeEventForm() {
    setShowEventForm(false);
    setEditingEvent(null);
    setEventFieldErrors({});
  }

  function openAddCategory() {
    setCategoryFieldErrors({});
    setCategoryMessage(null);
    setShowCategoryForm(true);
  }

  function closeCategoryForm() {
    setShowCategoryForm(false);
    setCategoryFieldErrors({});
    setCategoryMessage(null);
  }

  async function handleCategorySubmit(payload: CategoryPayload) {
    setError(null);
    setCategoryFieldErrors({});
    setCategoryMessage(null);
    setSavingCategory(true);
    try {
      const response = await apiFetch<{ data: EventCategory }>(
        "/event-categories",
        { method: "POST", body: payload, orgId: org.id },
      );
      setCategories((current) =>
        [...current, response.data].sort((a, b) =>
          a.label.localeCompare(b.label),
        ),
      );
      setNotice(`Category "${response.data.label}" added.`);
      closeCategoryForm();
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setCategoryFieldErrors(mapped);
      } else if (err instanceof ApiRequestError) {
        // Duplicate names and other non-field problems come back as a
        // plain message; show it on the form, not the page.
        setCategoryMessage(err.message);
      } else {
        showError(err, "Could not add the category.");
      }
    } finally {
      setSavingCategory(false);
    }
  }

  async function handleEventSubmit(payload: EventPayload) {
    setError(null);
    setEventFieldErrors({});
    setSavingEvent(true);
    try {
      if (editingEvent) {
        const response = await apiFetch<{ data: ShipmentEvent }>(
          `/events/${editingEvent.id}`,
          { method: "PUT", body: payload, orgId: org.id },
        );
        setEvents((current) =>
          current.map((item) =>
            item.id === editingEvent.id ? response.data : item,
          ),
        );
        setNotice("Event updated.");
      } else {
        const response = await apiFetch<{ data: ShipmentEvent }>(
          `/shipments/${shipmentId}/events`,
          { method: "POST", body: payload, orgId: org.id },
        );
        setEvents((current) => [response.data, ...current]);
        setNotice("Event added.");
      }
      closeEventForm();
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setEventFieldErrors(mapped);
      } else {
        showError(err, "Could not save the event.");
      }
    } finally {
      setSavingEvent(false);
    }
  }

  async function confirmDeleteEvent() {
    if (!deleteEventTarget) return;
    setDeletingEvent(true);
    try {
      await apiFetch(`/events/${deleteEventTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setEvents((current) =>
        current.filter((item) => item.id !== deleteEventTarget.id),
      );
      setNotice("Event deleted.");
      setDeleteEventTarget(null);
    } catch (err) {
      showError(err, "Could not delete the event.");
      setDeleteEventTarget(null);
    } finally {
      setDeletingEvent(false);
    }
  }

  async function handleUpload(event: FormEvent) {
    event.preventDefault();
    if (!uploadFile) {
      setUploadError("Choose a file to upload.");
      return;
    }
    const fileError = evidenceFileError(uploadFile);
    if (fileError) {
      setUploadError(fileError);
      return;
    }
    setUploadError(null);
    setError(null);
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append("file", uploadFile);
      formData.append("shipment_id", shipmentId);
      formData.append("document_category", uploadCategory);
      if (uploadEventId) formData.append("shipment_event_id", uploadEventId);
      const description = blankToNull(uploadDescription);
      if (description) formData.append("description", description);

      const response = await apiUpload<{ data: EvidenceFile }>("/evidence", formData, {
        orgId: org.id,
      });
      setEvidence((current) => [response.data, ...current]);
      setNotice(`Uploaded ${response.data.original_filename}.`);
      setUploadFile(null);
      setUploadDescription("");
      setUploadEventId("");
      setShowUploadForm(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    } catch (err) {
      if (err instanceof ApiRequestError && err.fieldError("file")) {
        setUploadError(err.fieldError("file") ?? err.message);
      } else {
        showError(err, "Could not upload the file.");
      }
    } finally {
      setUploading(false);
    }
  }

  async function handleDownload(file: EvidenceFile, preview: boolean) {
    setError(null);
    setDownloadingId(file.id);
    try {
      const blob = await apiDownload(
        `/evidence/${file.id}/download${preview ? "?preview=1" : ""}`,
        { orgId: org.id },
      );
      if (preview && isPreviewableMime(file.mime_type)) {
        openBlobInNewTab(blob);
      } else {
        downloadBlob(blob, file.original_filename);
      }
    } catch (err) {
      showError(err, "Could not download the file.");
    } finally {
      setDownloadingId(null);
    }
  }

  async function handleVerify(file: EvidenceFile, status: VerificationStatus) {
    setError(null);
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
      setEvidence((current) =>
        current.map((item) => (item.id === file.id ? response.data : item)),
      );
      setNotice(
        `${file.original_filename} marked ${VERIFICATION_STATUS_LABELS[status].toLowerCase()}.`,
      );
    } catch (err) {
      showError(err, "Could not update the verification status.");
    } finally {
      setVerifyingId(null);
    }
  }

  async function confirmDeleteEvidence() {
    if (!deleteEvidenceTarget) return;
    setDeletingEvidence(true);
    try {
      await apiFetch(`/evidence/${deleteEvidenceTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setEvidence((current) =>
        current.filter((item) => item.id !== deleteEvidenceTarget.id),
      );
      setNotice(`Deleted ${deleteEvidenceTarget.original_filename}.`);
      setDeleteEvidenceTarget(null);
    } catch (err) {
      showError(err, "Could not delete the file.");
      setDeleteEvidenceTarget(null);
    } finally {
      setDeletingEvidence(false);
    }
  }

  if (loading) {
    return <Spinner label="Loading shipment" />;
  }

  if (error && !shipment) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!shipment) {
    return <Alert tone="info">Shipment not found.</Alert>;
  }

  return (
    <div className="space-y-6">
      {notice && <Alert tone="success">{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}

      <Card>
        <CardHeader
          title={shipment.internal_reference}
          description={`${shipment.broker_or_customer_name} - created ${formatDate(shipment.created_at)}.`}
          action={
            <Badge tone={shipmentStatusTone(shipment.status)}>
              {shipment.status_label}
            </Badge>
          }
        />
        <CardBody>
          <dl>
            <DetailRow
              label="External load reference"
              value={shipment.external_load_reference ?? "Not set"}
            />
            <DetailRow
              label="Carrier"
              value={shipment.carrier?.name ?? "Not assigned"}
            />
            <DetailRow label="Driver" value={shipment.driver_name ?? "Not set"} />
            <DetailRow
              label="Driver contact"
              value={shipment.driver_contact ?? "Not set"}
            />
            <DetailRow
              label="Pickup location"
              value={shipment.pickup_location ?? "Not set"}
            />
            <DetailRow
              label="Delivery location"
              value={shipment.delivery_location ?? "Not set"}
            />
            <DetailRow
              label="Pickup appointment"
              value={formatDateTime(shipment.pickup_appointment)}
            />
            <DetailRow
              label="Delivery appointment"
              value={formatDateTime(shipment.delivery_appointment)}
            />
            <DetailRow
              label="Actual arrival"
              value={formatDateTime(shipment.actual_arrival_at)}
            />
            <DetailRow
              label="Actual departure"
              value={formatDateTime(shipment.actual_departure_at)}
            />
            <DetailRow
              label="Base rate"
              value={formatMoney(shipment.base_rate, shipment.currency)}
            />
            <DetailRow
              label="Created by"
              value={shipment.created_by?.name ?? "Unknown"}
            />
            <DetailRow label="Notes" value={shipment.notes ?? "None"} />
          </dl>

          <div className="mt-6 flex flex-wrap gap-2">
            {canEditShipments(myRole) && !archived && (
              <Link href={`/shipments/${shipment.id}/edit`}>
                <Button variant="secondary">Edit shipment</Button>
              </Link>
            )}
            {canEditShipments(myRole) && nextStatus && (
              <Button loading={transitioning} onClick={handleTransition}>
                Move to {SHIPMENT_STATUS_LABELS[nextStatus]}
              </Button>
            )}
            {canArchiveShipments(myRole) && !archived && (
              <Button
                variant="danger"
                onClick={() => setArchiveTarget(true)}
              >
                Archive shipment
              </Button>
            )}
          </div>
          {archived && (
            <p className="mt-4 text-sm text-slate-500">
              This shipment is archived and read-only.
            </p>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Events"
          description="Detention, layover, TONU, lumper, and other charge events for this shipment."
          action={
            canManageEvents(myRole) && !archived ? (
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" onClick={openAddCategory}>
                  New category
                </Button>
                <Button size="sm" onClick={openAddEvent}>
                  Add event
                </Button>
              </div>
            ) : undefined
          }
        />
        {showCategoryForm && (
          <CardBody className="border-b border-slate-200">
            <CategoryForm
              submitting={savingCategory}
              serverErrors={categoryFieldErrors}
              serverMessage={categoryMessage}
              submitLabel="Add category"
              onCancel={closeCategoryForm}
              onSubmit={handleCategorySubmit}
            />
          </CardBody>
        )}
        {showEventForm && (
          <CardBody className="border-b border-slate-200">
            <EventForm
              categories={categories}
              initial={editingEvent}
              submitting={savingEvent}
              serverErrors={eventFieldErrors}
              submitLabel={editingEvent ? "Save event" : "Add event"}
              onCancel={closeEventForm}
              onSubmit={handleEventSubmit}
            />
          </CardBody>
        )}
        {events.length === 0 ? (
          <EmptyState
            title="No events yet"
            description="Add an event when something chargeable happens on this load."
          />
        ) : (
          <Table
            headers={[
              "Category",
              "Date",
              "Time",
              "Duration",
              "Estimated amount",
              "Status",
              "",
            ]}
          >
            {events.map((event) => (
              <tr key={event.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">
                    {event.category.label}
                  </p>
                  {event.location && (
                    <p className="text-xs text-slate-500">{event.location}</p>
                  )}
                  {event.description && (
                    <p className="mt-1 text-xs text-slate-500">
                      {event.description}
                    </p>
                  )}
                </TableCell>
                <TableCell>{formatDate(event.event_date)}</TableCell>
                <TableCell>
                  {event.start_time || event.end_time
                    ? `${event.start_time ?? "Unknown"} - ${event.end_time ?? "Unknown"}`
                    : "Not set"}
                </TableCell>
                <TableCell>{formatDuration(event.duration_minutes)}</TableCell>
                <TableCell>
                  {formatMoney(event.estimated_amount, event.currency)}
                </TableCell>
                <TableCell>
                  <Badge tone={eventStatusTone(event.status)}>
                    {event.status_label}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    {canManageEvents(myRole) && !archived && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => openEditEvent(event)}
                      >
                        Edit
                      </Button>
                    )}
                    {canDeleteEvents(myRole) && !archived && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteEventTarget(event)}
                      >
                        Delete
                      </Button>
                    )}
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader
          title="Evidence"
          description="Rate confirmations, bills of lading, receipts, and photos for this shipment."
          action={
            canUploadEvidence(myRole) && !archived ? (
              <Button size="sm" onClick={() => setShowUploadForm((show) => !show)}>
                {showUploadForm ? "Hide upload form" : "Upload file"}
              </Button>
            ) : undefined
          }
        />
        {showUploadForm && (
          <CardBody className="border-b border-slate-200">
            <form onSubmit={handleUpload} className="space-y-4" noValidate>
              {uploadError && <Alert tone="error">{uploadError}</Alert>}
              <div>
                <label
                  htmlFor="evidence-file"
                  className="mb-1 block text-sm font-medium text-slate-700"
                >
                  File
                </label>
                <input
                  ref={fileInputRef}
                  id="evidence-file"
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt"
                  onChange={(event) =>
                    setUploadFile(event.target.files?.[0] ?? null)
                  }
                  className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
                <p className="mt-1 text-xs text-slate-500">
                  Max 20 MB. Allowed types: pdf, jpg, png, webp, doc, docx,
                  xls, xlsx, csv, txt.
                </p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Select
                  id="document_category"
                  label="Document category"
                  value={uploadCategory}
                  onChange={(event) =>
                    setUploadCategory(event.target.value as DocumentCategory)
                  }
                >
                  {DOCUMENT_CATEGORIES.map((category) => (
                    <option key={category.value} value={category.value}>
                      {category.label}
                    </option>
                  ))}
                </Select>
                <Select
                  id="shipment_event_id"
                  label="Linked event (optional)"
                  value={uploadEventId}
                  onChange={(event) => setUploadEventId(event.target.value)}
                >
                  <option value="">No event</option>
                  {events.map((event) => (
                    <option key={event.id} value={event.id}>
                      {event.category.label} on {formatDate(event.event_date)}
                    </option>
                  ))}
                </Select>
              </div>
              <Textarea
                id="evidence-description"
                label="Description"
                rows={2}
                value={uploadDescription}
                onChange={(event) => setUploadDescription(event.target.value)}
              />
              <Button type="submit" loading={uploading}>
                Upload file
              </Button>
            </form>
          </CardBody>
        )}
        {evidence.length === 0 ? (
          <EmptyState
            title="No evidence yet"
            description="Upload the documents that support this shipment and its events."
          />
        ) : (
          <Table
            headers={[
              "File",
              "Category",
              "Size",
              "Uploaded by",
              "Verification",
              "",
            ]}
          >
            {evidence.map((file) => (
              <tr key={file.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">
                    {file.original_filename}
                  </p>
                  {file.description && (
                    <p className="text-xs text-slate-500">{file.description}</p>
                  )}
                  <p className="text-xs text-slate-500">
                    {formatDate(file.created_at)}
                  </p>
                </TableCell>
                <TableCell>{file.document_category_label}</TableCell>
                <TableCell>{formatFileSize(file.file_size)}</TableCell>
                <TableCell>{file.uploaded_by?.name ?? "Unknown"}</TableCell>
                <TableCell>
                  {canVerifyEvidence(myRole) && !archived ? (
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
                      {(Object.keys(VERIFICATION_STATUS_LABELS) as VerificationStatus[]).map(
                        (status) => (
                          <option key={status} value={status}>
                            {VERIFICATION_STATUS_LABELS[status]}
                          </option>
                        ),
                      )}
                    </select>
                  ) : (
                    <Badge tone={verificationStatusTone(file.verification_status)}>
                      {VERIFICATION_STATUS_LABELS[file.verification_status]}
                    </Badge>
                  )}
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    {isPreviewableMime(file.mime_type) && (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={downloadingId === file.id}
                        onClick={() => handleDownload(file, true)}
                      >
                        Preview
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={downloadingId === file.id}
                      onClick={() => handleDownload(file, false)}
                    >
                      Download
                    </Button>
                    {canDeleteEvidence(myRole) && !archived && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteEvidenceTarget(file)}
                      >
                        Delete
                      </Button>
                    )}
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Modal
        open={archiveTarget}
        title="Archive shipment"
        description={
          shipment
            ? `Archive ${shipment.internal_reference}? Archived shipments are read-only and their events and evidence cannot be changed.`
            : undefined
        }
        confirmLabel="Archive shipment"
        danger
        loading={archiving}
        onConfirm={confirmArchive}
        onClose={() => setArchiveTarget(false)}
      />
      <Modal
        open={deleteEventTarget !== null}
        title="Delete event"
        description={
          deleteEventTarget
            ? `Delete the ${deleteEventTarget.category.label} event from ${formatDate(deleteEventTarget.event_date)}? This cannot be undone.`
            : undefined
        }
        confirmLabel="Delete event"
        danger
        loading={deletingEvent}
        onConfirm={confirmDeleteEvent}
        onClose={() => setDeleteEventTarget(null)}
      />
      <Modal
        open={deleteEvidenceTarget !== null}
        title="Delete evidence"
        description={
          deleteEvidenceTarget
            ? `Delete ${deleteEvidenceTarget.original_filename}? The file will be removed permanently.`
            : undefined
        }
        confirmLabel="Delete file"
        danger
        loading={deletingEvidence}
        onConfirm={confirmDeleteEvidence}
        onClose={() => setDeleteEvidenceTarget(null)}
      />
    </div>
  );
}

export default function ShipmentDetailPage() {
  const params = useParams();
  const shipmentId = String(params.id ?? "");
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Shipment"
        description="Details, events, and evidence for one load."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Shipments", href: "/shipments" },
          { label: "Shipment" },
        ]}
      />
      {currentOrg ? (
        <ShipmentDetailContent
          key={`${currentOrg.id}-${shipmentId}`}
          org={currentOrg}
          shipmentId={shipmentId}
        />
      ) : (
        <Alert tone="info">Create an organization first to view shipments.</Alert>
      )}
    </div>
  );
}
