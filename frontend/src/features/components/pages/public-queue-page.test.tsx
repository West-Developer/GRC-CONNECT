import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { act, render, screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { PublicQueuePage } from "@/features/components/pages/public-queue-page"
import { setLocation } from "@/tests/navigation-mock"

vi.mock("@/features/hooks/use-queue-call-alert", () => ({
  useQueueCallAlert: () => ({
    isCalled: false,
    callMessage: null,
    soundEnabled: false,
    soundPreferred: false,
    enableSound: vi.fn(),
    disableSound: vi.fn(),
  }),
}))

const TOKEN = "k".repeat(48)

function urlOf(input: RequestInfo | URL | undefined): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : (input?.url ?? "")
}

function view(overrides: Record<string, unknown> = {}) {
  return {
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
    ...overrides,
  }
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <PublicQueuePage />
    </QueryClientProvider>,
  )
}

describe("PublicQueuePage", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("shows the freshman their own number and who the Cashier is calling, without any sign-in", async () => {
    setLocation(`/queue/live?t=${TOKEN}`)
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: view() })),
    )

    renderPage()

    expect(
      await screen.findByRole("heading", { name: /cashier queue/i }),
    ).toBeInTheDocument()
    expect((await screen.findAllByText("Q003")).length).toBeGreaterThan(0)
    expect(screen.getByText("Q001")).toBeInTheDocument()
    expect(screen.getByText(/go to the Cashier/i)).toBeInTheDocument()
    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(urlOf(url)).toContain(`/api/v1/public/queue-view/${TOKEN}`)
    expect(init?.headers).not.toHaveProperty("Authorization")
  })

  it("keeps the numbers fresh by asking again every few seconds", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    setLocation(`/queue/live?t=${TOKEN}`)
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: view() })))
      .mockResolvedValue(
        new Response(
          JSON.stringify({ data: view({ now_serving_ticket_number: "Q002" }) }),
        ),
      )

    renderPage()
    await screen.findByText("Q001")

    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000)
    })

    expect(
      await screen.findByText("Q002", { selector: "p" }),
    ).toBeInTheDocument()
    expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it("says the link is incomplete, and asks nothing, when there is no token", async () => {
    setLocation("/queue/live")

    renderPage()

    expect(
      await screen.findByText(/this link is incomplete/i),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("says the link is no longer valid, and stops asking, when the server does not know it", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    setLocation(`/queue/live?t=${TOKEN}`)
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: { code: "NOT_FOUND", message: "Not found.", request_id: "r1" },
        }),
        { status: 404 },
      ),
    )

    renderPage()

    expect(await screen.findByText(/no longer valid/i)).toBeInTheDocument()
    const callsAfterFirstAnswer = fetchMock.mock.calls.length
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000)
    })
    expect(fetchMock.mock.calls.length).toBe(callsAfterFirstAnswer)
  })

  it("keeps trying, with a calm message, when the server cannot be reached", async () => {
    setLocation(`/queue/live?t=${TOKEN}`)
    fetchMock.mockRejectedValue(new TypeError("network down"))

    renderPage()

    expect(
      await screen.findByText(/live queue is temporarily unavailable/i),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations", async () => {
    setLocation(`/queue/live?t=${TOKEN}`)
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: view() })),
    )

    const { container } = renderPage()
    await screen.findByText("Q001")

    expect(await axe(container)).toHaveNoViolations()
  })
})
