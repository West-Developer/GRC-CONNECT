import { act, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import type { AuthSession } from "@/features/auth/auth-types"
import { useAuth } from "@/features/auth/use-auth"
import { PortalNotificationSheet } from "@/features/components/portal/portal-notification-sheet"
import { routerMock } from "@/tests/navigation-mock"
import {
  createStubGateway,
  renderWithAuthProvider,
  renderWithSession,
  testSession,
} from "@/tests/render-app"

const notificationEnvelope = {
  data: [
    {
      type: "notification",
      id: 7,
      notification_type: "schedule_published",
      message: "Schedule published for your review.",
      read_at: null,
      created_at: "2026-07-29T10:00:00Z",
    },
  ],
  links: {
    first: "http://127.0.0.1:8000/api/v1/notifications?page=1",
    last: "http://127.0.0.1:8000/api/v1/notifications?page=2",
    prev: null,
    next: "http://127.0.0.1:8000/api/v1/notifications?page=2",
  },
  meta: { current_page: 1, last_page: 2, per_page: 20, total: 21 },
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") {
    return input
  }

  return input instanceof URL ? input.toString() : input.url
}

const userASession: AuthSession = {
  userId: "1",
  displayName: "User A",
  role: "faculty",
  signedInAt: "2026-07-29T10:00:00Z",
}

const userBSession: AuthSession = {
  userId: "2",
  displayName: "User B",
  role: "faculty",
  signedInAt: "2026-07-29T10:01:00Z",
}

function AuthSwitchControls() {
  const { signIn, signOut } = useAuth()

  return (
    <>
      <button type="button" onClick={signOut}>
        Sign out
      </button>
      <button
        type="button"
        onClick={() =>
          void signIn({ email: "user-b@grc.test", password: "secret" })
        }
      >
        Sign in as User B
      </button>
    </>
  )
}

