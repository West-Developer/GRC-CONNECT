import type { Curriculum } from "@/features/schemas/reference-data-schema"
import { z } from "zod"
import {
  storeCurriculumInputSchema,
  curriculumReplacementSchema,
  curriculumSubjectPlacementInputSchema,
  curriculumTransitionSchema,
  type CurriculumEditorValues,
  type CurriculumSubjectPlacementInput,
  type CurriculumTransition,
  type StoreCurriculumInput,
  type UpdateCurriculumInput,
} from "@/features/schemas/curriculum-schema"
import {
  curriculumSchema,
  subjectsEnvelopeSchema,
  type Subject,
} from "@/features/schemas/reference-data-schema"
import {
  curriculumMigrationPreviewEnvelopeSchema,
  curriculumMigrationResultEnvelopeSchema,
  type CurriculumMigrationPreview,
  type CurriculumMigrationResult,
} from "@/features/schemas/curriculum-migration-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"

export const CURRICULA_PATH = "/api/v1/curricula"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const parsed = schema.safeParse(value)
  if (parsed.success) return parsed.data
  throw new ApiClientError({
    kind: "contract",
    message: `The ${label} did not match the published v1 contract.`,
    cause: parsed.error,
  })
}

export function toCurriculumReplacement(
  values: CurriculumEditorValues,
): UpdateCurriculumInput {
  return parse(
    curriculumReplacementSchema,
    {
      name: values.name,
      subjects: values.subjects.map((subject) => ({
        subject_id: subject.subject_id,
        year_level: subject.year_level,
        semester: subject.semester,
        is_required: subject.is_required,
        prerequisites: subject.prerequisites.map((edge) => ({
          prerequisite_subject_id: edge.prerequisite_subject_id,
          minimum_grade: edge.minimum_grade,
        })),
      })),
    },
    "curriculum replacement",
  )
}

export async function createCurriculum(
  input: StoreCurriculumInput,
): Promise<Curriculum> {
  const payload = await postAuthenticatedJson(
    CURRICULA_PATH,
    parse(storeCurriculumInputSchema, input, "curriculum create request"),
  )
  return parse(zEnvelope, payload, "created curriculum").data
}

export async function replaceCurriculum(
  id: number,
  input: UpdateCurriculumInput,
): Promise<Curriculum> {
  const payload = await patchAuthenticatedJson(
    `${CURRICULA_PATH}/${id}`,
    parse(curriculumReplacementSchema, input, "curriculum replacement request"),
  )
  return parse(zEnvelope, payload, "updated curriculum").data
}

export async function transitionCurriculum(
  id: number,
  transition: CurriculumTransition,
): Promise<Curriculum> {
  const payload = await patchAuthenticatedJson(
    `${CURRICULA_PATH}/${id}/transition`,
    parse(
      curriculumTransitionSchema,
      transition,
      "curriculum transition request",
    ),
  )
  return parse(zEnvelope, payload, "transitioned curriculum").data
}

export async function getCurrentCurriculumSubjects(
  programId: number,
  signal?: AbortSignal,
): Promise<readonly Subject[]> {
  const payload = await getAuthenticatedJson(
    `/api/v1/programs/${programId}/current-curriculum-subjects`,
    signal,
  )
  return parse(subjectsEnvelopeSchema, payload, "current curriculum subjects")
    .data
}

export async function addCurriculumSubjectPlacement(
  curriculumId: number,
  input: CurriculumSubjectPlacementInput,
): Promise<Curriculum> {
  const payload = await postAuthenticatedJson(
    `${CURRICULA_PATH}/${curriculumId}/subject-placements`,
    parse(
      curriculumSubjectPlacementInputSchema,
      input,
      "curriculum subject placement request",
    ),
  )
  return parse(zEnvelope, payload, "updated curriculum").data
}

export async function previewCurriculumMigration(
  curriculumId: number,
  studentNumber: string,
  signal?: AbortSignal,
): Promise<CurriculumMigrationPreview> {
  const query = new URLSearchParams({ student_number: studentNumber })
  const payload = await getAuthenticatedJson(
    `${CURRICULA_PATH}/${curriculumId}/migration-preview?${query.toString()}`,
    signal,
  )
  return parse(
    curriculumMigrationPreviewEnvelopeSchema,
    payload,
    "curriculum migration preview",
  ).data
}

export async function applyCurriculumMigration(
  curriculumId: number,
  input: { student_id: number; equivalency_ids: readonly number[] },
): Promise<CurriculumMigrationResult> {
  const payload = await postAuthenticatedJson(
    `${CURRICULA_PATH}/${curriculumId}/migrations`,
    { student_id: input.student_id, equivalency_ids: input.equivalency_ids },
  )
  return parse(
    curriculumMigrationResultEnvelopeSchema,
    payload,
    "curriculum migration result",
  ).data
}

export async function updateCurriculumMaxUnits(
  curriculumId: number,
  maxUnits: number | null,
): Promise<Curriculum> {
  const payload = await putAuthenticatedJson(
    `${CURRICULA_PATH}/${curriculumId}/max-units`,
    { max_units: maxUnits },
  )
  return parse(zEnvelope, payload, "updated curriculum").data
}

const curriculumEnvelopeSchema = z.object({ data: curriculumSchema }).strict()

const zEnvelope = {
  safeParse: (payload: unknown) => curriculumEnvelopeSchema.safeParse(payload),
}
