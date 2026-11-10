import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  deleteAuthenticatedJson,
  getJson,
  patchAuthenticatedJson,
  postAuthenticatedForm,
  setAuthTokenProvider,
  setUnauthorizedHandler,
} from "@/features/services/api-client"

describe("getJson", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("maps a valid API error envelope without leaking response details", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "VALIDATION_FAILED",
            message: "The submitted data is invalid.",
            errors: {
              email: ["The email field is required."],
            },
            request_id: "request-001",
          },
        }),
        { status: 422 },
      ),
    )

    await expect(getJson("/api/v1/example")).rejects.toMatchObject({
      kind: "http",
      code: "VALIDATION_FAILED",
      message: "The submitted data is invalid.",
      requestId: "request-001",
      status: 422,
    })
  })

  it("rejects an error envelope with undeclared fields", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "NOT_FOUND",
            message: "The requested resource was not found.",
            errors: {},
            request_id: "request-002",
            debug: "must not be consumed",
          },
        }),
        { status: 404 },
      ),
    )

    await expect(getJson("/api/v1/missing")).rejects.toMatchObject({
      kind: "http",
      message: "The public API returned an unexpected error response.",
      status: 404,
    })
  })

  it("classifies a non-JSON response as a contract failure", async () => {
    fetchMock.mockResolvedValue(
      new Response("<html>unexpected</html>", { status: 200 }),
    )

    await expect(getJson("/api/v1/health")).rejects.toMatchObject({
      kind: "contract",
      message: "The public API returned a response that was not valid JSON.",
      status: 200,
    })
  })

  it("sends authenticated PATCH requests with the bearer token and no browser credentials", async () => {
    setAuthTokenProvider(() => "1|the-token")
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ data: { read_at: "2026-07-29T12:00:00Z" } }),
        {
          status: 200,
        },
      ),
    )

    await patchAuthenticatedJson("/api/v1/notifications/7/read", {})

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(init.method).toBe("PATCH")
    expect(init.credentials).toBe("omit")
    expect(init.cache).toBe("no-store")
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer 1|the-token",
    )
  })

  it("accepts an empty successful DELETE response", async () => {
    const onUnauthorized = vi.fn()
    setAuthTokenProvider(() => "1|the-token")
    setUnauthorizedHandler(onUnauthorized)
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(
      deleteAuthenticatedJson("/api/v1/faculty-availabilities/7"),
    ).resolves.toBeNull()

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(init.method).toBe("DELETE")
    expect(init.credentials).toBe("omit")
    expect(init.cache).toBe("no-store")
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer 1|the-token",
    )
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it("captures Retry-After as seconds on a throttled response", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "THROTTLED",
            message: "Too many requests.",
            errors: {},
            request_id: "request-004",
          },
        }),
        { status: 429, headers: { "Retry-After": "30" } },
      ),
    )

    await expect(getJson("/api/v1/example")).rejects.toMatchObject({
      status: 429,
      retryAfterSeconds: 30,
    })
  })

  it("leaves retryAfterSeconds undefined when the header is absent", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "THROTTLED",
            message: "Too many requests.",
            errors: {},
            request_id: "request-005",
          },
        }),
        { status: 429 },
      ),
    )

    await expect(getJson("/api/v1/example")).rejects.toMatchObject({
      status: 429,
      retryAfterSeconds: undefined,
    })
  })

  it("invokes the unauthorized handler when an authenticated PATCH is rejected", async () => {
    const onUnauthorized = vi.fn()
    setAuthTokenProvider(() => "1|expired-token")
    setUnauthorizedHandler(onUnauthorized)
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "UNAUTHENTICATED",
            message: "Authentication is required.",
            errors: {},
            request_id: "request-003",
          },
        }),
        { status: 401 },
      ),
    )

    await expect(
      patchAuthenticatedJson("/api/v1/notifications/7/read", {}),
    ).rejects.toMatchObject({ status: 401 })

    expect(onUnauthorized).toHaveBeenCalledOnce()
  })
})

describe("postAuthenticatedForm", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "1|token")
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("sends a FormData body as-is, leaving the multipart Content-Type to the browser", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: { ok: true } }), { status: 201 }),
    )
    const form = new FormData()
    form.append("file", new File(["%PDF"], "tor.pdf", { type: "application/pdf" }))

    await postAuthenticatedForm("/api/v1/tor-documents", form)

    const [, init] = fetchMock.mock.calls[0]
    expect(init?.method).toBe("POST")
    expect(init?.body).toBe(form)
    const headers = init?.headers as Record<string, string>
    expect(headers["Content-Type"]).toBeUndefined()
    expect(headers.Authorization).toBe("Bearer 1|token")
  })
})
