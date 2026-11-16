import type { InputHTMLAttributes, SelectHTMLAttributes } from "react";

interface FieldProps {
  label: string;
  id: string;
  error?: string;
  help?: string;
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & FieldProps;

export function Input({ label, id, error, help, ...props }: InputProps) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
        className={`w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600 disabled:bg-slate-50 disabled:text-slate-500 ${
          error ? "border-red-500" : "border-slate-300"
        }`}
        {...props}
      />
      {help && !error && (
        <p id={`${id}-help`} className="mt-1 text-xs text-slate-500">
          {help}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & FieldProps;

export function Select({ label, id, error, help, children, ...props }: SelectProps) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-slate-700">
        {label}
      </label>
      <select
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
        className={`w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600 disabled:bg-slate-50 disabled:text-slate-500 ${
          error ? "border-red-500" : "border-slate-300"
        }`}
        {...props}
      >
        {children}
      </select>
      {help && !error && (
        <p id={`${id}-help`} className="mt-1 text-xs text-slate-500">
          {help}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
