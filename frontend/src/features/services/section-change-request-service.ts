import {
  createSectionChangeRequestInputSchema,
  decideSectionChangeRequestInputSchema,
  sectionChangeRequestEnvelopeSchema,
  sectionChangeRequestsEnvelopeSchema,
  type CreateSectionChangeRequestInput,
  type DecideSectionChangeRequestInput,
  type SectionChangeRequest,
  type SectionChangeRequestStatus,
} from "@/features/schemas/section-change-request-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

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

/** Requests the signed-in Program Head or Registrar Head may see (ADR 0032). */
export async function listSectionChangeRequests(
  status?: SectionChangeRequestStatus,
  signal?: AbortSignal,
): Promise<SectionChangeRequest[]> {
  const query = status ? `?status=${status}` : ""

  return parse(
    sectionChangeRequestsEnvelopeSchema,
    await getAuthenticatedJson(
      `/api/v1/section-change-requests${query}`,
      signal,
    ),
    "section change request list",
  ).data
}

/** Program Head: ask the Registrar Head to change a published section. */
export async function createSectionChangeRequest(
  sectionId: number,
  input: CreateSectionChangeRequestInput,
): Promise<SectionChangeRequest> {
  const parsed = parse(
    createSectionChangeRequestInputSchema,
    input,
    "section change request",
  )

  return parse(
    sectionChangeRequestEnvelopeSchema,
    await postAuthenticatedJson(
      `/api/v1/sections/${sectionId}/change-requests`,
      parsed,
    ),
    "section change request",
  ).data
}

/** Registrar Head approves or rejects; the Program Head who filed it may cancel. */
export async function decideSectionChangeRequest(
  requestId: number,
  input: DecideSectionChangeRequestInput,
): Promise<SectionChangeRequest> {
  const parsed = parse(
    decideSectionChangeRequestInputSchema,
    input,
    "section change decision",
  )

  return parse(
    sectionChangeRequestEnvelopeSchema,
    await patchAuthenticatedJson(
      `/api/v1/section-change-requests/${requestId}`,
      parsed,
    ),
    "section change request",
  ).data
}
