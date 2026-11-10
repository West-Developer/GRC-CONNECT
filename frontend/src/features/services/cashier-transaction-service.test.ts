import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  findCashierPaymentCandidate,
  listCashierTransactions,
} from "@/features/services/cashier-transaction-service"

const links = {
  first: "https://api.test/cashier-transactions?page=1",
  last: "https://api.test/cashier-transactions?page=1",
  prev: null,
  next: null,
}
const meta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 2,
}
const enrollmentTransaction = {
  type: "cashier_transaction",
  id: "enrollment_payment:19",
  transaction_type: "enrollment_payment",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-06-01001",
  enrollment_id: 9,
  amount: "5775.00",
  processed_at: "2026-08-14T01:00:00Z",
} as const
const accountTransaction = {
  type: "cashier_transaction",
  id: "account_payment:21",
  transaction_type: "account_payment",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-06-01001",
  enrollment_id: 7,
  amount: "500.00",
  processed_at: "2026-08-14T02:00:00Z",
} as const
const candidate = {
  type: "cashier_payment_candidate",
  student_id: 4,
  student_name: "Maria Santos",
  student_number: "2026-06-01001",
  year_level: 3,
  enrollment_id: 9,
  ticket: {
    id: 12,
    ticket_number: "Q012",
    status: "waiting",
  },
} as const

function jsonResponse(payload: unknown): Response {
  return new Response(JSON.stringify(payload))
}

function url(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("cashier-transaction-service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("lists normalized enrollment and balance transactions with exact filters", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: [accountTransaction, enrollmentTransaction],
        links,
        meta,
      }),
    )

    await expect(
      listCashierTransactions({
        student_number: "2026-06-01001",
        processed_on: "2026-08-14",
      }),
    ).resolves.toMatchObject({
      data: [accountTransaction, enrollmentTransaction],
    })

    expect(url(fetchMock.mock.calls[0]?.[0] as RequestInfo)).toContain(
      "/api/v1/cashier-transactions?student_number=2026-06-01001&processed_on=2026-08-14&page=1&per_page=20",
    )
  })

  it("finds one exact Cashier payment candidate", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: candidate }))

    await expect(findCashierPaymentCandidate("2026-06-01001")).resolves.toEqual(
      candidate,
    )

    expect(url(fetchMock.mock.calls[0]?.[0] as RequestInfo)).toContain(
      "/api/v1/cashier-payment-candidates?student_number=2026-06-01001",
    )
  })

  it("rejects malformed transaction and candidate response contracts", async () => {
    fetchMock
      .mockResolvedValueOnce(
        jsonResponse({
          data: [{ ...enrollmentTransaction, transaction_type: "receipt" }],
          links,
          meta,
        }),
      )
      .mockResolvedValueOnce(
        jsonResponse({ data: { ...candidate, email: "private@grc.test" } }),
      )

    await expect(listCashierTransactions({})).rejects.toMatchObject({
      kind: "contract",
    })
    await expect(
      findCashierPaymentCandidate("2026-06-01001"),
    ).rejects.toMatchObject({ kind: "contract" })
  })
})
