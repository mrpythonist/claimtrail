"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { TIMEZONES } from "@/lib/timezones";

export default function NewOrganizationPage() {
  const router = useRouter();
  const { createOrganization } = useAuth();
  const [name, setName] = useState("");
  const [timezone, setTimezone] = useState("UTC");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Organization name is required.");
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await createOrganization(name.trim(), timezone);
      router.push("/dashboard");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Create an organization"
        description="A new workspace for a company or team. You will be its owner."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Create organization" },
        ]}
      />
      <Card>
        <CardBody>
          <form onSubmit={handleSubmit} className="max-w-md space-y-4" noValidate>
            {error && <Alert tone="error">{error}</Alert>}
            <Input
              id="org-name"
              label="Organization name"
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Acme Trucking Co."
            />
            <Select
              id="org-timezone"
              label="Timezone"
              value={timezone}
              onChange={(event) => setTimezone(event.target.value)}
            >
              {TIMEZONES.map((tz) => (
                <option key={tz.value} value={tz.value}>
                  {tz.label}
                </option>
              ))}
            </Select>
            <div className="flex gap-3">
              <Button type="submit" loading={loading}>
                Create organization
              </Button>
              <Button variant="secondary" onClick={() => router.back()}>
                Cancel
              </Button>
            </div>
          </form>
        </CardBody>
      </Card>
    </div>
  );
}
