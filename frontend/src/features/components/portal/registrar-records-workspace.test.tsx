import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { RegistrarRecordsWorkspace } from "@/features/components/portal/registrar-records-workspace"
import { renderWithSession } from "@/tests/render-app"

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const registrarSession = {
  userId: "9",
  displayName: "Registrar Staff",
  role: "registrar_staff" as const,
  signedInAt: "2026-07-30T00:00:00Z",
}

function paginated(entries: unknown[]) {
  return {
    data: entries,
    links: { first: "http://x/1", last: "http://x/1", prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: entries.length,
    },
  }
}

const pendingCredit = {
  type: "transferee_credit",
  id: 1,
  student_id: 30,
  student_number: "2026-0002",
  source_institution: "Other University",
  source_subject_code: "MATH101",
  source_subject_title: "Calculus 1",
  source_grade: "1.75",
  credited_units: 3,
  subject_id: null,
  subject_code: null,
  status: "pending",
  status_label: "Pending",
  processed_at: null,
  created_at: "2026-07-01T00:00:00Z",
}

// What reaches the Registrar: a credit the Program Chair mapped and endorsed.
const endorsedCredit = {
  ...pendingCredit,
  id: 3,
  student_name: "Test Student",
  subject_id: 10,
  subject_code: "MATH101G",
  subject_title: "Calculus 1",
  status: "endorsed",
  status_label: "Endorsed",
  endorsed_at: "2026-07-02T00:00:00Z",
}

const pendingWithdrawal = {
  type: "withdrawal_request",
  id: 2,
  enrollment_id: 55,
  student_number: "2026-0003",
  reason: "Personal reasons",
  status: "pending",
  status_label: "Pending",
  processed_at: null,
  created_at: "2026-07-01T00:00:00Z",
}

function stubEmptyRoutes(fetchMock: ReturnType<typeof vi.fn<typeof fetch>>) {
  fetchMock.mockImplementation(() =>
    Promise.resolve(new Response(JSON.stringify(paginated([])))),
  )
}

