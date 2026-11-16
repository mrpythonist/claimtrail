"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

function AcceptInvitationContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { user, loading, refreshUser } = useAuth();
  const token = searchParams.get("token") ?? "";
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [accepting, setAccepting] = useState(false);

  async function accept() {
    setError(null);
    setMessage(null);
    setAccepting(true);
    try {
      await apiFetch("/invitations/accept", {
        method: "POST",
        body: { token },
        withOrg: false,
      });
      await refreshUser();
      router.push("/dashboard");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setAccepting(false);
    }
  }

  if (!token) {
    return (
      <AuthLayout title="Accept invitation">
        <Alert tone="error">
          This invitation link is incomplete. Ask the person who invited you to
          send it again.
        </Alert>
      </AuthLayout>
    );
  }

  if (loading) {
    return (
      <AuthLayout title="Accept invitation">
        <Spinner label="Checking your session" />
      </AuthLayout>
    );
  }

  if (!user) {
    const returnTo = `/invitations/accept?token=${encodeURIComponent(token)}`;
    return (
      <AuthLayout
        title="Accept invitation"
        description="Sign in with the email address the invitation was sent to, then come back here to accept it."
      >
        <div className="space-y-3">
          <Link
            href={`/login?next=${encodeURIComponent(returnTo)}`}
            className="block rounded-md bg-brand-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-brand-800"
          >
            Sign in to accept
          </Link>
          <Link
            href="/register"
            className="block rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Create an account first
          </Link>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Accept invitation"
      description={`Signed in as ${user.email}. The invitation must have been sent to this email address.`}
    >
      {error && <Alert tone="error">{error}</Alert>}
      {message && <Alert tone="success">{message}</Alert>}
      <Button onClick={accept} loading={accepting} className="w-full">
        Accept the invitation
      </Button>
      <p className="mt-4 text-sm text-slate-600">
        If this is not the right account,{" "}
        <Link href="/login" className="text-brand-700 hover:underline">
          sign in with a different one
        </Link>
        .
      </p>
    </AuthLayout>
  );
}

export default function AcceptInvitationPage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <AcceptInvitationContent />
    </Suspense>
  );
}
