import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  getOwnStudentAccount,
  getStudentAccount,
  recordStudentAccountPayment,
} from "@/features/services/student-account-service"

const account = {
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
  ],
} as const

// The service parses the response, and the schema fills in the fields an older API omitted
// (advance payment credit and the transaction list).
const parsedAccount = {
  ...account,
  advance_payment_balance: "0.00",
  transactions: [],
}

describe("student-account-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("gets the authenticated student's own balance summary", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: account })),
    )

    await expect(getOwnStudentAccount()).resolves.toEqual(parsedAccount)
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/student-account")
  })

  it("gets Cashier account context and records a balance-only payment", async () => {
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: account })))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: { ...account, outstanding_balance: "4500.00" },
          }),
          { status: 201 },
        ),
      )

    await expect(getStudentAccount(4)).resolves.toEqual(parsedAccount)
    await expect(
      recordStudentAccountPayment(4, { amount: 500 }),
    ).resolves.toMatchObject({
      outstanding_balance: "4500.00",
    })

    expect(fetchMock.mock.calls[0]?.[0]).toContain("/students/4/account")
    expect(fetchMock.mock.calls[1]?.[0]).toContain(
      "/students/4/account-payments",
    )
    expect(fetchMock.mock.calls[1]?.[1]?.method).toBe("POST")
    expect(JSON.parse(fetchMock.mock.calls[1]?.[1]?.body as string)).toEqual({
      amount: 500,
    })
  })
})
