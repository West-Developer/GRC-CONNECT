import {
  enrollmentChangeRequestEnvelopeSchema,
  enrollmentChangeRequestFiltersSchema,
  paginatedEnrollmentChangeRequestsSchema,
  storeEnrollmentChangeRequestInputSchema,
  updateEnrollmentChangeRequestInputSchema,
  type EnrollmentChangeRequest,
  type EnrollmentChangeRequestFilters,
  type Paginated,
  type StoreEnrollmentChangeRequestInput,
  type UpdateEnrollmentChangeRequestInput,
} from "@/features/schemas/enrollment-change-request-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const ENROLLMENT_CHANGE_REQUESTS_PATH = "/api/v1/enrollment-change-requests"

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

export async function listEnrollmentChangeRequests(
  filters: EnrollmentChangeRequestFilters,
  signal?: AbortSignal,
): Promise<Paginated<EnrollmentChangeRequest>> {
  const parsed = parse(enrollmentChangeRequestFiltersSchema, filters, "enrollment change request filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedEnrollmentChangeRequestsSchema,
    await getAuthenticatedJson(
      `${ENROLLMENT_CHANGE_REQUESTS_PATH}?${query.toString()}`,
      signal,
    ),
    "enrollment change request list",
  )
}

export async function createEnrollmentChangeRequest(
  enrollmentId: number,
  input: StoreEnrollmentChangeRequestInput,
): Promise<EnrollmentChangeRequest> {
  const payload = await postAuthenticatedJson(
    `/api/v1/enrollments/${enrollmentId}/change-requests`,
    parse(storeEnrollmentChangeRequestInputSchema, input, "enrollment change request"),
  )
  return parse(enrollmentChangeRequestEnvelopeSchema, payload, "created enrollment change request").data
}

export async function updateEnrollmentChangeRequest(
  id: number,
  input: UpdateEnrollmentChangeRequestInput,
): Promise<EnrollmentChangeRequest> {
  const payload = await patchAuthenticatedJson(
    `${ENROLLMENT_CHANGE_REQUESTS_PATH}/${id}`,
    parse(updateEnrollmentChangeRequestInputSchema, input, "enrollment change request decision"),
  )
  return parse(enrollmentChangeRequestEnvelopeSchema, payload, "updated enrollment change request").data
}
