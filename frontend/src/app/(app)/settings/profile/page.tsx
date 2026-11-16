"use client";

import { useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { NotificationPreferences } from "@/components/settings/NotificationPreferences";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { FieldErrors } from "@/lib/validation";

export default function ProfilePage() {
  const { user, currentOrg, refreshUser } = useAuth();
  // The app layout only renders this page once the user is loaded, so
  // initializing from the user directly is safe and needs no effect.
  const [name, setName] = useState(user?.name ?? "");
  const [profileMessage, setProfileMessage] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [savingProfile, setSavingProfile] = useState(false);

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [passwordErrors, setPasswordErrors] = useState<FieldErrors>({});
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null);
  const [passwordGeneralError, setPasswordGeneralError] = useState<string | null>(null);
  const [savingPassword, setSavingPassword] = useState(false);

  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [resending, setResending] = useState(false);

  if (!user) {
    return null;
  }

  async function handleProfileSubmit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setProfileError("Name is required.");
      return;
    }
    setProfileError(null);
    setProfileMessage(null);
    setSavingProfile(true);
    try {
      await apiFetch("/auth/profile", {
        method: "PUT",
        body: { name: name.trim() },
        withOrg: false,
      });
      setProfileMessage("Profile saved.");
      await refreshUser();
    } catch (err) {
      setProfileError(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setSavingProfile(false);
    }
  }

  async function handlePasswordSubmit(event: FormEvent) {
    event.preventDefault();
    const errors: FieldErrors = {};
    if (!currentPassword) {
      errors.current_password = "Current password is required.";
    }
    if (!newPassword) {
      errors.password = "New password is required.";
    } else if (newPassword.length < 8) {
      errors.password = "Password must be at least 8 characters.";
    }
    if (newPasswordConfirmation !== newPassword) {
      errors.password_confirmation = "Passwords do not match.";
    }
    setPasswordErrors(errors);
    if (Object.keys(errors).length > 0) return;

    setPasswordGeneralError(null);
    setPasswordMessage(null);
    setSavingPassword(true);
    try {
      await apiFetch("/auth/password", {
        method: "PUT",
        body: {
          current_password: currentPassword,
          password: newPassword,
          password_confirmation: newPasswordConfirmation,
        },
        withOrg: false,
      });
      setPasswordMessage("Password changed.");
      setCurrentPassword("");
      setNewPassword("");
      setNewPasswordConfirmation("");
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.fields).length > 0) {
        const mapped: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.fields)) {
          mapped[field] = messages[0];
        }
        setPasswordErrors(mapped);
      } else {
        setPasswordGeneralError(
          err instanceof ApiRequestError
            ? err.message
            : "Something went wrong. Please try again.",
        );
      }
    } finally {
      setSavingPassword(false);
    }
  }

  async function resendVerification() {
    setResendMessage(null);
    setResending(true);
    try {
      const response = await apiFetch<{ message: string }>(
        "/email/verification-notification",
        { method: "POST", withOrg: false },
      );
      setResendMessage(response.message);
    } catch (err) {
      setResendMessage(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setResending(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Profile settings"
        description="Your account details and password."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Profile settings" },
        ]}
      />

      <div className="space-y-6">
        <Card>
          <CardHeader title="Account" description="Your personal details." />
          <CardBody>
            <form
              onSubmit={handleProfileSubmit}
              className="max-w-md space-y-4"
              noValidate
            >
              {profileMessage && <Alert tone="success">{profileMessage}</Alert>}
              {profileError && <Alert tone="error">{profileError}</Alert>}
              <Input
                id="profile-name"
                label="Full name"
                type="text"
                value={name}
                onChange={(event) => setName(event.target.value)}
              />
              <div>
                <p className="mb-1 text-sm font-medium text-slate-700">Email</p>
                <p className="text-sm text-slate-900">{user.email}</p>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  {user.email_verified_at ? (
                    <Badge tone="success">Verified</Badge>
                  ) : (
                    <>
                      <Badge tone="warning">Not verified</Badge>
                      <button
                        type="button"
                        onClick={resendVerification}
                        disabled={resending}
                        className="text-sm font-medium text-brand-700 hover:underline disabled:opacity-60"
                      >
                        {resending ? "Sending..." : "Send verification link"}
                      </button>
                    </>
                  )}
                  {resendMessage && (
                    <span className="text-sm text-slate-600">{resendMessage}</span>
                  )}
                </div>
              </div>
              <Button type="submit" loading={savingProfile}>
                Save profile
              </Button>
            </form>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Change password"
            description="Your new password must be at least 8 characters."
          />
          <CardBody>
            <form
              onSubmit={handlePasswordSubmit}
              className="max-w-md space-y-4"
              noValidate
            >
              {passwordMessage && <Alert tone="success">{passwordMessage}</Alert>}
              {passwordGeneralError && (
                <Alert tone="error">{passwordGeneralError}</Alert>
              )}
              <Input
                id="current-password"
                label="Current password"
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
                error={passwordErrors.current_password}
              />
              <Input
                id="new-password"
                label="New password"
                type="password"
                autoComplete="new-password"
                value={newPassword}
                onChange={(event) => setNewPassword(event.target.value)}
                error={passwordErrors.password}
              />
              <Input
                id="new-password-confirmation"
                label="Confirm new password"
                type="password"
                autoComplete="new-password"
                value={newPasswordConfirmation}
                onChange={(event) => setNewPasswordConfirmation(event.target.value)}
                error={passwordErrors.password_confirmation}
              />
              <Button type="submit" loading={savingPassword}>
                Change password
              </Button>
            </form>
          </CardBody>
        </Card>

        {currentOrg ? (
          <NotificationPreferences key={currentOrg.id} org={currentOrg} />
        ) : (
          <Card>
            <CardHeader
              title="Notification preferences"
              description="Notification settings are per organization."
            />
            <CardBody>
              <p className="text-sm text-slate-600">
                Join or create an organization to set which events notify you.
              </p>
            </CardBody>
          </Card>
        )}
      </div>
    </div>
  );
}
