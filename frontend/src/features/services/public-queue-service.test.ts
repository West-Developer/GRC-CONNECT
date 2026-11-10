import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { setAuthTokenProvider } from "@/features/services/api-client"
import { getPublicQueueView } from "@/features/services/public-queue-service"

const TOKEN = "a".repeat(48)

function urlOf(input: RequestInfo | URL | undefined): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : (input?.url ?? "")
}

const queueView = {
  type: "student_queue_view",
  stage: "freshman_awaiting_payment",
  can_claim: false,
  ticket: {
    ticket_number: "Q003",
    status: "waiting",
    status_label: "Waiting",
    priority: "regular",
    priority_label: "Regular",
    position: 1,
    announce_count: 0,
  },
  now_serving_ticket_number: "Q001",
  upcoming_ticket_numbers: ["Q002", "Q003"],
  cut_off_today: false,
} as const

describe("public-queue-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    // A signed-in portal user on the same browser must not leak into this call.
    setAuthTokenProvider(() => "portal-token")
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    setAuthTokenProvider(() => null)
    fetchMock.mockReset()
  })

  it("reads the live queue for a link token without sending any credential", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: queueView })),
    )

    await expect(getPublicQueueView(TOKEN)).resolves.toEqual(queueView)

    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(urlOf(url)).toContain(`/api/v1/public/queue-view/${TOKEN}`)
    expect(init?.headers).not.toHaveProperty("Authorization")
  })

  it("percent-encodes the token so it cannot change the path", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: queueView })),
    )

    await getPublicQueueView("a/b?c#d")

    const [url] = fetchMock.mock.calls[0] ?? []
    expect(urlOf(url)).toContain("/api/v1/public/queue-view/a%2Fb%3Fc%23d")
  })

  it("rejects a response that carries more than the published contract", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          data: {
            ...queueView,
            ticket: { ...queueView.ticket, student_number: "2026-10-00001" },
          },
        }),
      ),
    )

    await expect(getPublicQueueView(TOKEN)).rejects.toMatchObject({
      kind: "contract",
    })
  })

  it("surfaces a not-found link as an error with its status", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          error: { code: "NOT_FOUND", message: "Not found.", request_id: "r1" },
        }),
        { status: 404 },
      ),
    )

    await expect(getPublicQueueView(TOKEN)).rejects.toMatchObject({
      kind: "http",
      status: 404,
    })
  })
})
