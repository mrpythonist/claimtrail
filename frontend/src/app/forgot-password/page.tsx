"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { validateEmailField } from "@/lib/validation";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | undefined>(undefined);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const fieldError = validateEmailField(email);
    setError(fieldError);
    if (fieldError) return;

    setMessage(null);
    setLoading(true);
    try {
      const data = await apiFetch<{ message: string }>("/auth/forgot-password", {
        method: "POST",
        body: { email: email.trim() },
        withOrg: false,
        redirectOnUnauthorized: false,
      });
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
    <AuthLayout
      title="Forgot your password?"
      description="Enter your email address and we will send you a reset link."
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {message && <Alert tone="success">{message}</Alert>}
        <Input
          id="email"
          label="Email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          error={error}
        />
        <Button type="submit" loading={loading} className="w-full">
          Send reset link
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-slate-600">
        <Link href="/login" className="text-brand-700 hover:underline">
          Back to sign in
        </Link>
      </p>
    </AuthLayout>
  );
}
