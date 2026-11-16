"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import {
  ShipmentForm,
  type ShipmentPayload,
} from "@/components/shipments/ShipmentForm";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canCreateShipments } from "@/lib/permissions";
import type { FieldErrors } from "@/lib/validation";
import type { Carrier, Organization, Shipment } from "@/lib/types";

function NewShipmentContent({ org }: { org: Organization }) {
  const router = useRouter();
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);

  const allowed = canCreateShipments(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: Carrier[] }>("/carriers?per_page=100", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setCarriers(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setLoadError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load carriers.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function handleSubmit(payload: ShipmentPayload) {
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      const response = await apiFetch<{ data: Shipment }>("/shipments", {
        method: "POST",
        body: payload,
        orgId: org.id,
      });
      router.push(`/shipments/${response.data.id}`);
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
            : "Could not create the shipment.",
        );
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!allowed) {
    return (
      <Alert tone="error">
        Your role does not allow creating shipments. Ask an owner or admin to
        change your role if you need access.
      </Alert>
    );
  }

  if (loading) {
    return <Spinner label="Loading" />;
  }

  return (
    <Card>
      <CardHeader
        title="Shipment details"
        description="Only the internal reference and the broker or customer name are required."
      />
      <CardBody>
        {loadError && (
          <div className="mb-4">
            <Alert tone="warning">
              {loadError} You can still create the shipment without a carrier.
            </Alert>
          </div>
        )}
        {error && (
          <div className="mb-4">
            <Alert tone="error">{error}</Alert>
          </div>
        )}
        <ShipmentForm
          orgId={org.id}
          carriers={carriers}
          submitting={submitting}
          serverErrors={fieldErrors}
          submitLabel="Create shipment"
          onCarrierAdded={(carrier) =>
            setCarriers((current) => [...current, carrier])
          }
          onSubmit={handleSubmit}
        />
      </CardBody>
    </Card>
  );
}

export default function NewShipmentPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="New shipment"
        description="Add a load to track its appointments, events, and evidence."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Shipments", href: "/shipments" },
          { label: "New shipment" },
        ]}
      />
      {currentOrg ? (
        <NewShipmentContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to add shipments.</Alert>
      )}
    </div>
  );
}
