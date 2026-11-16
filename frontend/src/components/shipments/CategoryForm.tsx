"use client";

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Input, Textarea } from "@/components/ui/Input";
import type { FieldErrors } from "@/lib/validation";

export interface CategoryPayload {
  label: string;
  description: string | null;
}

interface CategoryFormProps {
  submitting: boolean;
  /** Field errors returned by the server (422), keyed by field name. */
  serverErrors: FieldErrors;
  /** A non-field error from the server, such as a duplicate name. */
  serverMessage: string | null;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (payload: CategoryPayload) => Promise<void>;
}

/**
 * Add form for a custom event category. The parent owns the API call;
 * this form owns field state and client-side validation. The label
 * becomes the category name; the server derives a URL slug from it
 * and rejects names that collide with an existing category.
 */
export function CategoryForm({
  submitting,
  serverErrors,
  serverMessage,
  submitLabel,
  onCancel,
  onSubmit,
}: CategoryFormProps) {
  const [label, setLabel] = useState("");
  const [description, setDescription] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});

  function fieldError(field: string): string | undefined {
    return errors[field] ?? serverErrors[field];
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!label.trim()) {
      setErrors({ label: "Category name is required." });
      return;
    }
    setErrors({});
    await onSubmit({
      label: label.trim(),
      description: description.trim() ? description.trim() : null,
    });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      <Input
        id="category_label"
        label="Category name"
        type="text"
        value={label}
        onChange={(event) => setLabel(event.target.value)}
        error={fieldError("label")}
        placeholder="For example, Weigh Station Fee"
        help="A custom charge category for this organization."
      />
      <Textarea
        id="category_description"
        label="Description (optional)"
        rows={2}
        value={description}
        onChange={(event) => setDescription(event.target.value)}
        error={fieldError("description")}
        placeholder="When this category applies."
      />
      {serverMessage && (
        <p role="alert" className="text-sm text-red-600">
          {serverMessage}
        </p>
      )}
      <div className="flex gap-3">
        <Button type="submit" loading={submitting}>
          {submitLabel}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
