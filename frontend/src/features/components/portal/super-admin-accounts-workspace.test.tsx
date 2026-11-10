import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { SuperAdminAccountsWorkspace } from "@/features/components/portal/super-admin-accounts-workspace"
import { renderWithSession } from "@/tests/render-app"

const mockUsers = {
  data: [
    {
      type: "user_account",
      id: 1,
      name: "Super Administrator",
      email: "admin@grc.edu.ph",
      role: "super_admin",
      role_label: "Super Admin",
      college: null,
      college_label: null,
      status: "active",
      status_label: "Active",
      pending_setup: false,
      account_setup_completed_at: "2026-08-01T00:00:00+08:00",
      last_login_at: "2026-10-01T00:00:00+08:00",
      created_at: "2026-08-01T00:00:00+08:00",
      manageable: false,
      active_session_count: 1,
    },
    {
      type: "user_account",
      id: 2,
      name: "Dr. Faculty",
      email: "faculty@grc.edu.ph",
      role: "faculty",
      role_label: "Professor / Faculty",
      college: "ccs",
      college_label: "CCS",
      status: "active",
      status_label: "Active",
      pending_setup: false,
      account_setup_completed_at: "2026-08-10T00:00:00+08:00",
      last_login_at: null,
      created_at: "2026-08-10T00:00:00+08:00",
      manageable: true,
      active_session_count: 2,
    },
    {
      type: "user_account",
      id: 3,
      name: "Pending Staff",
      email: "pending@grc.edu.ph",
      role: "registrar_staff",
      role_label: "Registrar Staff",
      college: null,
      college_label: null,
      status: "disabled",
      status_label: "Disabled",
      pending_setup: true,
      account_setup_completed_at: null,
      last_login_at: null,
      created_at: "2026-09-01T00:00:00+08:00",
      manageable: true,
      active_session_count: 0,
    },
  ],
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    per_page: 20,
    to: 3,
    total: 3,
  },
  links: {
    first: null,
    last: null,
    prev: null,
    next: null,
  },
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace(role = "super_admin") {
  return renderWithSession(<SuperAdminAccountsWorkspace />, {
    session: {
      userId: "admin-1",
      displayName: "Super Admin",
      role: role as any,
      signedInAt: "2026-10-01T00:00:00Z",
    },
  })
}

