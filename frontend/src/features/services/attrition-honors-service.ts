import {
  type AttritionReport,
  attritionReportEnvelopeSchema,
  type HonorsReport,
  honorsReportEnvelopeSchema,
} from "@/features/schemas/attrition-honors-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const ATTRITION_REPORT_PATH = "/api/v1/analytics/attrition"
export const HONORS_REPORT_PATH = "/api/v1/reports/honors"

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

export async function getAttritionReport(
  input: {
    baselineAcademicTermId: number
    comparisonAcademicTermId: number
    college?: string
    programId?: number
    yearLevel?: number
  },
  signal?: AbortSignal,
): Promise<AttritionReport> {
  const params = new URLSearchParams({
    baseline_academic_term_id: String(input.baselineAcademicTermId),
    comparison_academic_term_id: String(input.comparisonAcademicTermId),
  })
  if (input.college) params.set("college", input.college)
  if (input.programId) params.set("program_id", String(input.programId))
  if (input.yearLevel) params.set("year_level", String(input.yearLevel))
  return parse(
    attritionReportEnvelopeSchema,
    await getAuthenticatedJson(`${ATTRITION_REPORT_PATH}?${params}`, signal),
    "attrition report",
  ).data
}

export async function getHonorsReport(
  input: {
    academicTermId: number
    college?: string
    programId?: number
    yearLevel?: number
    page?: number
    pageSize?: number
  },
  signal?: AbortSignal,
): Promise<HonorsReport> {
  const params = new URLSearchParams({
    academic_term_id: String(input.academicTermId),
    page: String(input.page ?? 1),
    page_size: String(input.pageSize ?? 25),
  })
  if (input.college) params.set("college", input.college)
  if (input.programId) params.set("program_id", String(input.programId))
  if (input.yearLevel) params.set("year_level", String(input.yearLevel))
  return parse(
    honorsReportEnvelopeSchema,
    await getAuthenticatedJson(`${HONORS_REPORT_PATH}?${params}`, signal),
    "honors report",
  )
}
