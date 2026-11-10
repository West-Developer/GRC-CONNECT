import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { PaymentRecordsWorkspace } from "@/features/components/portal/payment-records-workspace"
import { renderWithSession } from "@/tests/render-app"

const paginationLinks = {
  first: "https://api.test/cashier-transactions?page=1",
  last: "https://api.test/cashier-transactions?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
}

const enrollmentTransaction = {
  type: "cashier_transaction",
  id: "enrollment_payment:19",
  transaction_type: "enrollment_payment",
  student_id: 4,
  student_name: "Maria Santos",
  enrollment_id: 9,
  student_number: "2026-0001",
  amount: "5775.00",
  processed_at: "2026-07-30T00:00:00Z",
} as const

const accountTransaction = {
  type: "cashier_transaction",
  id: "account_payment:20",
  transaction_type: "account_payment",
  student_id: 4,
  student_name: "Maria Santos",
  enrollment_id: 7,
  student_number: "2026-0001",
  amount: "500.00",
  processed_at: "2026-07-29T00:00:00Z",
} as const

const accountingSession = {
  userId: "6",
  displayName: "Accounting Staff",
  role: "accounting_staff",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function url(input: RequestInfo | URL) {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("PaymentRecordsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("does not render payment history for an unauthorized role", () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(<PaymentRecordsWorkspace />, {
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

  it("lists enrollment and balance receipts in one transaction history", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [enrollmentTransaction, accountTransaction],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(<PaymentRecordsWorkspace />, {
      session: accountingSession,
    })

    const table = await screen.findByRole("table", {
      name: "Transaction history",
    })
    expect(within(table).getAllByText("Maria Santos")).toHaveLength(2)
    expect(within(table).getAllByText("2026-0001")).toHaveLength(2)
    expect(within(table).getByText("#9")).toBeInTheDocument()
    expect(within(table).getByText("₱5775.00")).toBeInTheDocument()
    expect(within(table).getByText("Enrollment payment")).toBeInTheDocument()
    expect(within(table).getByText("Balance payment")).toBeInTheDocument()
  })

  it("filters by exact student number and processed date only after Search", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify({
            data: [enrollmentTransaction],
            links: paginationLinks,
            meta: paginationMeta,
          }),
        ),
      ).then((response) => {
        void url(input)
        return response
      }),
    )
    renderWithSession(<PaymentRecordsWorkspace />, {
      session: accountingSession,
    })

    await screen.findByRole("table", { name: "Transaction history" })
    await user.type(screen.getByLabelText("Student number"), "2026-0001")
    await user.type(screen.getByLabelText("Processed on"), "2026-08-01")
    await user.click(screen.getByRole("button", { name: "Search" }))

    await vi.waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          url(input).includes(
            "student_number=2026-0001&processed_on=2026-08-01",
          ),
        ),
      ).toBe(true),
    )
  })

  it("allows Registrar Head to view transaction history too", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [enrollmentTransaction],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    renderWithSession(<PaymentRecordsWorkspace />, {
      session: {
        userId: "5",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(
      await screen.findByRole("table", { name: "Transaction history" }),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [enrollmentTransaction],
          links: paginationLinks,
          meta: paginationMeta,
        }),
      ),
    )
    const { container } = renderWithSession(<PaymentRecordsWorkspace />, {
      session: accountingSession,
    })

    await screen.findByRole("table", { name: "Transaction history" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
