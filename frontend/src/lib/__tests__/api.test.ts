import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiUpload, ApiRequestError } from "../api";

const mockedFetch = vi.fn();

function jsonResponse(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  mockedFetch.mockReset();
  vi.stubGlobal("fetch", mockedFetch);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("apiUpload retry-once", () => {
  it("retries once on a network failure and returns the second response", async () => {
    mockedFetch
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce(
        jsonResponse({ data: { id: "file-1" } }, 201),
      );

    const result = await apiUpload<{ data: { id: string } }>(
      "/evidence",
      new FormData(),
      { orgId: "org-1" },
    );

    expect(result).toEqual({ data: { id: "file-1" } });
    expect(mockedFetch).toHaveBeenCalledTimes(2);
  });

  it("retries once on a 5xx response and returns the second response", async () => {
    mockedFetch
      .mockResolvedValueOnce(jsonResponse({ message: "Server error" }, 500))
      .mockResolvedValueOnce(
        jsonResponse({ data: { id: "file-1" } }, 201),
      );

    const result = await apiUpload<{ data: { id: string } }>(
      "/evidence",
      new FormData(),
      { orgId: "org-1" },
    );

    expect(result).toEqual({ data: { id: "file-1" } });
    expect(mockedFetch).toHaveBeenCalledTimes(2);
  });

  it("does not retry a 4xx validation error", async () => {
    mockedFetch.mockResolvedValueOnce(
      jsonResponse(
        { message: "The file field is required.", errors: { file: ["The file field is required."] } },
        422,
      ),
    );

    const error = await apiUpload("/evidence", new FormData(), {
      orgId: "org-1",
    }).catch((err: unknown) => err);

    expect(error).toBeInstanceOf(ApiRequestError);
    expect((error as ApiRequestError).status).toBe(422);
    expect(mockedFetch).toHaveBeenCalledTimes(1);
  });

  it("carries the full error body on a 402 plan-limit refusal", async () => {
    mockedFetch.mockResolvedValueOnce(
      jsonResponse(
        {
          message: "The Free plan includes 5 claims per month.",
          error: "plan_limit_exceeded",
          limit: {
            resource: "claims",
            limit: 5,
            used: 5,
            plan: "free",
            upgrade_plan: "starter",
            upgrade_plan_name: "Starter",
          },
        },
        402,
      ),
    );

    const error = await apiUpload("/claims", new FormData(), {
      orgId: "org-1",
    }).catch((err: unknown) => err);

    expect(error).toBeInstanceOf(ApiRequestError);
    expect((error as ApiRequestError).status).toBe(402);
    expect((error as ApiRequestError).body.error).toBe("plan_limit_exceeded");
    expect((error as ApiRequestError).body.limit?.upgrade_plan_name).toBe(
      "Starter",
    );
    expect(mockedFetch).toHaveBeenCalledTimes(1);
  });

  it("throws the unreachable error when both attempts fail on the network", async () => {
    mockedFetch.mockRejectedValue(new TypeError("Failed to fetch"));

    const error = await apiUpload("/evidence", new FormData(), {
      orgId: "org-1",
    }).catch((err: unknown) => err);

    expect(error).toBeInstanceOf(ApiRequestError);
    expect((error as ApiRequestError).status).toBe(0);
    expect(mockedFetch).toHaveBeenCalledTimes(2);
  });

  it("throws the 5xx error when the retry also returns 5xx", async () => {
    mockedFetch.mockResolvedValue(
      jsonResponse({ message: "Server error" }, 503),
    );

    const error = await apiUpload("/evidence", new FormData(), {
      orgId: "org-1",
    }).catch((err: unknown) => err);

    expect(error).toBeInstanceOf(ApiRequestError);
    expect((error as ApiRequestError).status).toBe(503);
    expect(mockedFetch).toHaveBeenCalledTimes(2);
  });
});
