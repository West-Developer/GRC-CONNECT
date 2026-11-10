import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { StudentAccountBalancePanel } from "@/features/components/portal/student-account-balance-panel"
import type { StudentAccount } from "@/features/schemas/student-account-schema"

const account: StudentAccount = {
  type: "student_account",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-0001",
  year_level: 3,
  currency: "PHP",
  total_assessed: "6000.00",
  total_paid: "1000.00",
  prior_balance: "3500.00",
  outstanding_balance: "5000.00",
  advance_payment_balance: "0.00",
  has_promissory_note_on_file: true,
  entries: [
    {
      enrollment_id: 9,
      academic_term_id: 2,
      academic_term_label: "2025-2026 · 2nd",
      assessment_amount: "4500.00",
      confirmed_payment_amount: "1000.00",
      account_payment_amount: "0.00",
      outstanding_balance: "3500.00",
      promissory_note_on_file: true,
    },
    {
      enrollment_id: 10,
      academic_term_id: 3,
      academic_term_label: "2026-2027 · 1st",
      assessment_amount: "1500.00",
      confirmed_payment_amount: "0.00",
      account_payment_amount: "0.00",
      outstanding_balance: "1500.00",
      promissory_note_on_file: false,
    },
  ],
  transactions: [
    {
      id: "enrollment_payment:9",
      transaction_type: "enrollment_payment",
      transaction_type_label: "Enrollment Confirmation Payment",
      enrollment_id: 9,
      academic_term_label: "2025-2026 · 2nd",
      amount: "1000.00",
      reference_number: "OR-EP000009",
      cashier_name: "Cashier Staff",
      promissory_note_on_file: true,
      processed_at: "2026-08-01T08:00:00Z",
    },
  ],
}

describe("StudentAccountBalancePanel", () => {
  it("shows the student's total and term balances with the promissory-note indicator", () => {
    render(<StudentAccountBalancePanel account={account} />)

    expect(
      screen.getByRole("heading", { name: "Account balance" }),
    ).toBeInTheDocument()
    expect(screen.getByText("₱5,000.00")).toBeInTheDocument()
    expect(screen.getByText("2025-2026 · 2nd")).toBeInTheDocument()
    expect(screen.getAllByText("₱3,500.00")).toHaveLength(2)
    expect(screen.getByText("Promissory note on file")).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /record.*payment/i }),
    ).not.toBeInTheDocument()
  })

  it("shows the advance credit card and notice when the student has advance balance", () => {
    render(
      <StudentAccountBalancePanel
        account={{
          ...account,
          outstanding_balance: "0.00",
          advance_payment_balance: "850.00",
        }}
      />,
    )

    expect(screen.getByText("Advance payment credit")).toBeInTheDocument()
    expect(screen.getAllByText("₱850.00").length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText(/You have an advance payment balance of/i)).toBeInTheDocument()
  })
})
