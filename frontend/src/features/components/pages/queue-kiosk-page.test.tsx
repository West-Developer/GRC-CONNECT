import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { kioskTokenStorageKey } from "@/features/kiosk/kiosk-token"
import { QueueKioskPage } from "@/features/components/pages/queue-kiosk-page"
import { QUEUE_KIOSK_AUTO_DONE_SECONDS } from "@/features/components/kiosk/queue-kiosk-student-session"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

const kioskUser = {
  type: "user" as const,
  id: 11,
  name: "Queue Kiosk",
  email: "kiosk@grc.test",
  role: "queue_kiosk" as const,
  role_label: "Queue Kiosk",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
  college: null,
}

const studentUser = {
  type: "user" as const,
  id: 22,
  name: "Student One",
  email: "student@grc.test",
  role: "student" as const,
  role_label: "Student",
  privacy_notice: agreedPrivacyNotice,
  status: "active",
  college: "ccs" as const,
}

const secondStudentUser = {
  ...studentUser,
  id: 23,
  name: "Student Two",
  email: "student-two@grc.test",
}

function auth(token: string, user: typeof kioskUser | typeof studentUser) {
  return new Response(
    JSON.stringify({
      data: {
        type: "auth-session",
        token,
        token_type: "Bearer",
        expires_at: null,
        user,
      },
    }),
  )
}

function queue(
  stage:
    | "no_active_enrollment"
    | "pending_registrar_approval"
    | "pending_payment"
    | "enrolled",
  options?: { canClaim?: boolean; ticket?: object | null },
) {
  return new Response(
    JSON.stringify({
      data: {
        type: "student_queue_view",
        stage,
        can_claim: options?.canClaim ?? false,
        ticket: options?.ticket ?? null,
        now_serving_ticket_number: null,
        upcoming_ticket_numbers: [],
        cut_off_today: false,
      },
    }),
  )
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  const result = render(
    <QueryClientProvider client={queryClient}>
      <QueueKioskPage requirePassword={false} welcomeMs={0} autoDoneSeconds={3600} />
    </QueryClientProvider>,
  )
  return { ...result, queryClient }
}

async function signInDeviceAndStudent(
  user: ReturnType<typeof userEvent.setup>,
) {
  await user.type(screen.getByLabelText("Device email"), "kiosk@grc.test")
  await user.type(screen.getByLabelText("Device password"), "secret")
  await user.click(screen.getByRole("button", { name: "Open Student sign-in" }))
  await screen.findByRole("heading", { name: "Student sign-in" })
  await user.type(screen.getByLabelText("Student email"), "student@grc.test")
  await user.type(screen.getByLabelText("Student password"), "secret")
  await user.click(screen.getByRole("button", { name: "View queue" }))
}

