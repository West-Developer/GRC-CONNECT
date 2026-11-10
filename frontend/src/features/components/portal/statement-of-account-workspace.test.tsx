import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { StatementOfAccountWorkspace } from "@/features/components/portal/statement-of-account-workspace"
import { renderWithSession } from "@/tests/render-app"

const studentSession = {
  userId: "1",
  displayName: "Maria Santos",
  role: "student" as const,
  signedInAt: "2026-09-26T12:00:00Z",
}

const accountingSession = {
  userId: "6",
  displayName: "Accounting Staff",
  role: "accounting_staff" as const,
  signedInAt: "2026-09-26T12:00:00Z",
}

const statement = {
  type: "statement_of_account",
  student: {
    student_profile_id: 4,
    student_number: "2026-0001",
    name: "Maria Santos",
    program_code: "BSCS",
    program_name: "BS Computer Science",
  },
  academic_term_id: null,
  summary: {
    total_assessed: "1800.00",
    total_paid: "1500.00",
    outstanding_balance: "300.00",
    advance_payment_balance: "0.00",
  },
  terms: [
    {
      academic_term_id: 1,
      label: "2025-2026 · 1st",
      enrollment_id: 9,
      enrollment_status: "enrolled",
      lines: [
        {
          category: "tuition",
          label: "Tuition",
          quantity: "3.0",
          unit_amount: "200.00",
          amount: "600.00",
        },
        {
          category: "miscellaneous",
          label: "Library",
          quantity: null,
          unit_amount: null,
          amount: "400.00",
        },
      ],
      scholarship_discount: "0.00",
      assessment_total: "1000.00",
      payments: [
        {
          kind: "enrollment_payment",
          label: "Enrollment confirmation payment",
          reference_number: "OR-EP000001",
          amount: "700.00",
          promissory_note_on_file: true,
          paid_at: "2025-08-02T02:00:00Z",
        },
      ],
      paid_total: "700.00",
      outstanding: "300.00",
      prior_balance: "0.00",
      running_balance: "300.00",
    },
  ],
  credits: [],
  generated_at: "2026-09-26T04:00:00Z",
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("StatementOfAccountWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
    fetchMock.mockReset()
  })

  it("is not available to other roles", () => {
    renderWithSession(<StatementOfAccountWorkspace />, {
      session: { ...studentSession, role: "registrar_head" as const },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("shows a student their own statement with the running balance and promissory flag", async () => {
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/api/v1/me/statement-of-account")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: statement })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<StatementOfAccountWorkspace />, {
      session: studentSession,
    })

    expect(
      (await screen.findAllByText("Total assessed")).length,
    ).toBeGreaterThan(0)
    expect(screen.getByText("Outstanding balance")).toBeInTheDocument()
    expect(screen.getAllByText("₱300.00").length).toBeGreaterThan(0)
    expect(screen.getByText("Running balance")).toBeInTheDocument()
    expect(screen.getByText("Promissory note")).toBeInTheDocument()
    expect(screen.getByText("OR-EP000001")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Download PDF/i }),
    ).toBeInTheDocument()
    // No student lookup for a Student.
    expect(
      screen.queryByLabelText(/Student number, name, or email/i),
    ).not.toBeInTheDocument()
  })

  it("shows a full-payment discount line as a credit like a scholarship", async () => {
    const [term] = statement.terms
    fetchMock.mockImplementation((input) => {
      if (url(input).includes("/api/v1/me/statement-of-account")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...statement,
                terms: [
                  {
                    ...term,
                    lines: [
                      ...term.lines,
                      {
                        category: "full_payment_discount",
                        label: "Full-payment discount (10%)",
                        quantity: "10.0",
                        unit_amount: null,
                        amount: "-105.00",
                      },
                    ],
                  },
                ],
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<StatementOfAccountWorkspace />, {
      session: studentSession,
    })

    const row = (
      await screen.findByText("Full-payment discount (10%)")
    ).closest("tr") as HTMLElement
    expect(within(row).getByText(/105\.00/).className).toMatch(/text-success/)
    // An ordinary charge stays in the default tone.
    const libraryRow = screen.getByText("Library").closest("tr") as HTMLElement
    expect(within(libraryRow).getByText(/400\.00/).className).not.toMatch(
      /text-success/,
    )
  })

  it("re-queries with the chosen term", async () => {
    const requested: string[] = []
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/api/v1/me/statement-of-account")) {
        requested.push(target)
        return Promise.resolve(
          new Response(JSON.stringify({ data: statement })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const user = userEvent.setup()
    renderWithSession(<StatementOfAccountWorkspace />, {
      session: studentSession,
    })

    await screen.findAllByText("Total assessed")
    await user.selectOptions(screen.getByLabelText("Term"), "1")

    await vi.waitFor(() =>
      expect(
        requested.some((target) => target.includes("academic_term_id=1")),
      ).toBe(true),
    )
  })

  it("tells the student when there is nothing assessed yet", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              ...statement,
              terms: [],
              summary: {
                total_assessed: "0.00",
                total_paid: "0.00",
                outstanding_balance: "0.00",
                advance_payment_balance: "0.00",
              },
            },
          }),
        ),
      ),
    )

    renderWithSession(<StatementOfAccountWorkspace />, {
      session: studentSession,
    })

    expect(
      await screen.findByText("No assessed enrollment to show yet."),
    ).toBeInTheDocument()
  })

  it("lets Accounting Staff look up a student and open their statement", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                {
                  type: "cashier_student",
                  student_id: 4,
                  student_name: "Maria Santos",
                  student_number: "2026-0001",
                  year_level: 2,
                  financial_status: "payee",
                  financial_status_label: "Payee",
                },
              ],
            }),
          ),
        )
      }
      if (target.includes("/api/v1/students/4/statement-of-account")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: statement })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const user = userEvent.setup()
    renderWithSession(<StatementOfAccountWorkspace />, {
      session: accountingSession,
    })

    await user.type(
      screen.getByLabelText(/Student number, name, or email/i),
      "2026-0001",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    expect(
      (await screen.findAllByText("Total assessed")).length,
    ).toBeGreaterThan(0)
    expect(screen.getByText(/2026-0001 · BSCS/)).toBeInTheDocument()
  })

  it("shows an error state when the statement cannot be loaded", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ message: "Server error" }), {
          status: 403,
        }),
      ),
    )

    renderWithSession(<StatementOfAccountWorkspace />, {
      session: studentSession,
    })

    expect(await screen.findByRole("alert")).toBeInTheDocument()
  })
})