describe("PortalNotificationSheet", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it("shows unread count, supports an unread filter, pagination, and marking a notification read", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      const request = init

      if (request?.method === "PATCH") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...notificationEnvelope.data[0],
                read_at: "2026-07-29T10:03:00Z",
              },
            }),
            { status: 200 },
          ),
        )
      }

      expect(url).toContain("/notifications")
      return Promise.resolve(
        new Response(JSON.stringify(notificationEnvelope), { status: 200 }),
      )
    })

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))

    expect(
      await screen.findByRole("dialog", { name: "Notifications" }),
    ).toBeInTheDocument()
    // The unread badge now reads the true total (`meta.total`) from a
    // dedicated unread-count query, not a count of the current page's
    // items, so it reflects the fixture's `meta.total` of 21.
    expect(await screen.findByText("21 unread")).toBeInTheDocument()
    expect(
      screen.getByText("Schedule published for your review."),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Unread only" }))
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("unread_only=true"),
      expect.objectContaining({ method: "GET" }),
    )

    await user.click(
      screen.getByRole("button", { name: "Next notifications page" }),
    )
    expect(fetchMock).toHaveBeenLastCalledWith(
      expect.stringContaining("page=2"),
      expect.objectContaining({ method: "GET" }),
    )

    await user.click(
      screen.getByRole("button", { name: "Mark notification as read" }),
    )
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/notifications/7/read"),
      expect.objectContaining({ method: "PATCH" }),
    )
  })

  it("renders a safe error without assuming an unread notification belongs to the user", async () => {
    const user = userEvent.setup()
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: "FORBIDDEN",
            message: "You are not authorized to perform this action.",
            errors: {},
            request_id: "request-7",
          },
        }),
        { status: 403 },
      ),
    )

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))

    expect(
      await screen.findByText("Notifications are unavailable right now."),
    ).toBeInTheDocument()
    expect(screen.queryByText(/user_id/i)).not.toBeInTheDocument()
  })

  it("does not show User A's cached notifications after User B signs in", async () => {
    const user = userEvent.setup()
    const userANotifications = {
      ...notificationEnvelope,
      data: [
        {
          ...notificationEnvelope.data[0],
          message: "User A private notification",
        },
      ],
    }
    const userBNotifications = {
      ...notificationEnvelope,
      data: [
        {
          ...notificationEnvelope.data[0],
          id: 8,
          message: "User B private notification",
        },
      ],
    }
    // A stateful implementation rather than a fixed `mockResolvedValueOnce`
    // queue: the sheet now issues two requests per session (the list and the
    // dedicated unread-count query), and their exact call order/count is an
    // implementation detail this test should not need to track.
    let activeUser: "A" | "B" = "A"
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            activeUser === "A" ? userANotifications : userBNotifications,
          ),
          { status: 200 },
        ),
      ),
    )

    renderWithAuthProvider(
      <>
        <AuthSwitchControls />
        <PortalNotificationSheet />
      </>,
      {
        gateway: createStubGateway({
          restore: () => Promise.resolve(userASession),
          signIn: () => Promise.resolve(userBSession),
        }),
      },
    )

    await user.click(screen.getByRole("button", { name: /notifications/i }))
    expect(
      await screen.findByText("User A private notification"),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Close" }))
    await user.click(screen.getByRole("button", { name: "Sign out" }))
    activeUser = "B"
    await user.click(screen.getByRole("button", { name: "Sign in as User B" }))
    await user.click(screen.getByRole("button", { name: /notifications/i }))

    expect(
      await screen.findByText("User B private notification"),
    ).toBeInTheDocument()
    expect(
      screen.queryByText("User A private notification"),
    ).not.toBeInTheDocument()
  })

  it("shows a true unread total on the trigger, not just the current page", async () => {
    // A `Response` body can only be read once; the list query and the
    // dedicated unread-count query each read their own, so each call needs
    // a fresh instance rather than one shared `mockResolvedValue`.
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(notificationEnvelope), { status: 200 }),
      ),
    )

    renderWithSession(<PortalNotificationSheet />)

    expect(
      await screen.findByRole("button", { name: "Notifications, 21 unread" }),
    ).toBeInTheDocument()
    expect(await screen.findByText("9+")).toBeInTheDocument()
  })

  it("refreshes notifications while the sheet is open without a page reload", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    let message = "Earlier schedule update"
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [{ ...notificationEnvelope.data[0], message }],
          }),
        ),
      ),
    )

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    expect(
      await screen.findByText("Earlier schedule update"),
    ).toBeInTheDocument()

    message = "New Dean review request"
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000)
    })

    expect(
      await screen.findByText("New Dean review request"),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("dialog", { name: "Notifications" }),
    ).toBeInTheDocument()
  })

  it("keeps unread notifications unchanged when the sheet is closed", async () => {
    const user = userEvent.setup()
    const patchedIds: number[] = []
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)

      if (init?.method === "PATCH") {
        const id = Number(url.split("/").at(-2))
        patchedIds.push(id)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...notificationEnvelope.data[0],
                id,
                read_at: "2026-07-29T10:03:00Z",
              },
            }),
            { status: 200 },
          ),
        )
      }

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              { ...notificationEnvelope.data[0], id: 7 },
              {
                ...notificationEnvelope.data[0],
                id: 8,
                notification_type: "enrollment_submitted",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await screen.findByRole("dialog", { name: "Notifications" })

    await user.click(screen.getByRole("button", { name: "Close" }))

    expect(patchedIds).toEqual([])
  })

  it("does not attempt to mark anything read when the sheet is closed with zero unread", async () => {
    const user = userEvent.setup()
    let patchCalled = false
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        patchCalled = true
        return Promise.resolve(
          new Response(JSON.stringify({ data: notificationEnvelope.data[0] }), {
            status: 200,
          }),
        )
      }

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            meta: { ...notificationEnvelope.meta, total: 0 },
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await screen.findByRole("dialog", { name: "Notifications" })

    await user.click(screen.getByRole("button", { name: "Close" }))

    expect(patchCalled).toBe(false)
  })

  it("marks every unread notification read in one action", async () => {
    const user = userEvent.setup()
    let bulkReadCalled = false
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)

      if (init?.method === "PATCH" && url.includes("/notifications/read-all")) {
        bulkReadCalled = true
        return Promise.resolve(
          new Response(
            JSON.stringify({
              message: "All 2 notifications marked as read.",
              marked_count: 2,
            }),
            { status: 200 },
          ),
        )
      }

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              { ...notificationEnvelope.data[0], id: 7 },
              {
                ...notificationEnvelope.data[0],
                id: 8,
                notification_type: "enrollment_submitted",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />)
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", { name: "Mark all as read" }),
    )

    expect(bulkReadCalled).toBe(true)
  })

  it("navigates to the relevant module and closes the sheet when a routable notification is clicked", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...notificationEnvelope.data[0],
                read_at: "2026-07-29T10:03:00Z",
              },
            }),
            { status: 200 },
          ),
        )

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              {
                ...notificationEnvelope.data[0],
                notification_type: "schedule_returned",
                message: "CCS's schedule was returned.",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />, {
      session: { ...testSession, role: "program_chair" },
    })
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", {
        name: /CCS's schedule was returned/,
      }),
    )

    expect(routerMock.push).toHaveBeenCalledWith(
      "/portal/program-chair-enrollment",
    )
    expect(
      screen.queryByRole("dialog", { name: "Notifications" }),
    ).not.toBeInTheDocument()
  })

  it("opens the Certificate of Registration (COR) dialog when a student clicks a payment confirmed notification", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/enrollment-documents/1")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "enrollment_document",
                id: 1,
                enrollment_id: 10,
                student_id: 1,
                document_type: "certificate_of_registration",
                document_type_label: "Certificate of Registration",
                document_number: "COR-2026-0001",
                generated_at: "2026-07-29T10:00:00Z",
                status: "issued",
                status_label: "Issued",
                snapshot: {
                  student: {
                    name: "Maria Santos",
                    student_number: "2024-0001",
                    program: "BSIT",
                    year_level: 1,
                  },
                  enrollment: {
                    academic_term: "2026-2027 · 1st",
                    total_units: 18,
                  },
                  subjects: [],
                  fees: {
                    tuition_fee: "15000.00",
                    other_fees: [],
                    total_assessment: "15000.00",
                    amount_paid: "15000.00",
                    balance: "0.00",
                  },
                },
              },
            }),
            { status: 200 },
          ),
        )
      }
      if (url.includes("/enrollment-documents")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "enrollment_document",
                  id: 1,
                  enrollment_id: 10,
                  student_id: 1,
                  document_type: "certificate_of_registration",
                  document_type_label: "Certificate of Registration",
                  document_number: "COR-2026-0001",
                  generated_at: "2026-07-29T10:00:00Z",
                  status: "issued",
                  status_label: "Issued",
                },
              ],
              meta: { current_page: 1, last_page: 1, total: 1 },
            }),
            { status: 200 },
          ),
        )
      }

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              {
                ...notificationEnvelope.data[0],
                notification_type: "enrollment_payment_confirmed",
                message: "Your payment has been confirmed. Your Certificate of Registration (COR) is ready.",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />, {
      session: { ...testSession, role: "student" },
    })
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", {
        name: /Your payment has been confirmed/,
      }),
    )

    expect(
      await screen.findByRole("dialog", {
        name: /Certificate of Registration \(COR\)/i,
      }),
    ).toBeInTheDocument()
  })

  it("drops the unread badge as soon as a notification is clicked, without waiting for the server", async () => {
    const user = userEvent.setup()
    // The PATCH is held open so the badge can only have changed optimistically.
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return new Promise<Response>(() => undefined)

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              {
                ...notificationEnvelope.data[0],
                notification_type: "schedule_returned",
                message: "CCS's schedule was returned.",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />, {
      session: { ...testSession, role: "program_chair" },
    })
    expect(
      await screen.findByRole("button", { name: "Notifications, 21 unread" }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", {
        name: /CCS's schedule was returned/,
      }),
    )

    expect(
      await screen.findByRole("button", { name: "Notifications, 20 unread" }),
    ).toBeInTheDocument()
  })

  it("marks a notification with no destination for the role read when its card is clicked", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...notificationEnvelope.data[0],
                read_at: "2026-07-29T10:03:00Z",
              },
            }),
            { status: 200 },
          ),
        )

      return Promise.resolve(
        new Response(JSON.stringify(notificationEnvelope), { status: 200 }),
      )
    })

    // `schedule_published` has no destination for a student.
    renderWithSession(<PortalNotificationSheet />, {
      session: { ...testSession, role: "student" },
    })
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", {
        name: /Schedule published for your review/,
      }),
    )

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/notifications/7/read"),
      expect.objectContaining({ method: "PATCH" }),
    )
    expect(routerMock.push).not.toHaveBeenCalled()
  })

  it("opens Irregular Advising when a Program Chair clicks an irregular student's submission alert", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...notificationEnvelope.data[0],
                read_at: "2026-07-29T10:03:00Z",
              },
            }),
            { status: 200 },
          ),
        )

      return Promise.resolve(
        new Response(
          JSON.stringify({
            ...notificationEnvelope,
            data: [
              {
                ...notificationEnvelope.data[0],
                notification_type: "enrollment_submitted",
                message:
                  "Irregular student 2026-06-01722 submitted an enrollment for 2027-2028 1st and is awaiting Program Head schedule checking.",
              },
            ],
          }),
          { status: 200 },
        ),
      )
    })

    renderWithSession(<PortalNotificationSheet />, {
      session: { ...testSession, role: "program_chair" },
    })
    await user.click(screen.getByRole("button", { name: /notifications/i }))
    await user.click(
      await screen.findByRole("button", {
        name: /Irregular student 2026-06-01722 submitted an enrollment/,
      }),
    )

    expect(routerMock.push).toHaveBeenCalledWith(
      "/portal/irregular-enrollments",
    )
  })

  it.each([
    ["program_chair", "transferee_credit_requested", "Student 2026-0002 asked for a credit mapping: Intro to Computing from TIP.", "/portal/credit-mappings"],
    ["registrar_head", "transferee_credit_endorsed", "A transferee credit for student 2026-0002 (Intro to Computing) was endorsed by the Program Head and awaits your approval.", "/portal/credit-mappings"],
    ["program_chair", "transferee_credit_returned", "The Registrar Head returned a credit for student 2026-0002 (Intro to Computing). Reason: Mapped to the wrong subject.", "/portal/credit-mappings"],
    ["student", "transferee_credit_approved", "Your transferee credit has been approved.", "/portal/grades"],
  ] as const)(
    "sends a %s who opens a %s notification to the module that handles it",
    async (role, notificationType, message, destination) => {
      const user = userEvent.setup()
      fetchMock.mockImplementation((_input, init) => {
        if (init?.method === "PATCH")
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: { ...notificationEnvelope.data[0], read_at: "2026-07-29T10:03:00Z" },
              }),
              { status: 200 },
            ),
          )

        return Promise.resolve(
          new Response(
            JSON.stringify({
              ...notificationEnvelope,
              data: [
                { ...notificationEnvelope.data[0], notification_type: notificationType, message },
              ],
            }),
            { status: 200 },
          ),
        )
      })

      renderWithSession(<PortalNotificationSheet />, {
        session: { ...testSession, role },
      })
      await user.click(screen.getByRole("button", { name: /notifications/i }))
      await user.click(
        await screen.findByRole("button", { name: new RegExp(message.slice(0, 30)) }),
      )

      expect(routerMock.push).toHaveBeenCalledWith(destination)
    },
  )
})
