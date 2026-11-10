import {
  replaceSubjectOfferingsInputSchema,
  subjectOfferingFiltersSchema,
  subjectOfferingsEnvelopeSchema,
  type ReplaceSubjectOfferingsInput,
  type SubjectOffering,
  type SubjectOfferingFilters,
} from "@/features/schemas/subject-offering-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const SUBJECT_OFFERINGS_PATH = "/api/v1/subject-offerings"

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

export async function getSubjectOfferings(
  filters: SubjectOfferingFilters,
  signal?: AbortSignal,
): Promise<readonly SubjectOffering[]> {
  const parsedFilters = parse(
    subjectOfferingFiltersSchema,
    filters,
    "subject offering filter",
  )
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsedFilters)) {
    query.set(key, String(value))
  }
  const payload = await getAuthenticatedJson(
    `${SUBJECT_OFFERINGS_PATH}?${query.toString()}`,
    signal,
  )
  return parse(subjectOfferingsEnvelopeSchema, payload, "subject offerings")
    .data
}

export async function replaceSubjectOfferings(
  input: ReplaceSubjectOfferingsInput,
): Promise<readonly SubjectOffering[]> {
  const payload = await postAuthenticatedJson(
    SUBJECT_OFFERINGS_PATH,
    parse(
      replaceSubjectOfferingsInputSchema,
      input,
      "subject offerings replace request",
    ),
  )
  return parse(
    subjectOfferingsEnvelopeSchema,
    payload,
    "replaced subject offerings",
  ).data
}
