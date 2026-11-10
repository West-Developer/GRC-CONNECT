import {
  type GraduateListResponse,
  graduateListResponseSchema,
} from "@/features/schemas/graduate-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const GRADUATES_PATH = "/api/v1/graduates"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  label: string,
): T {
  const result = schema.safeParse(payload)
  if (result.success) return result.data
  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function getGraduates(
  input: {
    programId?: number | null
    graduationSchoolYear?: string | null
    curriculumId?: number | null
    search?: string | null
    page?: number
    perPage?: number
  } = {},
  signal?: AbortSignal,
): Promise<GraduateListResponse> {
  const params = new URLSearchParams()
  if (input.programId) params.set("program_id", String(input.programId))
  if (input.graduationSchoolYear) params.set("graduation_school_year", input.graduationSchoolYear)
  if (input.curriculumId) params.set("curriculum_id", String(input.curriculumId))
  if (input.search) params.set("search", input.search)
  if (input.page) params.set("page", String(input.page))
  if (input.perPage) params.set("per_page", String(input.perPage))

  const queryString = params.toString()
  const url = queryString ? `${GRADUATES_PATH}?${queryString}` : GRADUATES_PATH

  return parse(
    graduateListResponseSchema,
    await getAuthenticatedJson(url, signal),
    "graduates list",
  )
}

