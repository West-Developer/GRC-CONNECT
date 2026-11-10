import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  claimQueueTicket,
  listQueueTickets,
  updateQueueTicket,
} from "@/features/services/queue-ticket-service"
import {
  setAuthTokenProvider,
  setUnauthorizedHandler,
} from "@/features/services/api-client"

const paginationLinks = {
  first: "https://api.test/queue-tickets?page=1",
  last: "https://api.test/queue-tickets?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const ticket = {
  type: "queue_ticket",
  id: 1,
  enrollment_id: 9,
  student_number: "2026-0001",
  student_id: 4,
  is_freshman: false,
  // The API now says whether the ticket belongs to a transferee too (ADR 0043, amended 2026-10-05).
  is_transferee: false,
  ticket_number: "Q000009",
  queue_date: "2026-07-30",
  status: "waiting",
  status_label: "Waiting",
  priority: "regular",
  priority_label: "Regular",
  created_at: "2026-07-30T00:00:00Z",
  served_at: null,
  requeued_at: null,
} as const

describe("queue-ticket-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    setAuthTokenProvider(() => "portal-token")
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    setAuthTokenProvider(() => null)
    setUnauthorizedHandler(() => undefined)
  })

  it("lists queue tickets with filters and pagination", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: [ticket],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )

    const result = await listQueueTickets({ page: 1, per_page: 20 })

    expect(result.data).toEqual([ticket])
    expect(fetchMock.mock.calls[0]?.[0]).toContain("page=1&per_page=20")
  })

  it("transitions a ticket to serving", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: { ...ticket, status: "serving" } })),
    )

    const result = await updateQueueTicket(1, { action: "serve" })

    expect(result.status).toBe("serving")
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/queue-tickets/1")
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("PATCH")
  })

  it("claims a queue ticket for the signed-in student", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: ticket }), { status: 201 }),
    )

    await expect(claimQueueTicket()).resolves.toEqual(ticket)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/queue-tickets"),
      expect.objectContaining({ method: "POST" }),
    )
  })

  it("claims a queue ticket for a student by number", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: ticket }), { status: 201 }),
    )

    await claimQueueTicket("2026-08-00001")

    const [, init] = fetchMock.mock.calls[0] ?? []
    expect(JSON.parse(init?.body as string)).toEqual({
      student_number: "2026-08-00001",
    })
  })

  it("keeps Accounting claims on the portal bearer without a kiosk header", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ data: ticket }), { status: 201 }),
      ),
    )

    await claimQueueTicket()
    await claimQueueTicket("2026-08-00001")

    for (const [, init] of fetchMock.mock.calls) {
      expect(init?.headers).toMatchObject({
        Authorization: "Bearer portal-token",
      })
      expect(init?.headers).not.toHaveProperty("X-Queue-Kiosk-Token")
    }
  })

  it("uses separate Student and kiosk credentials without invoking the portal 401 handler", async () => {
    const portalUnauthorizedHandler = vi.fn()
    setUnauthorizedHandler(portalUnauthorizedHandler)
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: {} }), { status: 401 }),
    )

    await expect(
      claimQueueTicket(undefined, {
        studentToken: "student-token",
        kioskToken: "kiosk-token",
      }),
    ).rejects.toMatchObject({ status: 401 })

    const [, init] = fetchMock.mock.calls[0] ?? []
    expect(init?.headers).toMatchObject({
      Authorization: "Bearer student-token",
      "X-Queue-Kiosk-Token": "kiosk-token",
    })
    expect(portalUnauthorizedHandler).not.toHaveBeenCalled()
  })
})
