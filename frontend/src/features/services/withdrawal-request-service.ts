import {
  createWithdrawalRequestInputSchema,
  decideWithdrawalRequestInputSchema,
  paginatedWithdrawalRequestsSchema,
  withdrawalRequestEnvelopeSchema,
  withdrawalRequestFiltersSchema,
  type CreateWithdrawalRequestInput,
  type DecideWithdrawalRequestInput,
  type Paginated,
  type WithdrawalRequest,
  type WithdrawalRequestFilters,
} from "@/features/schemas/withdrawal-request-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const WITHDRAWAL_REQUESTS_PATH = "/api/v1/withdrawal-requests"

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

export async function listWithdrawalRequests(
  filters: WithdrawalRequestFilters,
  signal?: AbortSignal,
): Promise<Paginated<WithdrawalRequest>> {
  const parsed = parse(
    withdrawalRequestFiltersSchema,
    filters,
    "withdrawal request filter",
  )
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedWithdrawalRequestsSchema,
    await getAuthenticatedJson(
      `${WITHDRAWAL_REQUESTS_PATH}?${query.toString()}`,
      signal,
    ),
    "withdrawal request list",
  )
}

export async function createWithdrawalRequest(
  enrollmentId: number,
  input: CreateWithdrawalRequestInput,
): Promise<WithdrawalRequest> {
  const payload = await postAuthenticatedJson(
    `/api/v1/enrollments/${enrollmentId}/withdraw`,
    parse(createWithdrawalRequestInputSchema, input, "withdrawal request"),
  )
  return parse(
    withdrawalRequestEnvelopeSchema,
    payload,
    "created withdrawal request",
  ).data
}

export async function decideWithdrawalRequest(
  id: number,
  input: DecideWithdrawalRequestInput,
): Promise<WithdrawalRequest> {
  const payload = await patchAuthenticatedJson(
    `${WITHDRAWAL_REQUESTS_PATH}/${id}`,
    parse(decideWithdrawalRequestInputSchema, input, "withdrawal decision"),
  )
  return parse(
    withdrawalRequestEnvelopeSchema,
    payload,
    "updated withdrawal request",
  ).data
}
