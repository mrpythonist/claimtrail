"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError, getToken } from "@/lib/api";

function VerifyEmailContent() {
  const searchParams = useSearchParams();
  const status = searchParams.get("status");
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const signedIn = getToken() !== null;

  async function resend() {
    setMessage(null);
    setLoading(true);
    try {
      const data = await apiFetch<{ message: string }>(
        "/email/verification-notification",
        { method: "POST", withOrg: false },
      );
      setMessage(data.message);
    } catch (err) {
      setMessage(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout title="Email verification">
      {status === "verified" && (
        <Alert tone="success">
          Your email address is verified. You can now use ClaimTrail.
        </Alert>
      )}
      {status === "invalid" && (
        <Alert tone="error">
          This verification link is not valid. It may have been used already or
          copied incorrectly. Request a new one below.
        </Alert>
      )}
      {!status && (
        <Alert tone="info">
          We sent a verification link to your email address. Open it to verify
          your account. If it does not arrive, check your spam folder or request
          a new one below.
        </Alert>
      )}
      {message && (
        <div className="mt-4">
          <Alert tone="success">{message}</Alert>
        </div>
      )}
      <div className="mt-6 space-y-3">
        {signedIn ? (
          <Button onClick={resend} loading={loading} className="w-full">
            Send a new verification link
          </Button>
        ) : (
          <p className="text-sm text-slate-600">
            To request a new link,{" "}
            <Link href="/login" className="text-brand-700 hover:underline">
              sign in
            </Link>{" "}
            first.
          </p>
        )}
        <Link
          href="/dashboard"
          className="block text-center text-sm text-brand-700 hover:underline"
        >
          Go to the dashboard
        </Link>
      </div>
    </AuthLayout>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <VerifyEmailContent />
    </Suspense>
  );
}
