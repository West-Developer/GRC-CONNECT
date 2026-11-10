import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { SectionChangeRequestsPanel } from "@/features/components/portal/section-change-requests-panel"
import { SectionChangeRequestsWorkspace } from "@/features/components/portal/section-change-requests-workspace"
import { renderWithSession } from "@/tests/render-app"

function changeRequest(overrides: Record<string, unknown> = {}) {
  return {
    type: "section_change_request",
    id: 5,
    section_id: 11,
    section_code: "A",
    subject_code: "IT101",
    subject_title: "Introduction to Computing",
    academic_term_id: 1,
    status: "pending",
    status_label: "Pending",
    reason: "The room is being repaired.",
    requested_by_name: "Casey Program Head",
    changes: [
      { field: "room", label: "Room", old: "LAB 1", new: "LAB 9" },
      {
        field: "starts_at_time",
        label: "Start time",
        old: "08:00:00",
        new: "09:00:00",
      },
    ],
    decided_by_name: null,
    decided_at: null,
    decision_reason: null,
    created_at: "2026-09-26T00:00:00Z",
    ...overrides,
  }
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const registrarHead = {
  userId: "rh-1",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-09-26T00:00:00Z",
}

const programHead = {
  userId: "ph-1",
  displayName: "Program Head",
  role: "program_chair" as const,
  college: "ccs" as const,
  signedInAt: "2026-09-26T00:00:00Z",
}

describe("SectionChangeRequestsPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let decisionBody: Record<string, unknown> | null = null
  let rows: unknown[] = []
  let decisionStatus = 200

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    decisionBody = null
    rows = [changeRequest()]
    decisionStatus = 200
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (
        url.includes("/section-change-requests/5") &&
        init?.method === "PATCH"
      ) {
        decisionBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
        if (decisionStatus !== 200) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "The submitted data is invalid.",
                  errors: {
                    section: [
                      "This section changed after the request was made. Reject it and ask the Program Head to send a new one.",
                    ],
                  },
                  request_id: "test-stale",
                },
              }),
              { status: decisionStatus },
            ),
          )
        }
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: changeRequest({
                status:
                  decisionBody?.action === "approve" ? "approved" : "rejected",
              }),
            }),
          ),
        )
      }
      if (url.includes("/section-change-requests")) {
        return Promise.resolve(new Response(JSON.stringify({ data: rows })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  it("shows what the section has now next to what is being asked for", async () => {
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    const changes = await screen.findByRole("list", {
      name: "Changes requested for IT101",
    })
    expect(within(changes).getByText("LAB 1")).toBeInTheDocument()
    expect(within(changes).getByText("LAB 9")).toBeInTheDocument()
    expect(within(changes).getByText("08:00")).toBeInTheDocument()
    expect(within(changes).getByText("09:00")).toBeInTheDocument()
    expect(
      screen.getByText("Casey Program Head", { exact: false }),
    ).toBeInTheDocument()
    expect(screen.getByText("The room is being repaired.")).toBeInTheDocument()
  })

  it("approves after a confirmation", async () => {
    const user = userEvent.setup()
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    await user.click(await screen.findByRole("button", { name: "Approve" }))
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText("Approve this schedule change?"),
    ).toBeInTheDocument()
    await user.click(
      within(dialog).getByRole("button", { name: "Approve and apply" }),
    )

    await waitFor(() => expect(decisionBody).toEqual({ action: "approve" }))
  })

  it("will not reject without a reason, then sends it", async () => {
    const user = userEvent.setup()
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    await user.click(await screen.findByRole("button", { name: "Reject" }))
    const dialog = await screen.findByRole("alertdialog")
    const confirm = within(dialog).getByRole("button", {
      name: "Reject request",
    })
    expect(confirm).toBeDisabled()

    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Room is booked for exams.",
    )
    expect(confirm).toBeEnabled()
    await user.click(confirm)

    await waitFor(() =>
      expect(decisionBody).toEqual({
        action: "reject",
        decision_reason: "Room is booked for exams.",
      }),
    )
  })

  it("shows the server's reason when an approval can no longer be applied", async () => {
    decisionStatus = 422
    const user = userEvent.setup()
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    await user.click(await screen.findByRole("button", { name: "Approve" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.click(
      within(dialog).getByRole("button", { name: "Approve and apply" }),
    )

    expect(
      await screen.findByText(
        /This section changed after the request was made/,
      ),
    ).toBeInTheDocument()
  })

  it("lets a Program Head withdraw a pending request but not decide it", async () => {
    const user = userEvent.setup()
    renderWithSession(<SectionChangeRequestsPanel mode="requester" />, {
      session: programHead,
    })

    expect(await screen.findByText("IT101 · Section A")).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Reject" }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Withdraw request" }))
    const dialog = await screen.findByRole("alertdialog")
    await user.click(
      within(dialog).getByRole("button", { name: "Withdraw request" }),
    )

    await waitFor(() => expect(decisionBody).toEqual({ action: "cancel" }))
  })

  it("offers no buttons on a request that was already decided", async () => {
    rows = [
      changeRequest({
        status: "rejected",
        status_label: "Rejected",
        decided_by_name: "Registrar Head",
        decision_reason: "Booked.",
      }),
    ]
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    expect(await screen.findByText("Booked.")).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /Approve|Reject/ }),
    ).not.toBeInTheDocument()
  })

  it("says so when there is nothing to review", async () => {
    rows = []
    renderWithSession(<SectionChangeRequestsPanel mode="decider" />, {
      session: registrarHead,
    })

    expect(
      await screen.findByText(
        "No Program Head has asked to change a published schedule.",
      ),
    ).toBeInTheDocument()
  })
})

describe("SectionChangeRequestsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify({ data: [] }))),
    )
  })
  afterEach(() => vi.unstubAllGlobals())

  it("is only for the Registrar Head", () => {
    renderWithSession(<SectionChangeRequestsWorkspace />, {
      session: programHead,
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
