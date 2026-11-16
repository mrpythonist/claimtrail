"use client";

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ReadinessPanel } from "@/components/claims/ReadinessPanel";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { isClaimImmutable } from "@/lib/claims";
import { canReviewClaims } from "@/lib/permissions";
import type { Claim, Organization, Readiness } from "@/lib/types";

function ReviewContent({
  org,
  claimId,
}: {
  org: Organization;
  claimId: string;
}) {
  const [claim, setClaim] = useState<Claim | null>(null);
  const [readiness, setReadiness] = useState<Readiness | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [overriding, setOverriding] = useState(false);
  const [overrideError, setOverrideError] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const canOverride =
    claim !== null &&
    !isClaimImmutable(claim.status) &&
    canReviewClaims(myRole);

  useEffect(() => {
    let cancelled = false;

    const claimRequest = apiFetch<{ data: Claim }>(`/claims/${claimId}`, {
      orgId: org.id,
    }).then((response) => {
      if (!cancelled) setClaim(response.data);
    });

    const readinessRequest = apiFetch<{ data: Readiness }>(
      `/claims/${claimId}/readiness`,
      { orgId: org.id },
    ).then((response) => {
      if (!cancelled) setReadiness(response.data);
    });

    Promise.all([claimRequest, readinessRequest])
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the readiness review.",
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

  async function handleOverride(reason: string) {
    setOverrideError(null);
    setNotice(null);
    setOverriding(true);
    try {
      const response = await apiFetch<{ data: Readiness }>(
        `/claims/${claimId}/readiness/override`,
        { method: "POST", body: { reason }, orgId: org.id },
      );
      setReadiness(response.data);
      setNotice("Readiness overridden. Your name and reason were recorded.");
    } catch (err) {
      setOverrideError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not override readiness.",
      );
    } finally {
      setOverriding(false);
    }
  }

  if (loading) {
    return <Spinner label="Loading readiness" />;
  }

  if (error && !readiness) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!readiness) {
    return <Alert tone="info">No readiness data for this claim.</Alert>;
  }

  return (
    <div className="space-y-6">
      {notice && <Alert tone="success">{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}
      <Card>
        <CardHeader
          title={`Readiness for ${claim?.claim_reference ?? "this claim"}`}
          description="Which required documents are attached, and which are still missing."
        />
        <CardBody>
          <ReadinessPanel
            readiness={readiness}
            claimId={claimId}
            canOverride={canOverride}
            overriding={overriding}
            overrideError={overrideError}
            onOverride={handleOverride}
          />
        </CardBody>
      </Card>
    </div>
  );
}

export default function ClaimReviewPage() {
  const params = useParams();
  const claimId = String(params.id ?? "");
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Review readiness"
        description="Evidence readiness for one claim."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Claims", href: "/claims" },
          { label: "Claim", href: `/claims/${claimId}` },
          { label: "Review" },
        ]}
      />
      {currentOrg ? (
        <ReviewContent
          key={`${currentOrg.id}-${claimId}`}
          org={currentOrg}
          claimId={claimId}
        />
      ) : (
        <Alert tone="info">Create an organization first to review claims.</Alert>
      )}
    </div>
  );
}
