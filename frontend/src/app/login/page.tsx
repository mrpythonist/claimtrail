"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { validateLoginForm, type FieldErrors } from "@/lib/validation";

function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const next = searchParams.get("next") ?? "/dashboard";

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateLoginForm({ email, password });
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    setGeneralError(null);
    setLoading(true);
    try {
      await login(email.trim(), password);
      router.push(next.startsWith("/") ? next : "/dashboard");
    } catch (error) {
      if (error instanceof ApiRequestError) {
        if (Object.keys(error.fields).length > 0) {
          const mapped: FieldErrors = {};
          for (const [field, messages] of Object.entries(error.fields)) {
            mapped[field] = messages[0];
          }
          setErrors(mapped);
        } else {
          setGeneralError(error.message);
        }
      } else {
        setGeneralError("Something went wrong. Please try again.");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout title="Sign in" description="Welcome back to ClaimTrail.">
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {generalError && <Alert tone="error">{generalError}</Alert>}
        <Input
          id="email"
          label="Email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          error={errors.email}
        />
        <Input
          id="password"
          label="Password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={errors.password}
        />
        <Button type="submit" loading={loading} className="w-full">
          Sign in
        </Button>
      </form>
      <div className="mt-6 flex items-center justify-between text-sm">
        <Link href="/forgot-password" className="text-brand-700 hover:underline">
          Forgot password?
        </Link>
        <Link href="/register" className="text-brand-700 hover:underline">
          Create an account
        </Link>
      </div>
    </AuthLayout>
  );
}

export default function LoginPage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <LoginForm />
    </Suspense>
  );
}
