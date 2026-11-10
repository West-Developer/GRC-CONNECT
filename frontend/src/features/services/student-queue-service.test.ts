import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  setAuthTokenProvider,
  setUnauthorizedHandler,
} from "@/features/services/api-client"
import { getStudentQueueView } from "@/features/services/student-queue-service"

const queueView = {
  type: "student_queue_view",
  stage: "pending_payment",
  can_claim: true,
  ticket: {
    ticket_number: "Q000009",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 2,
    announce_count: 2,
  },
  now_serving_ticket_number: "Q000007",
  upcoming_ticket_numbers: ["Q000008", "Q000009"],
  cut_off_today: false,
} as const

describe("student-queue-service", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const portalUnauthorizedHandler = vi.fn()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "portal-token")
    setUnauthorizedHandler(portalUnauthorizedHandler)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    setAuthTokenProvider(() => null)
    setUnauthorizedHandler(() => undefined)
  })

  it("returns the complete published Student queue view", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: queueView })),
    )

    await expect(getStudentQueueView()).resolves.toEqual(queueView)
  })

  it("reads a ticket from a server that predates the announce counter as never announced", async () => {
    const olderTicket: Record<string, unknown> = { ...queueView.ticket }
    delete olderTicket.announce_count
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ data: { ...queueView, ticket: olderTicket } }),
      ),
    )

    await expect(getStudentQueueView()).resolves.toMatchObject({
      ticket: { ticket_number: "Q000009", announce_count: 0 },
    })
  })

  it("rejects a queue ticket that exposes a Student identity field", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            ...queueView,
            ticket: { ...queueView.ticket, student_number: "2026-08-00001" },
          },
        }),
      ),
    )

    await expect(getStudentQueueView()).rejects.toMatchObject({
      kind: "contract",
      message:
        "The API responded, but its Student queue view did not match the published v1 contract.",
    })
  })

  it("uses an explicit Student bearer without invoking the portal 401 handler", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: {} }), { status: 401 }),
    )

    await expect(
      getStudentQueueView(undefined, "student-token"),
    ).rejects.toMatchObject({
      status: 401,
    })

    const [, init] = fetchMock.mock.calls[0] ?? []
    expect(init?.headers).toMatchObject({
      Authorization: "Bearer student-token",
    })
    expect(portalUnauthorizedHandler).not.toHaveBeenCalled()
  })

  it("keeps the portal 401 handler for the default bearer", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: {} }), { status: 401 }),
    )

    await expect(getStudentQueueView()).rejects.toMatchObject({ status: 401 })

    expect(portalUnauthorizedHandler).toHaveBeenCalledOnce()
  })
})
