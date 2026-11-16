"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import {
  ShipmentForm,
  type ShipmentPayload,
} from "@/components/shipments/ShipmentForm";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canEditShipments } from "@/lib/permissions";
import type { FieldErrors } from "@/lib/validation";
import type { Carrier, Organization, Shipment } from "@/lib/types";

function EditShipmentContent({
  org,
  shipmentId,
}: {
  org: Organization;
  shipmentId: string;
}) {
  const router = useRouter();
  const [shipment, setShipment] = useState<Shipment | null>(null);
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);

  const allowed = canEditShipments(org.current_user_role);

  useEffect(() => {
    let cancelled = false;

    const shipmentRequest = apiFetch<{ data: Shipment }>(
      `/shipments/${shipmentId}`,
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setShipment(response.data);
    });

    const carriersRequest = apiFetch<{ data: Carrier[] }>(
      "/carriers?per_page=100",
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setCarriers(response.data);
    });

    Promise.all([shipmentRequest, carriersRequest])
      .catch((err) => {
        if (!cancelled) {
          setLoadError(
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

  async function handleSubmit(payload: ShipmentPayload) {
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      await apiFetch<{ data: Shipment }>(`/shipments/${shipmentId}`, {
        method: "PUT",
        body: payload,
        orgId: org.id,
      });
      router.push(`/shipments/${shipmentId}`);
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setFieldErrors(mapped);
      } else {
        setError(
          err instanceof ApiRequestError
            ? err.message
            : "Could not update the shipment.",
        );
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!allowed) {
    return (
      <Alert tone="error">
        Your role does not allow editing shipments. Ask an owner or admin to
        change your role if you need access.
      </Alert>
    );
  }

  if (loading) {
    return <Spinner label="Loading shipment" />;
  }

  if (loadError) {
    return <Alert tone="error">{loadError}</Alert>;
  }

  if (!shipment) {
    return <Alert tone="info">Shipment not found.</Alert>;
  }

  if (shipment.status === "archived") {
    return (
      <Card>
        <CardBody>
          <Alert tone="info">
            This shipment is archived and read-only. Archived shipments cannot
            be edited.
          </Alert>
          <div className="mt-4">
            <Link href={`/shipments/${shipmentId}`}>
              <Button variant="secondary">Back to shipment</Button>
            </Link>
          </div>
        </CardBody>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader
        title={`Edit ${shipment.internal_reference}`}
        description="Update the details for this shipment."
      />
      <CardBody>
        {error && (
          <div className="mb-4">
            <Alert tone="error">{error}</Alert>
          </div>
        )}
        <ShipmentForm
          orgId={org.id}
          carriers={carriers}
          initial={shipment}
          submitting={submitting}
          serverErrors={fieldErrors}
          submitLabel="Save changes"
          onCarrierAdded={(carrier) =>
            setCarriers((current) => [...current, carrier])
          }
          onSubmit={handleSubmit}
        />
      </CardBody>
    </Card>
  );
}

export default function EditShipmentPage() {
  const params = useParams();
  const shipmentId = String(params.id ?? "");
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Edit shipment"
        description="Update the details for this shipment."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Shipments", href: "/shipments" },
          { label: "Shipment", href: `/shipments/${shipmentId}` },
          { label: "Edit" },
        ]}
      />
      {currentOrg ? (
        <EditShipmentContent
          key={`${currentOrg.id}-${shipmentId}`}
          org={currentOrg}
          shipmentId={shipmentId}
        />
      ) : (
        <Alert tone="info">Create an organization first to edit shipments.</Alert>
      )}
    </div>
  );
}
