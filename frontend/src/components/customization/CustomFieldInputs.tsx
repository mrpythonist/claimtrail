"use client";

import { Input, Select } from "@/components/ui/Input";
import { formatCustomFieldValue } from "@/lib/customization";
import type {
  CustomField,
  CustomFieldValues,
} from "@/lib/types";

/**
 * Renders one input per active custom field definition, for the
 * shared shipment and claim forms. Values are keyed by field_key
 * and reported back as a whole object so the form can send them as
 * `custom_field_values`. A failed definitions fetch leaves the
 * list empty and the form works as before.
 */
export function CustomFieldInputs({
  fields,
  values,
  onChange,
  errors = {},
}: {
  fields: CustomField[];
  values: CustomFieldValues;
  onChange: (values: CustomFieldValues) => void;
  errors?: Record<string, string>;
}) {
  const active = fields.filter((field) => field.is_active);
  if (active.length === 0) return null;

  function setValue(fieldKey: string, value: string | number | boolean | null) {
    onChange({ ...values, [fieldKey]: value });
  }

  return (
    <div className="space-y-4">
      <p className="text-sm font-medium text-slate-900">Custom fields</p>
      <div className="grid gap-4 sm:grid-cols-2">
        {active.map((field) => {
          const error = errors[`custom_field_values.${field.field_key}`];
          const value = values[field.field_key];
          const id = `custom-field-${field.field_key}`;
          switch (field.field_type) {
            case "number":
              return (
                <Input
                  key={field.id}
                  id={id}
                  label={field.label}
                  type="number"
                  value={value === null || value === undefined ? "" : String(value)}
                  onChange={(event) =>
                    setValue(
                      field.field_key,
                      event.target.value === "" ? null : Number(event.target.value),
                    )
                  }
                  error={error}
                />
              );
            case "date":
              return (
                <Input
                  key={field.id}
                  id={id}
                  label={field.label}
                  type="date"
                  value={typeof value === "string" ? value : ""}
                  onChange={(event) =>
                    setValue(field.field_key, event.target.value || null)
                  }
                  error={error}
                />
              );
            case "select":
              return (
                <Select
                  key={field.id}
                  id={id}
                  label={field.label}
                  value={typeof value === "string" ? value : ""}
                  onChange={(event) =>
                    setValue(field.field_key, event.target.value || null)
                  }
                  error={error}
                >
                  <option value="">Choose an option</option>
                  {(field.options ?? []).map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </Select>
              );
            case "boolean":
              return (
                <div key={field.id}>
                  <label className="flex items-center gap-2 pt-7 text-sm text-slate-700">
                    <input
                      id={id}
                      type="checkbox"
                      checked={value === true}
                      onChange={(event) =>
                        setValue(field.field_key, event.target.checked)
                      }
                      className="h-4 w-4 rounded border-slate-300"
                    />
                    {field.label}
                  </label>
                  {error && (
                    <p role="alert" className="mt-1 text-xs text-red-600">
                      {error}
                    </p>
                  )}
                </div>
              );
            default:
              return (
                <Input
                  key={field.id}
                  id={id}
                  label={field.label}
                  type="text"
                  value={typeof value === "string" ? value : ""}
                  onChange={(event) =>
                    setValue(field.field_key, event.target.value)
                  }
                  error={error}
                />
              );
          }
        })}
      </div>
    </div>
  );
}

/** Read-only list of custom field values for a detail page. */
export function CustomFieldValuesDisplay({
  fields,
  values,
}: {
  fields: CustomField[];
  values: CustomFieldValues | null | undefined;
}) {
  const entries = Object.entries(values ?? {}).filter(
    ([, value]) => value !== null && value !== undefined && value !== "",
  );
  if (entries.length === 0) return null;

  return (
    <dl>
      {entries.map(([key, value]) => {
        const field = fields.find((item) => item.field_key === key);
        return (
          <div
            key={key}
            className="border-b border-slate-100 py-2 last:border-b-0 sm:grid sm:grid-cols-3 sm:gap-4"
          >
            <dt className="text-sm font-medium text-slate-500">
              {field?.label ?? key}
            </dt>
            <dd className="mt-1 text-sm text-slate-900 sm:mt-0 sm:col-span-2">
              {field
                ? formatCustomFieldValue(field, value)
                : String(value)}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}
