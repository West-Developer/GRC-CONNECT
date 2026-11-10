import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentRequestsWorkspace } from "@/features/components/portal/enrollment-requests-workspace"
import { renderWithSession } from "@/tests/render-app"

function urlOf(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const paginationLinks = {
  first: "https://api.test/x?page=1",
  last: "https://api.test/x?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const pendingWithdrawal = {
  type: "withdrawal_request",
  id: 12,
  enrollment_id: 9,
  student_number: "2026-0001",
  reason: "Relocating abroad.",
  status: "pending",
  status_label: "Pending",
  processed_at: null,
  created_at: "2026-08-04T00:00:00Z",
} as const

const pendingDrop = {
  type: "enrollment_change_request",
  id: 3,
  enrollment_id: 9,
  student_number: "2026-0002",
  request_type: "drop",
  request_type_label: "Drop subject",
  subject_code: "CS102",
  from_section_code: "A",
  to_section_code: null,
  reason: "Schedule conflict with work.",
  status: "pending",
  status_label: "Pending",
  decided_at: null,
  decision_reason: null,
  created_at: "2026-08-04T00:00:00Z",
} as const

const headSession = {
  userId: "5",
  displayName: "Registrar Head",
  role: "registrar_head",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const staffSession = {
  userId: "6",
  displayName: "Registrar Staff",
  role: "registrar_staff",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function page(rows: readonly unknown[]) {
  return new Response(
    JSON.stringify({
      data: rows,
      links: paginationLinks,
      meta: paginationMeta,
    }),
  )
}

describe("EnrollmentRequestsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("does not render for an unauthorized role", () => {
    fetchMock.mockResolvedValue(page([]))
    renderWithSession(<EnrollmentRequestsWorkspace />, {
      session: {
        userId: "1",
        displayName: "Student",
        role: "student",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("offers withdrawals, drops, added subjects and section changes as tabs", async () => {
    fetchMock.mockImplementation(() => Promise.resolve(page([])))
    renderWithSession(<EnrollmentRequestsWorkspace />, { session: headSession })

    const tabs = await screen.findByRole("tablist", { name: "Request type" })
    expect(
      within(tabs)
        .getAllByRole("tab")
        .map((tab) => tab.textContent),
    ).toEqual(["Withdrawals", "Drops", "Add subject", "Change section"])
  })

  it("lets the Registrar Head decide a withdrawal", async () => {
    const user = userEvent.setup()
    const bodies: unknown[] = []
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        bodies.push(
          JSON.parse(typeof init.body === "string" ? init.body : "{}"),
        )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...pendingWithdrawal,
                status: "approved",
                status_label: "Approved",
              },
            }),
          ),
        )
      }
      return Promise.resolve(page([pendingWithdrawal]))
    })
    renderWithSession(<EnrollmentRequestsWorkspace />, { session: headSession })

    const table = await screen.findByRole("table", {
      name: "Withdrawal requests",
    })
    await user.click(within(table).getByRole("button", { name: "Approve" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.click(
      within(dialog).getByRole("button", { name: "Confirm decision" }),
    )

    await waitFor(() => expect(bodies[0]).toMatchObject({ action: "approve" }))
  })

  it("lets Registrar Staff decide a withdrawal too", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(page([pendingWithdrawal])),
    )
    renderWithSession(<EnrollmentRequestsWorkspace />, {
      session: staffSession,
    })

    const table = await screen.findByRole("table", {
      name: "Withdrawal requests",
    })
    expect(
      within(table).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
    expect(
      within(table).getByRole("button", { name: "Reject" }),
    ).toBeInTheDocument()
  })

  it("asks the server for drops only, and lets the Head but not Staff decide them", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        urlOf(input).includes("enrollment-change-requests")
          ? page([pendingDrop])
          : page([]),
      ),
    )
    const { unmount } = renderWithSession(<EnrollmentRequestsWorkspace />, {
      session: staffSession,
    })

    await user.click(await screen.findByRole("tab", { name: "Drops" }))
    const staffTable = await screen.findByRole("table", {
      name: "Drop requests",
    })
    expect(
      within(staffTable).getByText("Schedule conflict with work."),
    ).toBeInTheDocument()
    expect(
      within(staffTable).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(
        ([input]) =>
          urlOf(input).includes("enrollment-change-requests") &&
          urlOf(input).includes("type=drop"),
      ),
    ).toBe(true)
    unmount()

    renderWithSession(<EnrollmentRequestsWorkspace />, { session: headSession })
    await user.click(await screen.findByRole("tab", { name: "Drops" }))
    const headTable = await screen.findByRole("table", {
      name: "Drop requests",
    })
    expect(
      within(headTable).getByRole("button", { name: "Approve" }),
    ).toBeInTheDocument()
  })
})
