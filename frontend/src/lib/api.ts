import type { ApiError } from "./types";

const API_URL =
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

const TOKEN_KEY = "claimtrail_token";
const ORG_KEY = "claimtrail_org_id";

/** Error thrown for non-2xx API responses. Carries field errors when present. */
export class ApiRequestError extends Error {
  status: number;
  fields: Record<string, string[]>;

  constructor(status: number, body: ApiError) {
    super(body.message || "Request failed");
    this.name = "ApiRequestError";
    this.status = status;
    this.fields = body.errors ?? {};
  }

  /** First error message for a field, if any. */
  fieldError(field: string): string | undefined {
    return this.fields[field]?.[0];
  }
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (typeof window === "undefined") return;
  if (token === null) {
    window.localStorage.removeItem(TOKEN_KEY);
  } else {
    window.localStorage.setItem(TOKEN_KEY, token);
  }
}

export function getStoredOrgId(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(ORG_KEY);
}

export function setStoredOrgId(orgId: string | null): void {
  if (typeof window === "undefined") return;
  if (orgId === null) {
    window.localStorage.removeItem(ORG_KEY);
  } else {
    window.localStorage.setItem(ORG_KEY, orgId);
  }
}

export function clearSession(): void {
  setToken(null);
  setStoredOrgId(null);
}

interface ApiOptions {
  method?: "GET" | "POST" | "PUT" | "DELETE";
  body?: unknown;
  /** Organization id for tenant-scoped requests. Defaults to the stored org. */
  orgId?: string | null;
  /** Set false for public endpoints that must not send the org header. */
  withOrg?: boolean;
  /** Set false to keep the session on a 401 (used by the login flow itself). */
  redirectOnUnauthorized?: boolean;
}

/**
 * Typed fetch wrapper for the ClaimTrail API.
 *
 * Sends the stored Sanctum token and the current organization id, parses
 * JSON, and throws ApiRequestError on failure. A 401 clears the session
 * and sends the user back to the login page, unless the caller opts out.
 */
export async function apiFetch<T>(
  path: string,
  options: ApiOptions = {},
): Promise<T> {
  const {
    method = "GET",
    body,
    orgId,
    withOrg = true,
    redirectOnUnauthorized = true,
  } = options;

  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  const token = getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (withOrg) {
    const resolvedOrg = orgId ?? getStoredOrgId();
    if (resolvedOrg) {
      headers["X-Organization-Id"] = resolvedOrg;
    }
  }

  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiRequestError(0, {
      message: "Could not reach the server. Check your connection and try again.",
    });
  }

  const data = (await response.json().catch(() => ({}))) as ApiError | T;

  if (!response.ok) {
    if (response.status === 401 && redirectOnUnauthorized) {
      clearSession();
      if (typeof window !== "undefined" && !window.location.pathname.startsWith("/login")) {
        // api.ts has no access to the Next router, and a full reload is
        // desirable here: it clears any in-memory state after the
        // session expires.
        // eslint-disable-next-line @next/next/no-location-assign-relative-destination
        window.location.href = "/login";
      }
    }
    const errorBody = data as ApiError;
    throw new ApiRequestError(response.status, {
      message: errorBody.message || `Request failed (${response.status})`,
      errors: errorBody.errors,
    });
  }

  return data as T;
}
