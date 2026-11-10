import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyInvitationWorkspace } from "@/features/components/portal/faculty-invitation-workspace"
import { renderWithSession } from "@/tests/render-app"

const invitations = {
  data: [
    {
      type: "faculty_invitation",
      id: 1,
      email: "pending.professor@grc.test",
      name: "pending.professor",
      status: "pending",
      invitation_sent_at: "2026-08-20T00:00:00+08:00",
      activated_at: null,
    },
    {
      type: "faculty_invitation",
      id: 2,
      email: "active.professor@grc.test",
      name: "Prof. Active",
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
  return renderWithSession(<FacultyInvitationWorkspace />, {
    session: {
      userId: "chair-1",
      displayName: "Program Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-08-09T00:00:00Z",
    },
  })
}

describe("FacultyInvitationWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let postedBodies: unknown[] = []
  let inviteShouldFail = false

  beforeEach(() => {
    postedBodies = []
    inviteShouldFail = false
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.endsWith("/faculty-invitations") && init?.method === "POST") {
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
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "faculty_invitation",
                id: 3,
                email: (body as { email: string }).email,
                name: (body as { email: string }).email.split("@")[0],
                status: "pending",
                invitation_sent_at: "2026-08-27T00:00:00+08:00",
                activated_at: null,
              },
            }),
            { status: 201 },
          ),
        )
      }
      if (url.includes("/faculty-invitations/1/resend") && init?.method === "POST") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...invitations.data[0], invitation_sent_at: "2026-08-28T00:00:00+08:00" },
            }),
          ),
        )
      }
      if (url.endsWith("/faculty-invitations")) {
        return Promise.resolve(new Response(JSON.stringify(invitations)))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("lists invited professors with their status, and only offers Resend for non-activated accounts", async () => {
    renderWorkspace()

    const pendingRow = (await screen.findByText("pending.professor@grc.test")).closest("tr")
    expect(pendingRow).not.toBeNull()
    expect(within(pendingRow as HTMLElement).getByText("Pending")).toBeInTheDocument()
    expect(
      within(pendingRow as HTMLElement).getByRole("button", { name: /Resend/ }),
    ).toBeInTheDocument()

    const activeRow = screen.getByText("active.professor@grc.test").closest("tr")
    expect(activeRow).not.toBeNull()
    expect(within(activeRow as HTMLElement).getByText("Activated")).toBeInTheDocument()
    expect(
      within(activeRow as HTMLElement).queryByRole("button", { name: /Resend/ }),
    ).not.toBeInTheDocument()
  })

  it("queues one or more emails and sends them together", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.professor@grc.test")

    const emailInput = screen.getByLabelText("Professor's email")
    await user.type(emailInput, "first.new@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))
    await user.type(emailInput, "second.new@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))

    expect(screen.getByText("first.new@grc.test")).toBeInTheDocument()
    expect(screen.getByText("second.new@grc.test")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Send 2 invitations" }))

    await vi.waitFor(() => {
      expect(postedBodies).toEqual([
        { email: "first.new@grc.test" },
        { email: "second.new@grc.test" },
      ])
    })
    expect(screen.queryByText("first.new@grc.test")).not.toBeInTheDocument()
  })

  it("removes a queued email before sending", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.professor@grc.test")

    await user.type(screen.getByLabelText("Professor's email"), "remove.me@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))
    expect(screen.getByText("remove.me@grc.test")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Remove remove.me@grc.test" }))
    expect(screen.queryByText("remove.me@grc.test")).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Send 0 invitations" })).toBeDisabled()
  })

  it("shows a per-email error when an invitation fails, without losing the working ones", async () => {
    inviteShouldFail = true
    const user = userEvent.setup()
    renderWorkspace()
    await screen.findByText("pending.professor@grc.test")

    await user.type(screen.getByLabelText("Professor's email"), "duplicate@grc.test")
    await user.click(screen.getByRole("button", { name: "Add" }))
    await user.click(screen.getByRole("button", { name: "Send 1 invitation" }))

    expect(
      await screen.findByText("duplicate@grc.test: The email has already been taken."),
    ).toBeInTheDocument()
  })

  it("resends a pending invitation", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const pendingRow = (await screen.findByText("pending.professor@grc.test")).closest("tr")
    await user.click(within(pendingRow as HTMLElement).getByRole("button", { name: /Resend/ }))

    await vi.waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            requestUrl(input).includes("/faculty-invitations/1/resend") && init?.method === "POST",
        ),
      ).toBe(true)
    })
  })

  it("is unauthorized for a role other than Program Chair", () => {
    renderWithSession(<FacultyInvitationWorkspace />, {
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
    expect(screen.queryByLabelText("Professor's email")).not.toBeInTheDocument()
  })
})