describe("QueueKioskPage", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    localStorage.clear()
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  describe("the Welcome screen before the device sign-in", () => {
    function renderWelcome() {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
      })
      fetchMock.mockResolvedValue(
        new Response(
          JSON.stringify({
            error: {
              code: "UNAUTHENTICATED",
              message: "Authentication is required.",
              errors: {},
              request_id: "request-1",
            },
          }),
          { status: 401 },
        ),
      )
      localStorage.setItem(kioskTokenStorageKey, "kiosk-token")
      return render(
        <QueryClientProvider client={queryClient}>
          <QueueKioskPage requirePassword={false} welcomeMs={3000} />
        </QueryClientProvider>,
      )
    }

    afterEach(() => vi.useRealTimers())

    it("plays a Welcome animation first and opens the device sign-in as a modal afterwards", async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true })
      renderWelcome()

      expect(
        await screen.findByRole("heading", { name: "Welcome" }),
      ).toBeInTheDocument()
      expect(screen.getByText("Cashier Queue Kiosk")).toBeInTheDocument()
      // Nothing to sign in to yet: no form, no modal, no student card.
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
      expect(screen.queryByLabelText("Device email")).not.toBeInTheDocument()

      await act(async () => {
        await vi.advanceTimersByTimeAsync(3000)
      })

      const dialog = await screen.findByRole("dialog")
      expect(
        within(dialog).getByRole("heading", { name: "Queue Kiosk sign-in" }),
      ).toBeInTheDocument()
      expect(within(dialog).getByLabelText("Device email")).toBeInTheDocument()
      expect(
        within(dialog).getByRole("button", { name: "Open Student sign-in" }),
      ).toBeInTheDocument()
    })

    it("lets a tap skip the animation", async () => {
      const user = userEvent.setup()
      renderWelcome()

      await user.click(
        await screen.findByRole("button", { name: "Tap to sign in" }),
      )

      expect(await screen.findByRole("dialog")).toBeInTheDocument()
      expect(
        screen.queryByRole("button", { name: "Tap to sign in" }),
      ).not.toBeInTheDocument()
    })

    it("keeps the device locked: the modal cannot be dismissed with Escape", async () => {
      const user = userEvent.setup()
      renderWelcome()
      await user.click(
        await screen.findByRole("button", { name: "Tap to sign in" }),
      )
      await screen.findByRole("dialog")

      await user.keyboard("{Escape}")

      expect(screen.getByRole("dialog")).toBeInTheDocument()
    })
  })

  it("moves from the restore status to the device sign-in form", async () => {
    let resolveRestore: ((response: Response) => void) | undefined
    localStorage.setItem(kioskTokenStorageKey, "kiosk-token")
    fetchMock.mockImplementationOnce(
      () =>
        new Promise<Response>((resolve) => {
          resolveRestore = resolve
        }),
    )
    const { container } = renderPage()
    expect(
      screen.getByRole("status", { name: "Restoring Queue Kiosk" }),
    ).toBeInTheDocument()

    resolveRestore?.(
      new Response(
        JSON.stringify({
          error: {
            code: "UNAUTHENTICATED",
            message: "Authentication is required.",
            errors: {},
            request_id: "request-1",
          },
        }),
        { status: 401 },
      ),
    )

    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(await axe(container)).toHaveNoViolations()
  })

  it("asks for the device password before signing the device out (default)", async () => {
    fetchMock.mockResolvedValueOnce(auth("kiosk-token", kioskUser))
    const user = userEvent.setup()
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    render(
      <QueryClientProvider client={queryClient}>
        <QueueKioskPage welcomeMs={0} autoDoneSeconds={3600} />
      </QueryClientProvider>,
    )
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await user.type(screen.getByLabelText("Device email"), "kiosk@grc.test")
    await user.type(screen.getByLabelText("Device password"), "secret")
    await user.click(
      screen.getByRole("button", { name: "Open Student sign-in" }),
    )
    await screen.findByRole("heading", { name: "Student sign-in" })

    await user.click(screen.getByRole("button", { name: "Sign out device" }))

    expect(
      await screen.findByRole("heading", { name: "Confirm Device Sign Out" }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText("Password")).toBeInTheDocument()
    // The page behind the dialog is still the signed-in kiosk (hidden from the
    // accessibility tree while the modal is open) until the password is confirmed.
    expect(
      screen.getByRole("heading", { name: "Student sign-in", hidden: true }),
    ).toBeInTheDocument()
  })

  it("persists a device session, then uses the in-memory Student token to request queue status", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })

    await signInDeviceAndStudent(user)

    await screen.findByText(
      "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
    )
    expect(localStorage.getItem(kioskTokenStorageKey)).toBe("kiosk-token")
    expect(localStorage.getItem("grc.auth-token.v1")).toBeNull()
    expect(fetchMock.mock.calls[2]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer student-token",
    })
  })

  it.each([
    [
      "no_active_enrollment",
      "You do not have an active enrollment for the current term.",
    ],
    [
      "pending_registrar_approval",
      "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
    ],
    ["enrolled", "Payment has been confirmed and your enrollment is complete."],
  ] as const)(
    "shows exact non-claimable %s guidance and Done",
    async (stage, guidance) => {
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue(stage))
      const user = userEvent.setup()
      renderPage()
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)

      expect(await screen.findByText(guidance)).toBeInTheDocument()
      expect(screen.getByRole("button", { name: "Done" })).toHaveClass(
        "queue-kiosk-done",
      )
    },
  )

  it("shows one touch-sized claim action, claims with both credentials, and refreshes the issued number", async () => {
    const ticket = {
      ticket_number: "Q001",
      status: "waiting",
      status_label: "Waiting",
      priority: "regular",
      priority_label: "Regular",
      position: 0,
    }
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              type: "queue_ticket",
              id: 1,
              enrollment_id: 1,
              student_number: "S1",
              student_id: 1,
              is_freshman: false,
              ticket_number: "Q001",
              queue_date: "2026-08-23",
              status: "waiting",
              status_label: "Waiting",
              priority: "regular",
              priority_label: "Regular",
              created_at: "2026-08-23T00:00:00Z",
              served_at: null,
              requeued_at: null,
            },
          }),
          { status: 201 },
        ),
      )
      .mockResolvedValueOnce(queue("pending_payment", { ticket }))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    const claim = await screen.findByRole("button", {
      name: "Claim queue number",
    })

    expect(claim).toHaveClass("queue-kiosk-claim")
    await user.click(claim)
    expect(await screen.findByText("Q001")).toBeInTheDocument()
    expect(fetchMock.mock.calls[3]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer student-token",
      "X-Queue-Kiosk-Token": "kiosk-token",
    })
  })

  describe("clearing the screen by itself once the queue number is shown", () => {
    const ticket = {
      ticket_number: "Q001",
      status: "waiting",
      status_label: "Waiting",
      priority: "regular",
      priority_label: "Regular",
      position: 0,
    }

    function renderWithCountdown(seconds?: number) {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
      })
      return render(
        <QueryClientProvider client={queryClient}>
          <QueueKioskPage
            requirePassword={false}
            welcomeMs={0}
            autoDoneSeconds={seconds}
          />
        </QueryClientProvider>,
      )
    }

    it("shows a 5 second countdown by default, with Done still available", async () => {
      expect(QUEUE_KIOSK_AUTO_DONE_SECONDS).toBe(5)
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue("pending_payment", { ticket }))
        .mockResolvedValue(new Response(null, { status: 204 }))
      const user = userEvent.setup()
      renderWithCountdown()
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)
      expect(await screen.findByText("Q001")).toBeInTheDocument()

      expect(screen.getByRole("timer")).toHaveTextContent(
        "This screen clears itself in 5 seconds. Press Done to finish now.",
      )
      expect(screen.getByRole("button", { name: "Done" })).toBeInTheDocument()
    })

    it("counts down, then returns to the Student sign-in and signs the Student out by itself", async () => {
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue("pending_payment", { ticket }))
        .mockResolvedValue(new Response(null, { status: 204 }))
      const user = userEvent.setup()
      renderWithCountdown(2)
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)
      expect(await screen.findByText("Q001")).toBeInTheDocument()
      expect(screen.getByRole("timer")).toHaveTextContent("in 2 seconds")

      // One second later it is down to 1 and the Student's ticket is still there.
      await waitFor(
        () =>
          expect(screen.getByRole("timer")).toHaveTextContent("in 1 second."),
        { timeout: 2500 },
      )
      expect(screen.getByText("Q001")).toBeInTheDocument()

      // Then, with nobody pressing Done, the screen is cleared.
      expect(
        await screen.findByRole(
          "heading",
          { name: "Student sign-in" },
          { timeout: 2500 },
        ),
      ).toBeInTheDocument()
      expect(screen.queryByText("Q001")).not.toBeInTheDocument()
      expect(screen.queryByText(/Student One/)).not.toBeInTheDocument()
      expect(screen.queryByRole("timer")).not.toBeInTheDocument()
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            String(input).includes("logout") &&
            (init?.headers as Record<string, string> | undefined)
              ?.Authorization === "Bearer student-token",
        ),
      ).toBe(true)
    })

    it("starts the countdown only after a queue number has been claimed", async () => {
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
        .mockResolvedValueOnce(
          new Response(
            JSON.stringify({
              data: {
                type: "queue_ticket",
                id: 1,
                enrollment_id: 1,
                student_number: "S1",
                student_id: 1,
                is_freshman: false,
                ticket_number: "Q001",
                queue_date: "2026-08-23",
                status: "waiting",
                status_label: "Waiting",
                priority: "regular",
                priority_label: "Regular",
                created_at: "2026-08-23T00:00:00Z",
                served_at: null,
                requeued_at: null,
              },
            }),
            { status: 201 },
          ),
        )
        .mockResolvedValueOnce(queue("pending_payment", { ticket }))
        .mockResolvedValue(new Response(null, { status: 204 }))
      const user = userEvent.setup()
      renderWithCountdown(60)
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)
      const claim = await screen.findByRole("button", {
        name: "Claim queue number",
      })

      // Before the number is claimed there is nothing on a timer.
      expect(screen.queryByRole("timer")).not.toBeInTheDocument()

      await user.click(claim)
      expect(await screen.findByText("Q001")).toBeInTheDocument()
      expect(screen.getByRole("timer")).toHaveTextContent("60 seconds")
    })

    it("lets Done end it straight away instead of waiting for the countdown", async () => {
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue("pending_payment", { ticket }))
        .mockResolvedValue(new Response(null, { status: 204 }))
      const user = userEvent.setup()
      renderWithCountdown(60)
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)
      await screen.findByText("Q001")
      expect(screen.getByRole("timer")).toHaveTextContent("60 seconds")

      await user.click(screen.getByRole("button", { name: "Done" }))

      expect(
        await screen.findByRole("heading", { name: "Student sign-in" }),
      ).toBeInTheDocument()
      expect(screen.queryByRole("timer")).not.toBeInTheDocument()
    })
  })

  it("skips claim for an existing ticket and removes every Student queue query before Done", async () => {
    const ticket = {
      ticket_number: "Q001",
      status: "waiting",
      status_label: "Waiting",
      priority: "regular",
      priority_label: "Regular",
      position: 0,
    }
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(
        queue("pending_payment", { canClaim: true, ticket }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await screen.findByText("Q001")
    expect(
      screen.queryByRole("button", { name: "Claim queue number" }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Done" }))
    await waitFor(() =>
      expect(
        screen.getByRole("heading", { name: "Student sign-in" }),
      ).toBeInTheDocument(),
    )
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
  })

  it("returns to device sign-in when a kiosk claim receives 403", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "FORBIDDEN",
              message: "Forbidden.",
              errors: {},
              request_id: "request-1",
            },
          }),
          { status: 403 },
        ),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )

    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    expect(localStorage.getItem(kioskTokenStorageKey)).toBeNull()
  })

  it("restores the device only after refresh and never recreates a Student queue session", async () => {
    localStorage.setItem(kioskTokenStorageKey, "kiosk-token")
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: kioskUser })),
    )
    renderPage()

    await screen.findByRole("heading", { name: "Student sign-in" })

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(
      screen.queryByText("Loading your live Cashier queue…"),
    ).not.toBeInTheDocument()
  })

  it("clears persistent device storage on explicit device sign-out", async () => {
    localStorage.setItem(kioskTokenStorageKey, "kiosk-token")
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: kioskUser })))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Student sign-in" })

    await user.click(screen.getByRole("button", { name: "Sign out device" }))

    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    expect(localStorage.getItem(kioskTokenStorageKey)).toBeNull()
  })

  it("keeps the Student session available and shows a validated non-403 API message", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "SERVER_ERROR",
              message: "Unavailable.",
              errors: {},
              request_id: "request-1",
            },
          }),
          { status: 500 },
        ),
      )
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)

    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )

    expect(await screen.findByRole("alert")).toHaveTextContent("Unavailable.")
    expect(
      screen.getByRole("button", { name: "Claim queue number" }),
    ).toBeEnabled()
  })

  it.each([
    ["connection", () => Promise.reject(new TypeError("offline"))],
    [
      "contract",
      () =>
        Promise.resolve(
          new Response(JSON.stringify({ data: { unexpected: true } }), {
            status: 201,
          }),
        ),
    ],
  ])(
    "uses a safe generic claim message for a %s failure",
    async (_kind, failClaim) => {
      fetchMock
        .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
        .mockResolvedValueOnce(auth("student-token", studentUser))
        .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
        .mockImplementationOnce(failClaim)
      const user = userEvent.setup()
      renderPage()
      await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
      await signInDeviceAndStudent(user)
      await user.click(
        await screen.findByRole("button", { name: "Claim queue number" }),
      )

      expect(await screen.findByRole("alert")).toHaveTextContent(
        "The queue number could not be claimed. Please try again.",
      )
      expect(screen.getByRole("button", { name: "Done" })).toBeEnabled()
    },
  )

  it("finishes only the Student session when its queue-status request returns 401", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "UNAUTHENTICATED",
              message: "Authentication is required.",
              errors: {},
              request_id: "request-1",
            },
          }),
          { status: 401 },
        ),
      )
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)

    expect(
      await screen.findByRole("heading", { name: "Student sign-in" }),
    ).toBeInTheDocument()
    expect(localStorage.getItem(kioskTokenStorageKey)).toBe("kiosk-token")
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
  })

  it("finishes only the Student session and clears queue cache when a claim returns 401", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "UNAUTHENTICATED",
              message: "Authentication is required.",
              errors: {},
              request_id: "request-claim-401",
            },
          }),
          { status: 401 },
        ),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )

    await screen.findByRole("heading", { name: "Student sign-in" })
    expect(localStorage.getItem(kioskTokenStorageKey)).toBe("kiosk-token")
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
  })

  it("keeps Done available while queue status is loading", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockReturnValueOnce(new Promise<Response>(() => undefined))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)

    expect(await screen.findByRole("button", { name: "Done" })).toBeEnabled()
    await user.click(screen.getByRole("button", { name: "Done" }))
    await screen.findByRole("heading", { name: "Student sign-in" })
  })

  it("keeps Done and an accessible retry available after a queue connection failure", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockRejectedValueOnce(new TypeError("offline"))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)

    const retry = await screen.findByRole("button", {
      name: "Retry queue status",
    })
    expect(screen.getByRole("button", { name: "Done" })).toBeEnabled()
    await user.click(retry)
    expect(
      await screen.findByText(
        "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
      ),
    ).toBeInTheDocument()
  })

  it("shows a validated non-forbidden API message for a retryable claim failure", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: {
              code: "QUEUE_STATE_CHANGED",
              message: "Your enrollment changed. Refresh and try again.",
              errors: {},
              request_id: "request-claim-conflict",
            },
          }),
          { status: 409 },
        ),
      )
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Your enrollment changed. Refresh and try again.",
    )
    expect(screen.getByRole("button", { name: "Done" })).toBeEnabled()
  })

  it("uses password-manager autocomplete, validates keyboard submission, and focuses the live error", async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    const email = screen.getByLabelText("Device email")
    const password = screen.getByLabelText("Device password")
    expect(email).toHaveAttribute("autocomplete", "username")
    expect(password).toHaveAttribute("autocomplete", "current-password")

    await user.tab()
    await user.keyboard("{Enter}")

    expect(
      await screen.findByText("Enter a valid email address."),
    ).toBeInTheDocument()
    expect(email).toHaveFocus()
  })

  it("submits both forms with Tab and Enter", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await user.type(screen.getByLabelText("Device email"), "kiosk@grc.test")
    await user.tab()
    await user.type(screen.getByLabelText("Device password"), "secret")
    await user.keyboard("{Enter}")
    await screen.findByRole("heading", { name: "Student sign-in" })
    await user.type(screen.getByLabelText("Student email"), "student@grc.test")
    await user.tab()
    await user.type(screen.getByLabelText("Student password"), "secret")
    await user.keyboard("{Enter}")
    expect(
      await screen.findByText(
        "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
      ),
    ).toBeInTheDocument()
  })

  it("aborts a pending claim on Done and ignores its stale success without recreating queue data", async () => {
    let resolveClaim: ((response: Response) => void) | undefined
    const ticketPayload = {
      data: {
        type: "queue_ticket",
        id: 1,
        enrollment_id: 1,
        student_number: "S1",
        student_id: 1,
        is_freshman: false,
        ticket_number: "Q001",
        queue_date: "2026-08-23",
        status: "waiting",
        status_label: "Waiting",
        priority: "regular",
        priority_label: "Regular",
        created_at: "2026-08-23T00:00:00Z",
        served_at: null,
        requeued_at: null,
      },
    }
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveClaim = resolve
          }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )
    const [, claimInit] = fetchMock.mock.calls[3] ?? []

    await user.click(screen.getByRole("button", { name: "Done" }))
    await screen.findByRole("heading", { name: "Student sign-in" })
    expect(claimInit?.signal!.aborted).toBe(true)
    resolveClaim?.(new Response(JSON.stringify(ticketPayload), { status: 201 }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5))
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
    expect(screen.queryByText("Q001")).not.toBeInTheDocument()
  })

  it("locks two synchronous claim activations to one POST", async () => {
    let resolveClaim: ((response: Response) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveClaim = resolve
          }),
      )
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    const claim = await screen.findByRole("button", {
      name: "Claim queue number",
    })

    fireEvent.click(claim)
    fireEvent.click(claim)
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4))
    resolveClaim?.(
      new Response(
        JSON.stringify({
          data: {
            type: "queue_ticket",
            id: 1,
            enrollment_id: 1,
            student_number: "S1",
            student_id: 1,
            is_freshman: false,
            ticket_number: "Q001",
            queue_date: "2026-08-23",
            status: "waiting",
            status_label: "Waiting",
            priority: "regular",
            priority_label: "Regular",
            created_at: "2026-08-23T00:00:00Z",
            served_at: null,
            requeued_at: null,
          },
        }),
        { status: 201 },
      ),
    )
  })

  it("aborts a pending claim on device sign-out and ignores a late forbidden response", async () => {
    let resolveClaim: ((response: Response) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveClaim = resolve
          }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )
    const [, claimInit] = fetchMock.mock.calls[3] ?? []
    await user.click(screen.getByRole("button", { name: "Sign out device" }))
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    expect(claimInit?.signal!.aborted).toBe(true)
    resolveClaim?.(
      new Response(
        JSON.stringify({
          error: {
            code: "FORBIDDEN",
            message: "Forbidden.",
            errors: {},
            request_id: "request-1",
          },
        }),
        { status: 403 },
      ),
    )
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(6))
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
  })

  it("keeps Student B's session intact when Student A's aborted claim settles late", async () => {
    let resolveClaim: ((response: Response) => void) | undefined
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-a-token", studentUser))
      .mockResolvedValueOnce(queue("pending_payment", { canClaim: true }))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveClaim = resolve
          }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(auth("student-b-token", secondStudentUser))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await user.click(
      await screen.findByRole("button", { name: "Claim queue number" }),
    )
    await user.click(screen.getByRole("button", { name: "Done" }))
    await screen.findByRole("heading", { name: "Student sign-in" })
    await user.type(
      screen.getByLabelText("Student email"),
      "student-two@grc.test",
    )
    await user.type(screen.getByLabelText("Student password"), "secret")
    await user.click(screen.getByRole("button", { name: "View queue" }))
    expect(
      await screen.findByText(
        "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
      ),
    ).toBeInTheDocument()
    resolveClaim?.(
      new Response(
        JSON.stringify({
          error: {
            code: "FORBIDDEN",
            message: "Forbidden.",
            errors: {},
            request_id: "request-1",
          },
        }),
        { status: 403 },
      ),
    )
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(7))
    expect(screen.getByText("Student: Student Two")).toBeInTheDocument()
    expect(JSON.stringify(fetchMock.mock.calls.slice(6))).not.toContain(
      "student-a-token",
    )
  })

  it("finishes Student state immediately while queue cancellation is still pending", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    await screen.findByText(
      "Registrar approval is required before a queue number can be issued. Once approved, claim your queuing ticket in person at the school Cashier kiosk.",
    )
    let settleCancel: (() => void) | undefined
    const cancel = vi.spyOn(queryClient, "cancelQueries").mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          settleCancel = resolve
        }),
    )

    fireEvent.click(screen.getByRole("button", { name: "Done" }))
    expect(
      screen.getByRole("heading", { name: "Student sign-in" }),
    ).toBeInTheDocument()
    expect(cancel).toHaveBeenCalledWith({ queryKey: ["student-queue"] })
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
    settleCancel?.()
  })

  it("clears device storage immediately while queue cancellation is still pending", async () => {
    fetchMock
      .mockResolvedValueOnce(auth("kiosk-token", kioskUser))
      .mockResolvedValueOnce(auth("student-token", studentUser))
      .mockResolvedValueOnce(queue("pending_registrar_approval"))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const user = userEvent.setup()
    const { queryClient } = renderPage()
    await screen.findByRole("heading", { name: "Queue Kiosk sign-in" })
    await signInDeviceAndStudent(user)
    let settleCancel: (() => void) | undefined
    vi.spyOn(queryClient, "cancelQueries").mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          settleCancel = resolve
        }),
    )

    fireEvent.click(screen.getByRole("button", { name: "Sign out device" }))
    expect(
      screen.getByRole("heading", { name: "Queue Kiosk sign-in" }),
    ).toBeInTheDocument()
    expect(localStorage.getItem(kioskTokenStorageKey)).toBeNull()
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["student-queue"] }),
    ).toHaveLength(0)
    settleCancel?.()
  })
})
