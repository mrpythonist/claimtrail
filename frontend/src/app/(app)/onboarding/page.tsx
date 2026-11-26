"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { OnboardingChecklist } from "@/components/onboarding/OnboardingChecklist";
import { TrialBanner } from "@/components/onboarding/TrialBanner";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageOrganization } from "@/lib/permissions";
import type { OnboardingProgress, Organization } from "@/lib/types";

/**
 * Plain-language explanation of what each onboarding step means and
 * why it matters. The API supplies the short description; this adds
 * the "what to do" detail for the guided page.
 */
const STEP_GUIDANCE: Record<string, string> = {
  create_organization:
    "Your organization is the workspace for your company. Everything in ClaimTrail, shipments, claims, evidence, belongs to one organization.",
  invite_member:
    "Invite the people who work on claims with you. Each member gets a role that decides what they can see and change.",
  create_shipment:
    "A shipment is one load. Add the broker, the carrier, and the pickup and delivery appointments so events and claims have somewhere to live.",
  add_event:
    "An event is something chargeable that happened on a load, for example detention at a dock. Events are what claims are built from.",
  upload_evidence:
    "Upload the documents that prove the charge: rate confirmations, bills of lading, receipts, and photos. Claims need evidence before they can be submitted.",
  create_claim:
    "A claim asks a broker to pay for one event. Set the amount, link the event, and attach the evidence that supports it.",
  review_claim:
    "Review checks that the claim has everything it needs. Once the evidence is complete, the claim is ready to send to the broker.",
};

function OnboardingContent({ org }: { org: Organization }) {
  const [progress, setProgress] = useState<OnboardingProgress | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dismissing, setDismissing] = useState(false);

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: OnboardingProgress }>("/onboarding", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setProgress(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the onboarding checklist.",
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

  async function handleDismiss() {
    setDismissing(true);
    try {
      await apiFetch("/onboarding/dismiss", {
        method: "POST",
        orgId: org.id,
      });
      setProgress((current) =>
        current ? { ...current, dismissed: true } : current,
      );
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not dismiss onboarding.",
      );
    } finally {
      setDismissing(false);
    }
  }

  if (loading) return <Spinner label="Loading onboarding" />;
  if (error && !progress) return <Alert tone="error">{error}</Alert>;
  if (!progress) return <Alert tone="info">No onboarding data yet.</Alert>;

  if (progress.all_completed || progress.dismissed) {
    return (
      <Card>
        <CardBody>
          <Alert tone="success">
            {progress.all_completed
              ? "Onboarding is complete. You have finished every first step."
              : "Onboarding is dismissed. You can still work through the steps below from the dashboard, shipments, and claims pages."}
          </Alert>
          <p className="mt-4 text-sm">
            <Link
              href="/dashboard"
              className="font-medium text-brand-700 hover:underline"
            >
              Back to the dashboard
            </Link>
          </p>
        </CardBody>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {error && <Alert tone="error">{error}</Alert>}
      <TrialBanner orgId={org.id} />
      <Card>
        <CardHeader
          title="First steps"
          description="Work through these steps in order. Each one takes a few minutes and gets your organization ready to recover money."
        />
        <CardBody>
          <OnboardingChecklist
            progress={progress}
            canDismiss={canManageOrganization(myRole)}
            dismissing={dismissing}
            onDismiss={handleDismiss}
          />
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="What each step means" />
        <CardBody>
          <dl className="space-y-4">
            {progress.steps.map((step) => (
              <div key={step.key}>
                <dt className="text-sm font-medium text-slate-900">
                  {step.label}
                </dt>
                <dd className="mt-0.5 text-sm text-slate-500">
                  {STEP_GUIDANCE[step.key] ?? step.description}
                </dd>
              </div>
            ))}
          </dl>
        </CardBody>
      </Card>
    </div>
  );
}

export default function OnboardingPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Onboarding"
        description="First steps for getting your organization set up in ClaimTrail."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Onboarding" },
        ]}
      />
      {currentOrg ? (
        <OnboardingContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to start onboarding.</Alert>
      )}
    </div>
  );
}
