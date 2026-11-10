import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  getQueueKioskCredential,
  updateQueueKioskCredential,
} from "@/features/services/queue-kiosk-credential-service"
import { setAuthTokenProvider } from "@/features/services/api-client"

const credential = {
  type: "queue_kiosk_credential",
  email: "queue.kiosk@grc.edu.ph",
  password: "temporary-password",
} as const

describe("queue-kiosk-credential-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "accounting-token")
  })

  afterEach(() => vi.unstubAllGlobals())

  it("gets the exact queue kiosk credential envelope", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )

    await expect(getQueueKioskCredential()).resolves.toEqual(credential)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/v1/queue-kiosk-credential"),
      expect.objectContaining({ method: "GET" }),
    )
  })

  it("updates only the validated kiosk password", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential })),
    )

    await expect(
      updateQueueKioskCredential({ password: "rotated-password" }),
    ).resolves.toEqual(credential)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/v1/queue-kiosk-credential"),
      expect.objectContaining({
        method: "PUT",
        body: JSON.stringify({ password: "rotated-password" }),
      }),
    )
  })

  it("rejects undeclared response fields as a contract error", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ data: { ...credential, token: "leaked" } }),
      ),
    )

    await expect(getQueueKioskCredential()).rejects.toMatchObject({
      kind: "contract",
    })
  })

  it("rejects extra top-level envelope fields and invalid update payload fields", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: credential, meta: {} })),
    )

    await expect(getQueueKioskCredential()).rejects.toMatchObject({
      kind: "contract",
    })
    await expect(
      updateQueueKioskCredential({
        password: "rotated-password",
        unexpected: "value",
      } as never),
    ).rejects.toThrow()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it("rejects a password longer than 255 characters before calling the API", async () => {
    await expect(
      updateQueueKioskCredential({ password: "a".repeat(256) }),
    ).rejects.toThrow()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