describe("RegistrarRecordsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("is not available for a non-registrar-staff role", () => {
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: { ...registrarSession, role: "student" } },
    )

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("shows only the credit-mappings module when routed there", async () => {
    stubEmptyRoutes(fetchMock)
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    expect(
      await screen.findByRole("heading", { name: "Credit mappings" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Transferee credits" }),
    ).toBeInTheDocument()
    // Recording and mapping credits is the Program Chair's work now.
    expect(
      screen.queryByRole("heading", { name: "Record a transferee credit" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("heading", { name: "Withdrawal requests" }),
    ).not.toBeInTheDocument()
  })

  it("shows only the drops-withdrawals module when routed there", async () => {
    stubEmptyRoutes(fetchMock)
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />,
      { session: registrarSession },
    )

    expect(
      await screen.findByRole("heading", { name: "Drops & withdrawals" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { name: "Withdrawal requests" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("heading", { name: "Record a transferee credit" }),
    ).not.toBeInTheDocument()
  })

  it("lists endorsed transferee credits with what they will be credited as", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([endorsedCredit]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Transferee credits",
    })
    expect(
      within(table).getByText(/Test Student \(2026-0002\)/),
    ).toBeInTheDocument()
    expect(within(table).getByText("MATH101G — Calculus 1")).toBeInTheDocument()
  })

  it("offers no decision on a credit the Program Chair has not endorsed", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([pendingCredit]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Transferee credits",
    })
    expect(within(table).getByText("Not mapped")).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Reject" }),
    ).not.toBeInTheDocument()
  })

  it("lets Registrar Staff follow credits but not decide them", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([endorsedCredit]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Transferee credits",
    })
    expect(within(table).getByText("MATH101G — Calculus 1")).toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Approve" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Return" }),
    ).not.toBeInTheDocument()
    expect(
      within(table).queryByRole("button", { name: "Reject" }),
    ).not.toBeInTheDocument()
  })

  it.each([
    "credit-mappings",
    "drops-withdrawals",
    "academic-records",
    "enrollment-documents",
  ])(
    "is closed to the Registrar Head for %s: the Head decides credits in the Registrar Head review workspace",
    (moduleId) => {
      renderWithSession(
        <RegistrarRecordsWorkspace initialModuleId={moduleId} />,
        { session: { ...registrarSession, role: "registrar_head" } },
      )

      expect(
        screen.getByText("This workspace is not available for your role."),
      ).toBeInTheDocument()
      expect(fetchMock).not.toHaveBeenCalled()
    },
  )

  it("lists pending withdrawal requests", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/withdrawal-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([pendingWithdrawal]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Withdrawal requests",
    })
    expect(within(table).getByText("2026-0003")).toBeInTheDocument()
    expect(within(table).getByText("Personal reasons")).toBeInTheDocument()
  })

  it("approves a pending withdrawal request with a single-step decision", async () => {
    let decisionBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/withdrawal-requests/2") && init?.method === "PATCH") {
        decisionBody = JSON.parse(init.body as string)
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
      if (url.includes("/withdrawal-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([pendingWithdrawal]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    const user = userEvent.setup()
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Withdrawal requests",
    })
    await user.click(within(table).getByRole("button", { name: "Approve" }))
    await user.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Confirm decision",
      }),
    )

    await vi.waitFor(() => expect(decisionBody).toEqual({ action: "approve" }))
  })

  it("requires a reason before confirming a withdrawal rejection, with a visible message", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/withdrawal-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([pendingWithdrawal]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    const user = userEvent.setup()
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Withdrawal requests",
    })
    await user.click(within(table).getByRole("button", { name: "Reject" }))

    const dialog = screen.getByRole("alertdialog")
    const confirmButton = within(dialog).getByRole("button", {
      name: "Confirm decision",
    })
    expect(confirmButton).toBeDisabled()
    expect(
      within(dialog).getByText("Reason is required to reject."),
    ).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText("Reason"), "Not eligible")
    expect(confirmButton).toBeEnabled()
  })

  it("shows the academic records module read-only", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/academic-grades")) {
        return Promise.resolve(
          new Response(
            JSON.stringify(
              paginated([
                {
                  type: "academic_grade",
                  id: 5,
                  student_id: 30,
                  student_number: "2026-0002",
                  subject_id: 101,
                  subject_code: "CS101",
                  section_id: 44,
                  academic_term_id: 1,
                  mark: "1.50",
                  mark_label: "with Distinction",
                  final_grade: "1.50",
                  remarks: null,
                  status: "locked",
                  status_label: "Locked",
                  submitted_at: "2026-07-01T00:00:00Z",
                  locked_at: "2026-07-01T00:00:00Z",
                },
              ]),
            ),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="academic-records" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Academic records",
    })
    expect(within(table).getByText("1.50")).toBeInTheDocument()
    expect(within(table).queryByRole("button")).not.toBeInTheDocument()
  })

  it("shows the enrollment documents module read-only", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/enrollment-documents")) {
        return Promise.resolve(
          new Response(
            JSON.stringify(
              paginated([
                {
                  type: "enrollment_document",
                  id: 7,
                  enrollment_id: 55,
                  student_number: "2026-0003",
                  document_type: "cor",
                  document_type_label: "Certificate of Registration",
                  document_number: "COR-2026-0001",
                  generated_at: "2026-07-01T00:00:00Z",
                },
              ]),
            ),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="enrollment-documents" />,
      { session: registrarSession },
    )

    const table = await screen.findByRole("table", {
      name: "Enrollment documents",
    })
    expect(within(table).getByText("COR-2026-0001")).toBeInTheDocument()
  })

  it("shows empty states for each module with nothing recorded", async () => {
    stubEmptyRoutes(fetchMock)
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    expect(
      await screen.findByText(
        "No credit requests have reached the Registrar Head yet.",
      ),
    ).toBeInTheDocument()
  })

  it("shows an authorization error when the server denies a 403 for this registrar-scoped module", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "FORBIDDEN",
                message: "You are not authorized to view this module.",
                errors: {},
                request_id: "request-403",
              },
            }),
            { status: 403 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    expect(await screen.findByText("You don't have access")).toBeInTheDocument()
    expect(
      screen.getByText("You are not authorized to view this module."),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Try again" }),
    ).not.toBeInTheDocument()
  })

  it("shows a not-found error when the requested records module returns 404", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "NOT_FOUND",
                message: "The requested records could not be found.",
                errors: {},
                request_id: "request-404",
              },
            }),
            { status: 404 },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    expect(await screen.findByText("Not found")).toBeInTheDocument()
    expect(
      screen.getByText("The requested records could not be found."),
    ).toBeInTheDocument()
  })

  it("shows a throttled message with the retry wait time when the server rate-limits the request", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/withdrawal-requests")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "THROTTLED",
                message: "Too many requests.",
                errors: {},
                request_id: "request-429",
              },
            }),
            { status: 429, headers: { "Retry-After": "30" } },
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />,
      { session: registrarSession },
    )

    expect(await screen.findByText("Slow down")).toBeInTheDocument()
    expect(
      screen.getByText(/Try again in about 30 seconds/),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Try again" }),
    ).not.toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/transferee-credits")) {
        return Promise.resolve(
          new Response(JSON.stringify(paginated([pendingCredit]))),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    const { container } = renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      { session: registrarSession },
    )

    await screen.findByRole("table", { name: "Transferee credits" })
    expect(await axe(container)).toHaveNoViolations()
  })

  it("is not available to a Program Chair: credit mapping has its own workspace", () => {
    renderWithSession(
      <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />,
      {
        session: {
          userId: "12",
          displayName: "Prof. Chair",
          role: "program_chair",
          signedInAt: "2026-07-29T12:00:00Z",
        },
      },
    )

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
