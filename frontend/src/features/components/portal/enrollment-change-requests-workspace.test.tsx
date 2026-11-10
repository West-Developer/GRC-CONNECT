import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentChangeRequestsWorkspace } from "@/features/components/portal/enrollment-change-requests-workspace"
import { renderWithSession } from "@/tests/render-app"

const paginationLinks = {
  first: "https://api.test/enrollment-change-requests?page=1",
  last: "https://api.test/enrollment-change-requests?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const pendingRequest = {
  type: "enrollment_change_request",
  id: 3,
  enrollment_id: 9,
  student_number: "2026-0001",
  request_type: "add",
  request_type_label: "Add subject",
  subject_code: "CS102",
  from_section_code: null,
  to_section_code: "B",
  reason: "Need this subject to graduate on time.",
  status: "pending",
  status_label: "Pending",
  decided_at: null,
  decision_reason: null,
  created_at: "2026-08-04T00:00:00Z",
} as const

const registrarHeadSession = {
  userId: "5",
  displayName: "Registrar Head",
  role: "registrar_head",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

const registrarStaffSession = {
  userId: "6",
  displayName: "Registrar Staff",
  role: "registrar_staff",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

describe("EnrollmentChangeRequestsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("does not render for an unauthorized role", () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })))
    renderWithSession(<EnrollmentChangeRequestsWorkspace />, {
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

  it("lets Registrar Staff view every request without decision actions", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingRequest],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(<EnrollmentChangeRequestsWorkspace />, {
      session: registrarStaffSession,
    })

    const table = await screen.findByRole("table", {
      name: "Add/drop requests",
    })
    expect(within(table).getByText("CS102")).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
  })

  it("lets Registrar Head approve a pending request", async () => {
    const user = userEvent.setup()
    let patchBody: unknown = null
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH") {
        patchBody = init.body ? JSON.parse(init.body as string) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: { ...pendingRequest, status: "approved" } }),
          ),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingRequest],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(<EnrollmentChangeRequestsWorkspace />, {
      session: registrarHeadSession,
    })

    const table = await screen.findByRole("table", {
      name: "Add/drop requests",
    })
    await user.click(within(table).getByRole("button", { name: "Approve" }))

    const dialog = await screen.findByRole("alertdialog")
    await user.click(within(dialog).getByRole("button", { name: "Confirm decision" }))

    await vi.waitFor(() => expect(patchBody).toEqual({ action: "approve" }))
  })

  it("requires a reason before confirming a rejection", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((_input, init) => {
      if (init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: { ...pendingRequest, status: "rejected" } }),
          ),
        )
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [pendingRequest],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      )
    })
    renderWithSession(<EnrollmentChangeRequestsWorkspace />, {
      session: registrarHeadSession,
    })

    const table = await screen.findByRole("table", {
      name: "Add/drop requests",
    })
    await user.click(within(table).getByRole("button", { name: "Reject" }))
    expect(
      screen.getByRole("button", { name: "Confirm decision" }),
    ).toBeDisabled()
    await user.type(screen.getByLabelText("Reason"), "Would exceed unit cap.")
    await user.click(screen.getByRole("button", { name: "Confirm decision" }))

    await vi.waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/enrollment-change-requests/3"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({
            action: "reject",
            reason: "Would exceed unit cap.",
          }),
        }),
      ),
    )
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [pendingRequest],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    const { container } = renderWithSession(<EnrollmentChangeRequestsWorkspace />, {
      session: registrarHeadSession,
    })

    await screen.findByRole("table", { name: "Add/drop requests" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
