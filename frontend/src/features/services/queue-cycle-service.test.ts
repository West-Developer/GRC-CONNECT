import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  cutOffQueueCycle,
  getQueueCycle,
  resumeQueueCycle,
} from "@/features/services/queue-cycle-service"
import { setAuthTokenProvider } from "@/features/services/api-client"

const cycle = {
  type: "queue_cycle",
  id: 1,
  opened_on: "2026-08-23",
  status: "open",
  status_label: "Open",
  cut_off_at: null,
  cut_off_service_date: null,
} as const

describe("queue-cycle-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "test-token")
  })
  afterEach(() => vi.unstubAllGlobals())

  it("returns null when no cycle is open", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: null })))

    await expect(getQueueCycle()).resolves.toBeNull()
  })

  it("returns the open cycle", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: cycle })))

    await expect(getQueueCycle()).resolves.toEqual(cycle)
  })

  it("cuts off the queue", async () => {
    const cutOff = { ...cycle, status: "cut_off" as const }
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: cutOff })))

    await expect(cutOffQueueCycle()).resolves.toEqual(cutOff)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/queue-cycle/cut-off"),
      expect.objectContaining({ method: "POST" }),
    )
  })

  it("resumes the queue", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: cycle })))

    await expect(resumeQueueCycle()).resolves.toEqual(cycle)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/queue-cycle/resume"),
      expect.objectContaining({ method: "POST" }),
    )
  })
})
