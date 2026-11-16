"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import {
  ClaimForm,
  type ClaimPayload,
} from "@/components/claims/ClaimForm";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canCreateClaims } from "@/lib/permissions";
import type { FieldErrors } from "@/lib/validation";
import type { Claim, Organization, Shipment } from "@/lib/types";

function NewClaimContent({ org }: { org: Organization }) {
  const router = useRouter();
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);

  const allowed = canCreateClaims(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: Shipment[] }>("/shipments?per_page=100", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setShipments(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setLoadError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load shipments.",
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

  async function handleSubmit(payload: ClaimPayload) {
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      const response = await apiFetch<{ data: Claim }>("/claims", {
        method: "POST",
        body: payload,
        orgId: org.id,
      });
      router.push(`/claims/${response.data.id}`);
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
            : "Could not create the claim.",
        );
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!allowed) {
    return (
      <Alert tone="error">
        Your role does not allow creating claims. Ask an owner or admin to
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
        title="Claim details"
        description="A claim belongs to one shipment. Pick an event to link it to what happened on the load; the category defaults from the event."
      />
      <CardBody>
        {loadError && (
          <div className="mb-4">
            <Alert tone="warning">
              {loadError} You cannot create a claim without a shipment.
            </Alert>
          </div>
        )}
        {error && (
          <div className="mb-4">
            <Alert tone="error">{error}</Alert>
          </div>
        )}
        {shipments.length === 0 && !loadError ? (
          <Alert tone="info">
            No shipments yet. Create a shipment first, then come back to
            create a claim for it.
          </Alert>
        ) : (
          <ClaimForm
            orgId={org.id}
            shipments={shipments}
            submitting={submitting}
            serverErrors={fieldErrors}
            submitLabel="Create claim"
            onCancel={() => router.push("/claims")}
            onSubmit={handleSubmit}
          />
        )}
      </CardBody>
    </Card>
  );
}

export default function NewClaimPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="New claim"
        description="Start a claim for a shipment, optionally linked to an event on it."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Claims", href: "/claims" },
          { label: "New claim" },
        ]}
      />
      {currentOrg ? (
        <NewClaimContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to add claims.</Alert>
      )}
    </div>
  );
}
