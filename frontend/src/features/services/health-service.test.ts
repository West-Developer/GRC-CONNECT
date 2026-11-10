import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  getPublicApiHealth,
  PUBLIC_API_HEALTH_PATH,
} from "@/features/services/health-service"

const validHealthEnvelope = {
  data: {
    type: "service-health",
    service: "grc-enrollment-api",
    status: "ok",
    api_version: "v1",
    generated_at: "2026-07-26T08:45:00Z",
  },
} as const

describe("getPublicApiHealth", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("requests and parses the exact public v1 health contract", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(validHealthEnvelope), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    )

    await expect(getPublicApiHealth()).resolves.toEqual(
      validHealthEnvelope.data,
    )
    expect(fetchMock).toHaveBeenCalledWith(
      `http://127.0.0.1:8000${PUBLIC_API_HEALTH_PATH}`,
      expect.objectContaining({
        method: "GET",
        credentials: "omit",
        cache: "no-store",
      }),
    )
  })

  it("rejects a successful response that violates the published contract", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            ...validHealthEnvelope.data,
            service: "unexpected-service",
          },
        }),
        {
          status: 200,
          headers: { "Content-Type": "application/json" },
        },
      ),
    )

    await expect(getPublicApiHealth()).rejects.toMatchObject({
      kind: "contract",
      message:
        "The API responded, but its health payload did not match the published v1 contract.",
    })
  })

  it("rejects undeclared fields instead of silently stripping them", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          ...validHealthEnvelope,
          internal_environment: "production",
        }),
        {
          status: 200,
          headers: { "Content-Type": "application/json" },
        },
      ),
    )

    await expect(getPublicApiHealth()).rejects.toMatchObject({
      kind: "contract",
    })
  })
})
