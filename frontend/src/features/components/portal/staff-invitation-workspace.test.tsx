import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { StaffInvitationWorkspace } from "@/features/components/portal/staff-invitation-workspace"
import { renderWithSession } from "@/tests/render-app"

const invitations = {
  data: [
    {
      type: "staff_invitation",
      id: 1,
      email: "pending.dean@grc.test",
      name: "pending.dean",
      role: "dean",
      role_label: "Dean",
      status: "pending",
      invitation_sent_at: "2026-08-20T00:00:00+08:00",
      activated_at: null,
    },
    {
      type: "staff_invitation",
      id: 2,
      email: "active.itadmin@grc.test",
      name: "Active IT",
      role: "it_admin",
      role_label: "IT Control",
      status: "activated",
      invitation_sent_at: "2026-08-10T00:00:00+08:00",
      activated_at: "2026-08-11T00:00:00+08:00",
    },
  ],
} as const

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function renderWorkspace() {
  return renderWithSession(<StaffInvitationWorkspace />, {
    session: {
      userId: "registrar-1",
      displayName: "Registrar Head",
      role: "registrar_head",
      signedInAt: "2026-08-09T00:00:00Z",
    },
  })
}

describe("StaffInvitationWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let postedBodies: unknown[] = []
  let inviteShouldFail = false

  beforeEach(() => {
    postedBodies = []
    inviteShouldFail = false
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.endsWith("/staff-invitations") && init?.method === "POST") {
        const body: unknown = typeof init.body === "string" ? JSON.parse(init.body) : null
        postedBodies.push(body)
        if (inviteShouldFail) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "The given data was invalid.",
                  errors: { email: ["The email has already been taken."] },
                  request_id: "invite-test-request",
                },
              }),
              { status: 422 },
            ),
          )
        }
        const { email, role } = body as { email: string; role: string }
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "staff_invitation",
                id: 3,
                email,
                name: email.split("@")[0],
                role,
                role_label: role,
                status: "pending",
                invitation_sent_at: "2026-08-27T00:00:00+08:00",
                activated_at: null,
              },
            }),
            { status: 201 },
          ),
        )
      }
      if (url.includes("/staff-invitations/1/resend") && init?.method === "POST") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...invitations.data[0], invitation_sent_at: "2026-08-28T00:00:00+08:00" },
            }),
          ),
        )
      }
      if (url.endsWith("/staff-invitations")) {
        return Promise.resolve(new Response(JSON.stringify(invitations)))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("lists invited staff with their role and status, and only offers Resend for non-activated accounts", async () => {
    renderWorkspace()

    const pendingRow = (await screen.findByText("pending.dean@grc.test")).closest("tr")
    expect(pendingRow).not.toBeNull()
    expect(within(pendingRow as HTMLElement).getByText("Dean")).toBeInTheDocument()
    expect(within(pendingRow as HTMLElement).getByText("Pending")).toBeInTheDocument()
    expect(
      within(pendingRow as HTMLElement).getByRole("button", { name: /Resend/ }),
    ).toBeInTheDocument()

    const activeRow = screen.getByText("active.itadmin@grc.test").closest("tr")
    expect(activeRow).not.toBeNull()
    expect(within(activeRow as HTMLElement).getByText("IT Control")).toBeInTheDocument()
    expect(within(activeRow as HTMLElement).getByText("Activated")).toBeInTheDocument()
    expect(
      within(activeRow as HTMLElement).queryByRole("button", { name: /Resend/ }),
    ).not.toBeInTheDocument()
  })

  it("queues one or more emails with a role each and sends them together", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.dean@grc.test")

    const emailInput = screen.getByLabelText("Email")
    await user.type(emailInput, "first.new@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))

    await user.click(screen.getByLabelText("Role"))
    await user.click(await screen.findByRole("option", { name: "IT Control" }))
    await user.type(emailInput, "second.new@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))

    expect(screen.getByText("first.new@grc.test (Professor / Faculty)")).toBeInTheDocument()
    expect(screen.getByText("second.new@grc.test (IT Control)")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Send 2 invitations" }))

    await vi.waitFor(() => {
      expect(postedBodies).toEqual([
        { email: "first.new@grc.test", role: "faculty" },
        { email: "second.new@grc.test", role: "it_admin" },
      ])
    })
    expect(screen.queryByText("first.new@grc.test (Professor / Faculty)")).not.toBeInTheDocument()
  })

  it("removes a queued invite before sending", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.dean@grc.test")

    await user.type(screen.getByLabelText("Email"), "remove.me@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))
    expect(screen.getByText("remove.me@grc.test (Professor / Faculty)")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Remove remove.me@grc.test" }))
    expect(screen.queryByText("remove.me@grc.test (Professor / Faculty)")).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Send 0 invitations" })).toBeDisabled()
  })

  it("shows a per-email error when an invitation fails, without losing the working ones", async () => {
    inviteShouldFail = true
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.dean@grc.test")

    await user.type(screen.getByLabelText("Email"), "duplicate@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))
    await user.click(screen.getByRole("button", { name: "Send 1 invitation" }))

    expect(
      await screen.findByText("duplicate@grc.test: The email has already been taken."),
    ).toBeInTheDocument()
  })

  it("resends a pending invitation", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const pendingRow = (await screen.findByText("pending.dean@grc.test")).closest("tr")
    await user.click(within(pendingRow as HTMLElement).getByRole("button", { name: /Resend/ }))

    await vi.waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            requestUrl(input).includes("/staff-invitations/1/resend") && init?.method === "POST",
        ),
      ).toBe(true)
    })
  })

  it("is unauthorized for a role other than Registrar Head", () => {
    renderWithSession(<StaffInvitationWorkspace />, {
      session: {
        userId: "dean-1",
        displayName: "Dean",
        role: "dean",
        signedInAt: "2026-08-09T00:00:00Z",
      },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText("Email")).not.toBeInTheDocument()
  })
})
