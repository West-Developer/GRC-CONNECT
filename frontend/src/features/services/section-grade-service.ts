import {
  gradeSectionSummariesEnvelopeSchema,
  saveSectionGradeDraftsInputSchema,
  sectionGradeSheetEnvelopeSchema,
  type GradeSectionSummary,
  type SaveSectionGradeDraftsInput,
  type SectionGradeSheet,
} from "@/features/schemas/section-grade-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const GRADE_SUBMISSION_SECTIONS_PATH =
  "/api/v1/sections/grade-submission"

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

export async function listGradeSubmissionSections(
  signal?: AbortSignal,
): Promise<readonly GradeSectionSummary[]> {
  const payload = await getAuthenticatedJson(
    GRADE_SUBMISSION_SECTIONS_PATH,
    signal,
  )
  return parse(
    gradeSectionSummariesEnvelopeSchema,
    payload,
    "grade-section list",
  ).data
}

export async function getSectionGradeSheet(
  sectionId: number,
  signal?: AbortSignal,
): Promise<SectionGradeSheet> {
  const payload = await getAuthenticatedJson(
    `/api/v1/sections/${sectionId}/grades`,
    signal,
  )
  return parse(sectionGradeSheetEnvelopeSchema, payload, "section grade sheet")
    .data
}

export async function saveSectionGradeDrafts(
  sectionId: number,
  input: SaveSectionGradeDraftsInput,
): Promise<SectionGradeSheet> {
  const payload = await postAuthenticatedJson(
    `/api/v1/sections/${sectionId}/grades`,
    parse(saveSectionGradeDraftsInputSchema, input, "grade draft request"),
  )
  return parse(sectionGradeSheetEnvelopeSchema, payload, "saved grade sheet")
    .data
}

export async function submitSectionGrades(
  sectionId: number,
): Promise<SectionGradeSheet> {
  const payload = await postAuthenticatedJson(
    `/api/v1/sections/${sectionId}/grades/submit`,
    {},
  )
  return parse(
    sectionGradeSheetEnvelopeSchema,
    payload,
    "submitted grade sheet",
  ).data
}
