import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  confirmNoCreditDeclaration,
  declareNoCredit,
  getMyNoCreditDeclaration,
  listNoCreditDeclarations,
} from "@/features/services/no-credit-declaration-service"

const declaration = {
  type: "no_credit_declaration",
  id: 3,
  student_id: 4,
  student_number: "2026-10-00007",
  student_name: "Denmar Curtivo",
  status: "pending",
  declared_at: "2026-10-23T01:00:00Z",
  confirmed_at: null,
}

function urlAndMethod(call: Parameters<typeof fetch> | undefined) {
  const input = call?.[0]
  const raw =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.toString()
        : (input?.url ?? "")
  return {
    path: new URL(raw, "http://localhost").pathname,
    method: call?.[1]?.method ?? "GET",
  }
}

describe("no-credit-declaration-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("reads the student's own declaration, or null when they have made none", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: null })),
    )
    await expect(getMyNoCreditDeclaration()).resolves.toBeNull()

    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: declaration })),
    )
    await expect(getMyNoCreditDeclaration()).resolves.toMatchObject({
      status: "pending",
    })
    expect(urlAndMethod(fetchMock.mock.calls[1])).toEqual({
      path: "/api/v1/no-credit-declaration",
      method: "GET",
    })
  })

  it("declares, lists and confirms against the documented endpoints", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: declaration }), { status: 201 }),
    )
    await declareNoCredit()
    expect(urlAndMethod(fetchMock.mock.calls[0])).toEqual({
      path: "/api/v1/no-credit-declaration",
      method: "POST",
    })

    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: [declaration] })),
    )
    await expect(listNoCreditDeclarations()).resolves.toHaveLength(1)
    expect(urlAndMethod(fetchMock.mock.calls[1]).path).toBe(
      "/api/v1/no-credit-declarations",
    )

    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            ...declaration,
            status: "confirmed",
            confirmed_at: "2026-10-23T02:00:00Z",
          },
        }),
      ),
    )
    await expect(confirmNoCreditDeclaration(3)).resolves.toMatchObject({
      status: "confirmed",
    })
    expect(urlAndMethod(fetchMock.mock.calls[2])).toEqual({
      path: "/api/v1/no-credit-declarations/3/confirm",
      method: "POST",
    })
  })

  it("rejects a response that does not match the contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ data: { ...declaration, status: "maybe" } }),
      ),
    )

    await expect(getMyNoCreditDeclaration()).rejects.toThrow(/contract/)
  })
})
