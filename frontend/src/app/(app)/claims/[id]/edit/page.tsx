"use client";

import { useParams, useRouter } from "next/navigation";
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
import { isClaimImmutable } from "@/lib/claims";
import { canEditClaims } from "@/lib/permissions";
import type { FieldErrors } from "@/lib/validation";
import type { Claim, Organization } from "@/lib/types";

function EditClaimContent({
  org,
  claimId,
}: {
  org: Organization;
  claimId: string;
}) {
  const router = useRouter();
  const [claim, setClaim] = useState<Claim | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);

  const allowed = canEditClaims(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: Claim }>(`/claims/${claimId}`, { orgId: org.id })
      .then((response) => {
        if (!cancelled) setClaim(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the claim.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, claimId]);

  async function handleSubmit(payload: ClaimPayload) {
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      await apiFetch<{ data: Claim }>(`/claims/${claimId}`, {
        method: "PUT",
        body: payload,
        orgId: org.id,
      });
      router.push(`/claims/${claimId}`);
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
            : "Could not save the claim.",
        );
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!allowed) {
    return (
      <Alert tone="error">
        Your role does not allow editing claims. Ask an owner or admin to
        change your role if you need access.
      </Alert>
    );
  }

  if (loading) {
    return <Spinner label="Loading claim" />;
  }

  if (error && !claim) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!claim) {
    return <Alert tone="info">Claim not found.</Alert>;
  }

  if (isClaimImmutable(claim.status)) {
    return (
      <Alert tone="info">
        This claim is {claim.status_label.toLowerCase()} and can no longer be
        edited.
      </Alert>
    );
  }

  return (
    <Card>
      <CardHeader
        title={`Edit ${claim.claim_reference}`}
        description="Change the claim details. Once a claim is submitted, its amount, currency, category, and event link are frozen."
      />
      <CardBody>
        {error && (
          <div className="mb-4">
            <Alert tone="error">{error}</Alert>
          </div>
        )}
        <ClaimForm
          orgId={org.id}
          shipments={[]}
          initial={claim}
          submitting={submitting}
          serverErrors={fieldErrors}
          submitLabel="Save claim"
          onCancel={() => router.push(`/claims/${claimId}`)}
          onSubmit={handleSubmit}
        />
      </CardBody>
    </Card>
  );
}

export default function EditClaimPage() {
  const params = useParams();
  const claimId = String(params.id ?? "");
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Edit claim"
        description="Change the details of one claim."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Claims", href: "/claims" },
          { label: "Claim", href: `/claims/${claimId}` },
          { label: "Edit" },
        ]}
      />
      {currentOrg ? (
        <EditClaimContent
          key={`${currentOrg.id}-${claimId}`}
          org={currentOrg}
          claimId={claimId}
        />
      ) : (
        <Alert tone="info">Create an organization first to edit claims.</Alert>
      )}
    </div>
  );
}
