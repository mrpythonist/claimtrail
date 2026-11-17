"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ReadinessPanel } from "@/components/claims/ReadinessPanel";
import { ReviewNotes } from "@/components/claims/ReviewNotes";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  isClaimImmutable,
  reviewNextAction,
} from "@/lib/claims";
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

  // Review decision state.
  const [comment, setComment] = useState("");
  const [commentError, setCommentError] = useState<string | null>(null);
  const [deciding, setDeciding] = useState(false);
  const [decisionError, setDecisionError] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const canOverride =
    claim !== null &&
    !isClaimImmutable(claim.status) &&
    canReviewClaims(myRole);
  const canDecide =
    claim !== null &&
    claim.status === "under_review" &&
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

  async function handleDecision(decision: "approve" | "request_changes") {
    if (!claim) return;
    if (decision === "request_changes" && !comment.trim()) {
      setCommentError(
        "A comment is required when asking for changes, so the team knows what to fix.",
      );
      return;
    }
    setCommentError(null);
    setDecisionError(null);
    setNotice(null);
    setDeciding(true);
    try {
      const response = await apiFetch<{ data: Claim }>(
        `/claims/${claimId}/review`,
        {
          method: "POST",
          body: {
            decision,
            comment: comment.trim() || undefined,
          },
          orgId: org.id,
        },
      );
      setClaim(response.data);
      setNotice(
        decision === "approve"
          ? "Claim approved. It is now ready to submit."
          : "Changes requested. The claim is back to evidence required.",
      );
      setComment("");
    } catch (err) {
      setDecisionError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not record the review decision.",
      );
    } finally {
      setDeciding(false);
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

  const nextAction = reviewNextAction(
    claim?.status ?? "potential",
    readiness.readiness_percentage,
    readiness.missing.length,
  );

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

      <Card>
        <CardHeader
          title="What is still missing"
          description="Plain-language checklist of what blocks submission."
        />
        <CardBody>
          {readiness.missing.length === 0 ? (
            <p className="text-sm text-slate-700">
              Nothing is missing. Every required document is attached, so
              this claim can move to ready to submit once a reviewer
              approves it.
            </p>
          ) : (
            <ul className="space-y-2">
              {readiness.missing.map((item) => (
                <li
                  key={item.value}
                  className="flex items-start gap-2 text-sm text-slate-700"
                >
                  <span aria-hidden="true" className="text-red-600">✕</span>
                  <span>
                    <span className="font-medium text-slate-900">
                      {item.label}
                    </span>{" "}
                    is missing. Attach it from the claim page before the
                    claim can be submitted.
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-4 text-sm">
            <Link
              href={`/claims/${claimId}`}
              className="font-medium text-brand-700 hover:underline"
            >
              Open the claim to attach evidence
            </Link>
          </p>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Review decision"
          description="Approve the claim, or ask for changes and send it back for evidence."
        />
        <CardBody>
          <Alert tone="info">{nextAction}</Alert>
          {decisionError && (
            <div className="mt-4">
              <Alert tone="error">{decisionError}</Alert>
            </div>
          )}
          {!canDecide && claim && (
            <p className="mt-4 text-sm text-slate-500">
              {claim.status === "under_review"
                ? "Only owners, admins, and operations managers can approve or request changes."
                : `This claim is ${claim.status_label.toLowerCase()}, so it cannot be approved or sent back from here.`}
            </p>
          )}
          <form
            onSubmit={(event: FormEvent) => event.preventDefault()}
            className="mt-4 space-y-4"
            noValidate
          >
            <Textarea
              id="review-comment"
              label="Comment"
              rows={3}
              value={comment}
              onChange={(event) => setComment(event.target.value)}
              error={commentError ?? undefined}
              help="Required when asking for changes. Optional when approving."
              placeholder="What did you check, and what still needs fixing?"
            />
            <div className="flex flex-wrap gap-2">
              <Button
                loading={deciding}
                disabled={!canDecide}
                onClick={() => handleDecision("approve")}
              >
                Approve
              </Button>
              <Button
                variant="secondary"
                loading={deciding}
                disabled={!canDecide}
                onClick={() => handleDecision("request_changes")}
              >
                Request changes
              </Button>
            </div>
          </form>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Reviewer notes"
          description="Notes from reviewers working on this claim."
        />
        <CardBody>
          <ReviewNotes
            claimId={claimId}
            orgId={org.id}
            canAdd={canReviewClaims(myRole)}
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
