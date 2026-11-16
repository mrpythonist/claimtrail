"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import type { FieldErrors } from "@/lib/validation";

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors: FieldErrors = {};
    if (!password) {
      formErrors.password = "Password is required.";
    } else if (password.length < 8) {
      formErrors.password = "Password must be at least 8 characters.";
    }
    if (passwordConfirmation !== password) {
      formErrors.password_confirmation = "Passwords do not match.";
    }
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    setGeneralError(null);
    setLoading(true);
    try {
      await apiFetch("/auth/reset-password", {
        method: "POST",
        body: { token, email, password, password_confirmation: passwordConfirmation },
        withOrg: false,
        redirectOnUnauthorized: false,
      });
      router.push("/login?reset=1");
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setErrors(mapped);
      } else {
        setGeneralError(
          err instanceof ApiRequestError
            ? err.message
            : "Something went wrong. Please try again.",
        );
      }
    } finally {
      setLoading(false);
    }
  }

  if (!token || !email) {
    return (
      <AuthLayout title="Reset your password">
        <Alert tone="error">
          This reset link is incomplete. Request a new one from the{" "}
          <Link href="/forgot-password" className="underline">
            forgot password
          </Link>{" "}
          page.
        </Alert>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Reset your password"
      description={`Setting a new password for ${email}.`}
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {generalError && <Alert tone="error">{generalError}</Alert>}
        <Input
          id="password"
          label="New password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={errors.password}
          help="At least 8 characters."
        />
        <Input
          id="password_confirmation"
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
          value={passwordConfirmation}
          onChange={(event) => setPasswordConfirmation(event.target.value)}
          error={errors.password_confirmation}
        />
        <Button type="submit" loading={loading} className="w-full">
          Reset password
        </Button>
      </form>
    </AuthLayout>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <ResetPasswordForm />
    </Suspense>
  );
}