describe("SuperAdminAccountsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const recordedRequests: { url: string; method: string; body: unknown }[] = []

  beforeEach(() => {
    recordedRequests.length = 0
    vi.stubGlobal("fetch", fetchMock)

    fetchMock.mockImplementation(async (input, init) => {
      const url = requestUrl(input)
      const method = init?.method ?? "GET"
      let body: unknown = null

      if (init?.body && typeof init.body === "string") {
        try {
          body = JSON.parse(init.body)
        } catch {
          body = init.body
        }
      }

      recordedRequests.push({ url, method, body })

      if (url.includes("/api/v1/super-admin/users/invite")) {
        return new Response(
          JSON.stringify({
            data: {
              type: "user_account",
              id: 4,
              name: "New Staff",
              email: "newstaff@grc.edu.ph",
              role: "admission_staff",
              role_label: "Admission Staff",
              college: null,
              college_label: null,
              status: "disabled",
              status_label: "Disabled",
              pending_setup: true,
              account_setup_completed_at: null,
              last_login_at: null,
              created_at: "2026-10-01T00:00:00+08:00",
              manageable: true,
              active_session_count: 0,
            },
          }),
          { status: 201 },
        )
      }

      if (url.includes("/role")) {
        return new Response(
          JSON.stringify({
            data: {
              ...mockUsers.data[1],
              role: "dean",
              role_label: "Dean",
            },
          }),
          { status: 200 },
        )
      }

      if (url.includes("/status")) {
        return new Response(
          JSON.stringify({
            data: {
              ...mockUsers.data[1],
              status: "disabled",
              status_label: "Disabled",
            },
          }),
          { status: 200 },
        )
      }

      if (url.includes("/setup-invitation")) {
        return new Response(
          JSON.stringify({
            data: mockUsers.data[2],
          }),
          { status: 200 },
        )
      }

      if (url.includes("/password-reset")) {
        return new Response(
          JSON.stringify({
            data: {
              user_id: 2,
              status: "sent",
            },
          }),
          { status: 200 },
        )
      }

      if (url.includes("/sessions") && init?.method === "DELETE") {
        return new Response(
          JSON.stringify({
            data: {
              user_id: 2,
              sessions_revoked: true,
            },
          }),
          { status: 200 },
        )
      }

      if (url.includes("/api/v1/super-admin/users/") && init?.method === "DELETE") {
        return new Response(
          JSON.stringify({
            data: {
              user_id: 3,
              deleted: true,
            },
          }),
          { status: 200 },
        )
      }

      if (url.includes("/api/v1/super-admin/users")) {
        return new Response(JSON.stringify(mockUsers), { status: 200 })
      }

      return new Response(JSON.stringify({ data: [] }), { status: 200 })
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("renders the accounts workspace and users list", async () => {
    const { container } = renderWorkspace()

    expect(
      screen.getByRole("heading", { name: "Accounts & Access" }),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(screen.getByText("Super Administrator")).toBeInTheDocument()
      expect(screen.getByText("Dr. Faculty")).toBeInTheDocument()
      expect(screen.getByText("Pending Staff")).toBeInTheDocument()
    })

    expect(screen.getByText("System Protected")).toBeInTheDocument()
    expect(screen.getByText("Pending Setup")).toBeInTheDocument()

    const results = await axe(container)
    expect(results).toHaveNoViolations()
  })

  it("shows unauthorized when not super admin", () => {
    renderWorkspace("dean")
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("allows inviting a staff account", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await waitFor(() => {
      expect(screen.getByText("Dr. Faculty")).toBeInTheDocument()
    })

    const inviteBtn = screen.getByRole("button", {
      name: /Invite Staff Account/i,
    })
    await user.click(inviteBtn)

    expect(
      screen.getByRole("heading", { name: "Invite Staff Account" }),
    ).toBeInTheDocument()

    const emailInput = screen.getByLabelText(/Institutional Email/i)
    await user.type(emailInput, "newstaff@grc.edu.ph")

    const submitBtn = screen.getByRole("button", {
      name: /Send Invitation/i,
    })
    await user.click(submitBtn)

    await waitFor(() => {
      expect(recordedRequests).toContainEqual(
        expect.objectContaining({
          method: "POST",
          url: expect.stringContaining("/invite"),
          body: expect.objectContaining({
            email: "newstaff@grc.edu.ph",
          }),
        }),
      )
    })
  })

  it("allows changing user role", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await waitFor(() => {
      expect(screen.getByText("Dr. Faculty")).toBeInTheDocument()
    })

    const changeRoleBtn = screen.getAllByTitle("Change Role")[0]
    await user.click(changeRoleBtn)

    expect(
      screen.getByRole("heading", { name: "Change User Role" }),
    ).toBeInTheDocument()

    const reasonInput = screen.getByLabelText(/Reason for Change/i)
    await user.type(reasonInput, "Promotion to Dean")

    const saveBtn = screen.getByRole("button", { name: /Save Role/i })
    await user.click(saveBtn)

    await waitFor(() => {
      expect(recordedRequests).toContainEqual(
        expect.objectContaining({
          method: "PATCH",
          url: expect.stringContaining("/role"),
          body: expect.objectContaining({
            reason: "Promotion to Dean",
          }),
        }),
      )
    })
  })

  it("allows deleting an unused account after typing email", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await waitFor(() => {
      expect(screen.getByText("Pending Staff")).toBeInTheDocument()
    })

    const deleteBtn = screen.getByTitle("Delete Unused Account")
    await user.click(deleteBtn)

    expect(
      screen.getByRole("heading", { name: "Permanently Delete Account" }),
    ).toBeInTheDocument()

    const confirmDeleteBtn = screen.getByRole("button", {
      name: "Permanently Delete",
    })
    expect(confirmDeleteBtn).toBeDisabled()

    const emailInput = screen.getByPlaceholderText("pending@grc.edu.ph")
    await user.type(emailInput, "pending@grc.edu.ph")

    expect(confirmDeleteBtn).not.toBeDisabled()
    await user.click(confirmDeleteBtn)

    await waitFor(() => {
      expect(recordedRequests).toContainEqual(
        expect.objectContaining({
          method: "DELETE",
          url: expect.stringContaining("/api/v1/super-admin/users/3"),
        }),
      )
    })
  })
})
