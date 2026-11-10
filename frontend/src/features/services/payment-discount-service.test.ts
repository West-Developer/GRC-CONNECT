import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  getPaymentDiscountSetting,
  updatePaymentDiscountSetting,
} from "@/features/services/payment-discount-service"

const setting = {
  type: "payment_discount_setting",
  percentage: "10.0",
  valid_from: null,
  valid_until: "2026-12-31",
  status: "active",
  updated_by: "Registrar Head",
  updated_at: "2026-10-12T01:00:00Z",
} as const

describe("payment-discount-service", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("reads the setting from the versioned endpoint", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: setting })))

    await expect(getPaymentDiscountSetting()).resolves.toEqual(setting)
    expect(fetchMock.mock.calls[0][0]).toContain("/api/v1/payment-discount-settings")
  })

  it("sends the percentage and both dates, with null clearing a date", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: setting })))

    await updatePaymentDiscountSetting({
      percentage: "12.5",
      valid_from: null,
      valid_until: "2026-12-31",
    })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain("/api/v1/payment-discount-settings")
    expect(init?.method).toBe("PUT")
    expect(JSON.parse(init?.body as string)).toEqual({
      percentage: "12.5",
      valid_from: null,
      valid_until: "2026-12-31",
    })
  })

  it("rejects a response that does not match the contract", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: { ...setting, status: "maybe" } })),
    )

    await expect(getPaymentDiscountSetting()).rejects.toThrow(/published v1 contract/)
  })
})
