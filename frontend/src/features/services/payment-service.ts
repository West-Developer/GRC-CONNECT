import {
  paginatedPaymentsSchema,
  paymentFiltersSchema,
  type Paginated,
  type Payment,
  type PaymentFilters,
} from "@/features/schemas/payment-schema"
import { ApiClientError, getAuthenticatedJson } from "@/features/services/api-client"

export const PAYMENTS_PATH = "/api/v1/payments"

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

export async function listPayments(
  filters: PaymentFilters,
  signal?: AbortSignal,
): Promise<Paginated<Payment>> {
  const parsed = parse(paymentFiltersSchema, filters, "payment filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedPaymentsSchema,
    await getAuthenticatedJson(`${PAYMENTS_PATH}?${query.toString()}`, signal),
    "payment list",
  )
}
