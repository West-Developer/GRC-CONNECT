import {
  focusManager,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query"
import { act, renderHook } from "@testing-library/react"
import type { ReactNode } from "react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  studentQueueQueryKey,
  useKioskQueueClaimMutation,
  useStudentQueueQuery,
} from "@/features/hooks/use-student-queue"

const queueView = {
  type: "student_queue_view",
  stage: "pending_payment",
  can_claim: true,
  ticket: null,
  now_serving_ticket_number: null,
  upcoming_ticket_numbers: [],
  cut_off_today: false,
} as const

const ticket = {
  type: "queue_ticket",
  id: 1,
  enrollment_id: 9,
  student_number: "2026-08-0001",
  student_id: 4,
  is_freshman: false,
  ticket_number: "Q000009",
  queue_date: "2026-08-23",
  status: "waiting",
  status_label: "Waiting",
  priority: "regular",
  priority_label: "Regular",
  created_at: "2026-08-23T00:00:00Z",
  served_at: null,
  requeued_at: null,
} as const

function createQueryClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
}

function createWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    )
  }
}

describe("useStudentQueueQuery", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const visibilityDescriptor = Object.getOwnPropertyDescriptor(
    document,
    "visibilityState",
  )
  let visibilityState: DocumentVisibilityState = "visible"

  beforeEach(() => {
    vi.useFakeTimers()
    vi.stubGlobal("fetch", fetchMock)
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      get: () => visibilityState,
    })
  })

  afterEach(() => {
    vi.runOnlyPendingTimers()
    vi.useRealTimers()
    focusManager.setFocused(undefined)
    vi.unstubAllGlobals()
    if (visibilityDescriptor) {
      Object.defineProperty(document, "visibilityState", visibilityDescriptor)
    }
  })

  it("uses only the viewer ID in the Student queue query key", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: queueView })),
    )
    const queryClient = createQueryClient()
    const wrapper = createWrapper(queryClient)

    renderHook(
      () =>
        useStudentQueueQuery({
          viewerId: "student-7",
          enabled: true,
          token: "student-token",
        }),
      { wrapper },
    )

    await act(async () => {
      await Promise.resolve()
    })

    expect(studentQueueQueryKey("student-7")).toEqual([
      "student-queue",
      "student-7",
    ])
    expect(
      queryClient
        .getQueryCache()
        .getAll()
        .map((query) => query.queryKey),
    ).toEqual([["student-queue", "student-7"]])
    expect(JSON.stringify(queryClient.getQueryCache().getAll())).not.toContain(
      "student-token",
    )
    expect(JSON.stringify(queryClient.getQueryCache().getAll())).not.toContain(
      "kiosk-token",
    )
  })

  it("does not fetch while there is no Student viewer", async () => {
    const queryClient = createQueryClient()

    renderHook(
      () =>
        useStudentQueueQuery({
          viewerId: null,
          enabled: true,
          token: "student-token",
        }),
      { wrapper: createWrapper(queryClient) },
    )

    await act(async () => {
      await Promise.resolve()
    })

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("polls again after exactly three seconds", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: queueView })),
    )
    const queryClient = createQueryClient()

    renderHook(
      () =>
        useStudentQueueQuery({
          viewerId: "student-7",
          enabled: true,
          token: "student-token",
        }),
      { wrapper: createWrapper(queryClient) },
    )

    await act(async () => {
      await Promise.resolve()
    })
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_999)
    })
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1)
    })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it("refetches on window focus even when the previous data is fresh", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: queueView })),
    )
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    })

    renderHook(
      () =>
        useStudentQueueQuery({
          viewerId: "student-7",
          enabled: true,
          token: "student-token",
        }),
      { wrapper: createWrapper(queryClient) },
    )

    await act(async () => {
      await Promise.resolve()
    })
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await act(async () => {
      focusManager.setFocused(false)
      focusManager.setFocused(true)
      await Promise.resolve()
    })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it("continues polling while the document is hidden", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: queueView })),
    )
    const queryClient = createQueryClient()

    renderHook(
      () =>
        useStudentQueueQuery({
          viewerId: "student-7",
          enabled: true,
          token: "student-token",
        }),
      { wrapper: createWrapper(queryClient) },
    )

    await act(async () => {
      await Promise.resolve()
    })
    visibilityState = "hidden"

    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000)
    })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})

describe("useKioskQueueClaimMutation", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("invalidates only the claiming Student queue after a kiosk claim", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: ticket }), { status: 201 }),
    )
    const queryClient = createQueryClient()
    const targetKey = studentQueueQueryKey("student-7")
    const otherKey = studentQueueQueryKey("student-8")
    queryClient.setQueryData(targetKey, queueView)
    queryClient.setQueryData(otherKey, queueView)
    const { result } = renderHook(
      () =>
        useKioskQueueClaimMutation({
          viewerId: "student-7",
          studentToken: "student-token",
          kioskToken: "kiosk-token",
        }),
      { wrapper: createWrapper(queryClient) },
    )

    await result.current.mutateAsync({})

    expect(queryClient.getQueryState(targetKey)?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(otherKey)?.isInvalidated).toBe(false)
    const [, init] = fetchMock.mock.calls[0] ?? []
    expect(init?.headers).toMatchObject({
      Authorization: "Bearer student-token",
      "X-Queue-Kiosk-Token": "kiosk-token",
    })
  })
})
