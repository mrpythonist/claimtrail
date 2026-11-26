"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState, type FormEvent } from "react";
import { AuthLayout } from "@/components/layout/AuthLayout";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { isListedPlanKey, planName } from "@/lib/marketing";
import { getSignupPlan, setSignupPlan } from "@/lib/signup-plan";
import { validateRegisterForm, type FieldErrors } from "@/lib/validation";

function RegisterForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { register } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [chosenPlan, setChosenPlan] = useState<string | null>(null);

  /**
   * The pricing page links here with ?plan=growth (and so on).
   * Remember the choice so the onboarding page can offer the
   * matching trial after the organization is created. An unknown
   * plan value is ignored, and a choice stored from an earlier
   * visit survives without a query parameter.
   */
  useEffect(() => {
    const fromQuery = searchParams.get("plan");
    if (isListedPlanKey(fromQuery)) {
      setSignupPlan(fromQuery);
      setChosenPlan(fromQuery);
      return;
    }
    setChosenPlan(getSignupPlan());
  }, [searchParams]);

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
        {chosenPlan && (
          <Alert tone="info">
            You chose the {planName(chosenPlan)} plan. After you create
            your organization, start the 30-day free trial from the
            onboarding page. No card is required.
          </Alert>
        )}
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

export default function RegisterPage() {
  return (
    <Suspense fallback={<Spinner label="Loading" />}>
      <RegisterForm />
    </Suspense>
  );
}
