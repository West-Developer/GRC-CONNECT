import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AdvancePaymentWorkspace } from "@/features/components/portal/advance-payment-workspace"
import { renderWithSession } from "@/tests/render-app"

const accountingSession = {
  userId: "6",
  displayName: "Accounting Staff",
  role: "accounting_staff" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

// The Advance Payment page searches every student, not only the ones waiting
// in the payment queue, so the row carries no enrollment or ticket.
const studentRow = {
  type: "cashier_student",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-0001",
  year_level: 2,
  financial_status: "payee",
  financial_status_label: "Payee",
}

const accountData = {
  type: "student_account",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-0001",
  year_level: 2,
  currency: "PHP",
  total_assessed: "10000.00",
  total_paid: "2000.00",
  prior_balance: "3000.00",
  outstanding_balance: "8000.00",
  advance_payment_balance: "500.00",
  has_promissory_note_on_file: false,
  entries: [
    {
      enrollment_id: 9,
      academic_term_id: 2,
      academic_term_label: "2026-2027 · 1st",
      assessment_amount: "10000.00",
      confirmed_payment_amount: "2000.00",
      account_payment_amount: "0.00",
      outstanding_balance: "8000.00",
      promissory_note_on_file: false,
    },
  ],
  transactions: [
    {
      id: "tx-1",
      transaction_type: "enrollment_payment",
      transaction_type_label: "Enrollment payment",
      enrollment_id: 9,
      academic_term_label: "2026-2027 · 1st",
      amount: "2000.00",
      reference_number: "OR-001",
      cashier_name: "Accounting Staff",
      promissory_note_on_file: false,
      processed_at: "2026-08-01T10:00:00Z",
    },
  ],
}

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("AdvancePaymentWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("does not render for unauthorized student role", () => {
    renderWithSession(<AdvancePaymentWorkspace />, {
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

  it("searches student and renders account balances with the Payee advance payment and classification actions", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentRow] })),
        )
      }
      if (target.includes("/students/4/account")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: accountData })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, {
      session: accountingSession,
    })

    const input = screen.getByLabelText(/Student number, name, or email/i)
    await user.type(input, "2026-0001")
    await user.click(screen.getByRole("button", { name: "Find student" }))

    expect(await screen.findByText("Maria Santos")).toBeInTheDocument()
    expect(screen.getByText(/2nd Year/)).toBeInTheDocument()
    expect(screen.getByText("Advance Payment Credit")).toBeInTheDocument()
    expect(screen.getByText("₱500.00")).toBeInTheDocument()
    expect(screen.getByText("Current Outstanding Balance")).toBeInTheDocument()
    expect(screen.getByText("₱8,000.00")).toBeInTheDocument()

    expect(
      screen.getByRole("button", { name: /Record Payee Advance Payment/i }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: /Edit Classification/i }),
    ).toBeInTheDocument()
    // Assigning a scholarship discount now belongs to the Payment Queue's
    // Confirm payment flow (ADR 0025), not to this page.
    expect(
      screen.queryByRole("button", { name: /Assign Scholarship Discount/i }),
    ).not.toBeInTheDocument()
  })

  it("records payee advance payment without minimum amount constraint", async () => {
    let recordedBody: any = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentRow] })),
        )
      }
      if (target.includes("/students/4/account-payments") && init?.method === "POST") {
        recordedBody = JSON.parse(init.body as string)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...accountData, advance_payment_balance: "2000.00" },
            }),
            { status: 201 },
          ),
        )
      }
      if (target.includes("/students/4/account")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: accountData })),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, {
      session: accountingSession,
    })

    await user.type(
      screen.getByLabelText(/Student number, name, or email/i),
      "2026-0001",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    await user.click(
      await screen.findByRole("button", {
        name: /Record Payee Advance Payment/i,
      }),
    )

    const dialog = screen.getByRole("dialog")
    const amountInput = within(dialog).getByLabelText(
      /Advance Payment Amount \(PHP\)/i,
    )

    // Try entering 0 (invalid)
    await user.clear(amountInput)
    await user.type(amountInput, "0")

    // Button should be disabled when amount <= 0
    expect(
      within(dialog).getByRole("button", { name: "Confirm Advance Payment" }),
    ).toBeDisabled()

    // Enter valid 1500
    await user.clear(amountInput)
    await user.type(amountInput, "1500")

    const confirmBtn = within(dialog).getByRole("button", {
      name: "Confirm Advance Payment",
    })
    expect(confirmBtn).not.toBeDisabled()
    await user.click(confirmBtn)

    expect(recordedBody).toEqual({
      amount: 1500,
      financial_status: "payee",
    })

    expect(
      await screen.findByText("Advance Payment Recorded Successfully"),
    ).toBeInTheDocument()
  })

  it("does not claim to be finding a student before anything has been searched", () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify({ data: [] }))),
    )
    renderWithSession(<AdvancePaymentWorkspace />, {
      session: accountingSession,
    })

    expect(
      screen.queryByText("Finding student record…"),
    ).not.toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("finds a student who is not waiting in the payment queue, and searches without an enrollment lookup", async () => {
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentRow] })),
        )
      }
      if (target.includes("/students/4/account")) {
        return Promise.resolve(new Response(JSON.stringify({ data: accountData })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, { session: accountingSession })

    await user.type(
      screen.getByLabelText(/Student number, name, or email/i),
      "2026-0001",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    expect(await screen.findByText("Maria Santos")).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([input]) =>
        url(input).includes("/cashier-payment-candidates"),
      ),
    ).toBe(false)
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/cashier-student-lookup?search=2026-0001"),
      expect.anything(),
    )
  })

  it("lets the cashier pick from several matches", async () => {
    const other = {
      ...studentRow,
      student_id: 5,
      student_name: "Maria Cruz",
      student_number: "2026-0002",
    }
    fetchMock.mockImplementation((input) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [studentRow, other] })),
        )
      }
      if (target.includes("/students/5/account")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...accountData, student_id: 5, student_name: "Maria Cruz" },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, { session: accountingSession })

    await user.type(
      screen.getByLabelText(/Student number, name, or email/i),
      "Maria",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    // Two matches: nothing is opened until one is chosen.
    expect(
      screen.queryByRole("button", { name: /Record Payee Advance Payment/i }),
    ).not.toBeInTheDocument()
    await user.click(
      await screen.findByRole("button", { name: /Maria Cruz/ }),
    )

    expect(
      await screen.findByRole("button", { name: /Record Payee Advance Payment/i }),
    ).toBeInTheDocument()
  })

  it("says so when nobody matches", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify({ data: [] }))),
    )
    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, { session: accountingSession })

    await user.type(
      screen.getByLabelText(/Student number, name, or email/i),
      "Nobody Here",
    )
    await user.click(screen.getByRole("button", { name: "Find student" }))

    expect(
      await screen.findByText(/No student found matching "Nobody Here"/),
    ).toBeInTheDocument()
  })

  it("can record an advance payment again after searching for another student", async () => {
    const other = {
      ...studentRow,
      student_id: 5,
      student_name: "Ana Reyes",
      student_number: "2026-0002",
    }
    const posts: string[] = []
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [target.includes("search=2026-0002") ? other : studentRow],
            }),
          ),
        )
      }
      if (target.includes("/account-payments") && init?.method === "POST") {
        posts.push(target)
        return Promise.resolve(
          new Response(JSON.stringify({ data: accountData }), { status: 201 }),
        )
      }
      if (target.includes("/students/4/account") || target.includes("/students/5/account")) {
        return Promise.resolve(new Response(JSON.stringify({ data: accountData })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, { session: accountingSession })
    const search = screen.getByLabelText(/Student number, name, or email/i)

    for (const number of ["2026-0001", "2026-0002"]) {
      await user.clear(search)
      await user.type(search, number)
      await user.click(screen.getByRole("button", { name: "Find student" }))
      await user.click(
        await screen.findByRole("button", { name: /Record Payee Advance Payment/i }),
      )
      const dialog = await screen.findByRole("dialog")
      await user.type(
        within(dialog).getByLabelText(/Advance Payment Amount/i),
        "500",
      )
      await user.click(
        within(dialog).getByRole("button", { name: "Confirm Advance Payment" }),
      )
      await screen.findByText("Advance Payment Recorded Successfully")
    }

    expect(posts).toHaveLength(2)
    expect(posts[0]).toContain("/students/4/account-payments")
    expect(posts[1]).toContain("/students/5/account-payments")
  })

  it("accepts advance payment amounts below 1000 without minimum requirement", async () => {
    let capturedBody: any = null
    fetchMock.mockImplementation((input, init) => {
      const target = url(input)
      if (target.includes("/cashier-student-lookup")) {
        return Promise.resolve(new Response(JSON.stringify({ data: [studentRow] })))
      }
      if (target.includes("/account-payments") && init?.method === "POST") {
        capturedBody = JSON.parse(String(init.body))
        return Promise.resolve(
          new Response(JSON.stringify({ data: accountData }), { status: 201 }),
        )
      }
      if (target.includes("/students/4/account")) {
        return Promise.resolve(new Response(JSON.stringify({ data: accountData })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWithSession(<AdvancePaymentWorkspace />, { session: accountingSession })

    await user.type(screen.getByLabelText(/Student number, name, or email/i), "2026-0001")
    await user.click(screen.getByRole("button", { name: "Find student" }))
    await user.click(await screen.findByRole("button", { name: /Record Payee Advance Payment/i }))

    const dialog = await screen.findByRole("dialog")
    await user.type(within(dialog).getByLabelText(/Advance Payment Amount/i), "250")
    await user.click(within(dialog).getByRole("button", { name: "Confirm Advance Payment" }))

    await screen.findByText("Advance Payment Recorded Successfully")
    expect(capturedBody).toEqual({ amount: 250, financial_status: "payee" })
  })
})
