"use client";

import { useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { ApiRequestError, apiFetch } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import type { FieldErrors } from "@/lib/validation";

const FLEET_SIZES = [
  "1 truck",
  "2 trucks",
  "3-10 trucks",
  "11-30 trucks",
  "31+ trucks",
  "Dispatch agency",
];

/**
 * The demo request form (v1.2.2). Posts to the public
 * POST /v1/leads endpoint with source demo_request. The endpoint
 * is idempotent by email, so submitting twice updates the same
 * lead instead of creating a duplicate.
 */
export function DemoRequestForm() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [company, setCompany] = useState("");
  const [fleetSize, setFleetSize] = useState("");
  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors: FieldErrors = {};
    if (!name.trim()) formErrors.name = "Your name is required.";
    if (!email.trim()) formErrors.email = "Your work email is required.";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      formErrors.email = "Enter a valid email address.";
    }
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    setGeneralError(null);
    setLoading(true);
    try {
      await apiFetch("/leads", {
        method: "POST",
        withOrg: false,
        body: {
          name: name.trim(),
          email: email.trim(),
          company: company.trim() || null,
          fleet_size: fleetSize || null,
          message: message.trim() || null,
          source: "demo_request",
        },
      });
      void trackEvent("demo_request_submitted", "/demo-request");
      setSubmitted(true);
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

  if (submitted) {
    return (
      <Alert tone="success">
        Thanks, {name.trim()}. Your demo request is in. We will be in touch
        at {email.trim()} to arrange a time.
      </Alert>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      {generalError && <Alert tone="error">{generalError}</Alert>}
      <Input
        id="name"
        label="Your name"
        type="text"
        autoComplete="name"
        value={name}
        onChange={(event) => setName(event.target.value)}
        error={errors.name}
      />
      <Input
        id="email"
        label="Work email"
        type="email"
        autoComplete="email"
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        error={errors.email}
      />
      <Input
        id="company"
        label="Company"
        type="text"
        autoComplete="organization"
        value={company}
        onChange={(event) => setCompany(event.target.value)}
        error={errors.company}
      />
      <div>
        <label
          htmlFor="fleet-size"
          className="mb-1 block text-sm font-medium text-slate-700"
        >
          Fleet size
        </label>
        <select
          id="fleet-size"
          value={fleetSize}
          onChange={(event) => setFleetSize(event.target.value)}
          className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none"
        >
          <option value="">Select a fleet size</option>
          {FLEET_SIZES.map((size) => (
            <option key={size} value={size}>
              {size}
            </option>
          ))}
        </select>
      </div>
      <div>
        <label
          htmlFor="message"
          className="mb-1 block text-sm font-medium text-slate-700"
        >
          What should the demo cover? (optional)
        </label>
        <textarea
          id="message"
          rows={4}
          value={message}
          onChange={(event) => setMessage(event.target.value)}
          className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none"
        />
        {errors.message && (
          <p className="mt-1 text-sm text-red-600">{errors.message}</p>
        )}
      </div>
      <Button type="submit" disabled={loading}>
        {loading ? "Sending..." : "Request a demo"}
      </Button>
      <p className="text-xs text-slate-500">
        Your details are used only to arrange the demo. They are not sold
        or shared.
      </p>
    </form>
  );
}
