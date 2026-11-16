"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { validateRegisterForm, type FieldErrors } from "@/lib/validation";

export default function RegisterPage() {
  const router = useRouter();
  const { register } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateRegisterForm({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    setGeneralError(null);
    setLoading(true);
    try {
      await register(name.trim(), email.trim(), password, passwordConfirmation);
      router.push("/dashboard");
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
    <AuthLayout
      title="Create your account"
      description="Then create an organization, or accept an invitation to join one."
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {generalError && <Alert tone="error">{generalError}</Alert>}
        <Input
          id="name"
          label="Full name"
          type="text"
          autoComplete="name"
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={errors.name}
        />
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
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={errors.password}
          help="At least 8 characters."
        />
        <Input
          id="password_confirmation"
          label="Confirm password"
          type="password"
          autoComplete="new-password"
          value={passwordConfirmation}
          onChange={(event) => setPasswordConfirmation(event.target.value)}
          error={errors.password_confirmation}
        />
        <Button type="submit" loading={loading} className="w-full">
          Create account
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-slate-600">
        Already have an account?{" "}
        <Link href="/login" className="text-brand-700 hover:underline">
          Sign in
        </Link>
      </p>
    </AuthLayout>
  );
}
