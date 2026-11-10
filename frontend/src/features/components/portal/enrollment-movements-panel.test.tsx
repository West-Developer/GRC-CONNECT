import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { EnrollmentMovementsPanel } from "@/features/components/portal/enrollment-movements-panel"
import { renderWithSession } from "@/tests/render-app"

function movement(
  type: "drops" | "withdrawals" | "shifts",
  overrides: Record<string, unknown> = {},
) {
  return {
    data: {
      type: "enrollment_movements",
      movement: type,
      academic_term_id: 2,
      total: 4,
      by_department: [
        { college: "ccs", label: "College of Computer Studies", count: 3 },
        { college: "coe", label: "College of Education", count: 1 },
      ],
      groups:
        type === "shifts"
          ? [
              {
                label: "BSIT → BSCS",
                count: 3,
                from_program_code: "BSIT",
                to_program_code: "BSCS",
              },
              {
                label: "BSED → BSIT",
                count: 1,
                from_program_code: "BSED",
                to_program_code: "BSIT",
              },
            ]
          : [
              {
                label: "BSIT",
                count: 3,
                from_program_code: null,
                to_program_code: null,
              },
              {
                label: "BSED",
                count: 1,
                from_program_code: null,
                to_program_code: null,
              },
            ],
      ...overrides,
    },
  }
}

const programs = {
  data: [
    {
      type: "program",
      id: 1,
      code: "BSIT",
      name: "BS Information Technology",
      status: "active",
      status_label: "Active",
    },
    {
      type: "program",
      id: 2,
      code: "BSCS",
      name: "BS Computer Science",
      status: "active",
      status_label: "Active",
    },
  ],
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

describe("EnrollmentMovementsPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let shiftStatus = 201
  let shiftBody: Record<string, unknown> | null = null

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    shiftStatus = 201
    shiftBody = null
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/program-shifts") && init?.method === "POST") {
        shiftBody =
          typeof init.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null
        if (shiftStatus !== 201) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                error: {
                  code: "VALIDATION_FAILED",
                  message: "The submitted data is invalid.",
                  errors: {
                    student_number: ["The selected student number is invalid."],
                  },
                  request_id: "r1",
                },
              }),
              { status: shiftStatus },
            ),
          )
        }
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "program_shift",
                id: 9,
                from_program_id: 1,
                to_program_id: 2,
                academic_term_id: 2,
              },
            }),
            { status: 201 },
          ),
        )
      }
      if (url.includes("/enrollment-movements")) {
        const type = url.includes("type=shifts")
          ? "shifts"
          : url.includes("type=withdrawals")
            ? "withdrawals"
            : "drops"
        return Promise.resolve(new Response(JSON.stringify(movement(type))))
      }
      if (url.includes("/programs")) {
        return Promise.resolve(new Response(JSON.stringify(programs)))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it("shows the drops total, the department split, and the count per course", async () => {
    renderWithSession(
      <EnrollmentMovementsPanel
        type="drops"
        termId={2}
        college={null}
        canRecord={false}
      />,
      { session: registrarHead },
    )

    expect(await screen.findByText("Subject drops")).toBeInTheDocument()
    expect(screen.getByText("4")).toBeInTheDocument()
    expect(screen.getByText("College of Computer Studies")).toBeInTheDocument()
    const table = screen.getByRole("table")
    expect(within(table).getByText("BSIT")).toBeInTheDocument()
    expect(within(table).getByText("BSED")).toBeInTheDocument()
    expect(
      screen.queryByRole("heading", { name: "Record a course shift" }),
    ).not.toBeInTheDocument()
  })

  it("asks the server for the chosen department", async () => {
    renderWithSession(
      <EnrollmentMovementsPanel
        type="withdrawals"
        termId={2}
        college="coe"
        canRecord={false}
      />,
      { session: registrarHead },
    )

    await screen.findByText("Withdrawals")
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("type=withdrawals"),
      expect.anything(),
    )
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("college=coe"),
      expect.anything(),
    )
  })

  it("shows course shifts as from to", async () => {
    renderWithSession(
      <EnrollmentMovementsPanel
        type="shifts"
        termId={2}
        college={null}
        canRecord={false}
      />,
      { session: registrarHead },
    )

    const table = await screen.findByRole("table")
    const firstRow = within(table).getAllByRole("row")[1]
    expect(firstRow).toHaveTextContent(/BSIT.*BSCS/)
    expect(within(firstRow).getByLabelText("to")).toBeInTheDocument()
    expect(within(firstRow).getByText("3")).toBeInTheDocument()
  })

  it("says so when nothing was recorded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify(movement("shifts", { total: 0, groups: [] })),
        ),
      ),
    )
    renderWithSession(
      <EnrollmentMovementsPanel
        type="shifts"
        termId={2}
        college={null}
        canRecord={false}
      />,
      { session: registrarHead },
    )

    expect(
      await screen.findByText(
        "No course shift has been recorded for this term.",
      ),
    ).toBeInTheDocument()
  })

  it("records a shift for the Registrar", async () => {
    const user = userEvent.setup()
    renderWithSession(
      <EnrollmentMovementsPanel
        type="shifts"
        termId={2}
        college={null}
        canRecord
      />,
      { session: registrarHead },
    )

    await screen.findByRole("heading", { name: "Record a course shift" })
    const submit = screen.getByRole("button", { name: "Record course shift" })
    expect(submit).toBeDisabled()

    await user.type(screen.getByLabelText("Student number"), "2026-0001")
    await user.click(screen.getByLabelText("New course"))
    await user.click(await screen.findByRole("option", { name: /BSCS/ }))
    await user.type(screen.getByLabelText("Reason"), "Changed course")
    expect(submit).toBeEnabled()
    await user.click(submit)

    await waitFor(() =>
      expect(shiftBody).toEqual({
        student_number: "2026-0001",
        to_program_id: 2,
        academic_term_id: 2,
        reason: "Changed course",
      }),
    )
    expect(
      await screen.findByText("Course shift recorded."),
    ).toBeInTheDocument()
  })

  it("shows the server's reason when a shift is refused", async () => {
    shiftStatus = 422
    const user = userEvent.setup()
    renderWithSession(
      <EnrollmentMovementsPanel
        type="shifts"
        termId={2}
        college={null}
        canRecord
      />,
      { session: registrarHead },
    )

    await screen.findByRole("heading", { name: "Record a course shift" })
    await user.type(screen.getByLabelText("Student number"), "NOPE")
    await user.click(screen.getByLabelText("New course"))
    await user.click(await screen.findByRole("option", { name: /BSCS/ }))
    await user.type(screen.getByLabelText("Reason"), "Changed course")
    await user.click(
      screen.getByRole("button", { name: "Record course shift" }),
    )

    expect(
      await screen.findByText("The selected student number is invalid."),
    ).toBeInTheDocument()
  })
})
