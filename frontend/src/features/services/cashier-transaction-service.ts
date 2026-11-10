import {
  cashierPaymentCandidateEnvelopeSchema,
  cashierPaymentCandidateLookupSchema,
  cashierStudentSearchSchema,
  cashierStudentsEnvelopeSchema,
  cashierTransactionFiltersSchema,
  paginatedCashierTransactionsSchema,
  type CashierPaymentCandidate,
  type CashierStudent,
  type CashierTransactionFilters,
  type PaginatedCashierTransactions,
} from "@/features/schemas/cashier-transaction-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const CASHIER_TRANSACTIONS_PATH = "/api/v1/cashier-transactions"
export const CASHIER_PAYMENT_CANDIDATES_PATH =
  "/api/v1/cashier-payment-candidates"
export const CASHIER_STUDENT_LOOKUP_PATH = "/api/v1/cashier-student-lookup"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const result = schema.safeParse(value)
  if (result.success) return result.data

  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function listCashierTransactions(
  filters: CashierTransactionFilters,
  signal?: AbortSignal,
): Promise<PaginatedCashierTransactions> {
  const parsed = parse(
    cashierTransactionFiltersSchema,
    filters,
    "Cashier transaction filter",
  )
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }

  return parse(
    paginatedCashierTransactionsSchema,
    await getAuthenticatedJson(
      `${CASHIER_TRANSACTIONS_PATH}?${query.toString()}`,
      signal,
    ),
    "Cashier transaction list",
  )
}

export async function findCashierPaymentCandidate(
  studentNumber: string,
  signal?: AbortSignal,
): Promise<CashierPaymentCandidate> {
  const parsedStudentNumber = parse(
    cashierPaymentCandidateLookupSchema,
    studentNumber,
    "Cashier student-number lookup",
  )
  const query = new URLSearchParams({ student_number: parsedStudentNumber })
  const envelope = parse(
    cashierPaymentCandidateEnvelopeSchema,
    await getAuthenticatedJson(
      `${CASHIER_PAYMENT_CANDIDATES_PATH}?${query.toString()}`,
      signal,
    ),
    "Cashier payment candidate",
  )

  return envelope.data
}

/**
 * The Cashier's general student search (Advance Payment): any student by
 * number, name fragment or exact email, whatever their enrollment or queue
 * state. Not to be confused with `findCashierPaymentCandidate`, which only
 * returns a student who is waiting for payment.
 */
export async function searchCashierStudents(
  search: string,
  signal?: AbortSignal,
): Promise<readonly CashierStudent[]> {
  const parsedSearch = parse(
    cashierStudentSearchSchema,
    search,
    "Cashier student search",
  )
  const query = new URLSearchParams({ search: parsedSearch })
  const envelope = parse(
    cashierStudentsEnvelopeSchema,
    await getAuthenticatedJson(
      `${CASHIER_STUDENT_LOOKUP_PATH}?${query.toString()}`,
      signal,
    ),
    "Cashier student search results",
  )

  return envelope.data
}
