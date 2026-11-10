import {
  sectionPlanCountsSchema,
  sectionPlansAutoAssignEnvelopeSchema,
  sectionPlansEnvelopeSchema,
  type SectionPlan,
  type SectionPlanCounts,
} from "@/features/schemas/section-plan-schema"
import { getAuthenticatedJson, patchAuthenticatedJson, postAuthenticatedJson } from "@/features/services/api-client"
import { z } from "zod"

const path = "/api/v1/academic-term-section-plans"
const parseEnvelope = (payload: unknown) => sectionPlansEnvelopeSchema.parse(payload).data
// The auto-assign endpoint adds a sibling `meta` key alongside `data` (see
// `sectionPlansAutoAssignEnvelopeSchema`) that `parseEnvelope`'s `.strict()`
// schema would reject, so it gets its own parse helper.
const parseAutoAssignEnvelope = (payload: unknown) => sectionPlansAutoAssignEnvelopeSchema.parse(payload).data

export async function getSectionPlans(termId: number, curriculumId?: number, signal?: AbortSignal): Promise<readonly SectionPlan[]> {
  const curriculumQuery = curriculumId ? `&curriculum_id=${curriculumId}` : ""
  const payload = await getAuthenticatedJson(`${path}?academic_term_id=${termId}${curriculumQuery}`, signal)
  return parseEnvelope(payload)
}

export async function saveSectionPlan(input: SectionPlanCounts): Promise<readonly SectionPlan[]> {
  const payload = await patchAuthenticatedJson(`/api/v1/academic-terms/${input.academic_term_id}/section-plan`, sectionPlanCountsSchema.parse(input))
  return parseEnvelope(payload)
}

export async function releaseSectionPlan(termId: number, curriculumId: number, yearLevel?: number): Promise<readonly SectionPlan[]> {
  const payload = await postAuthenticatedJson(`/api/v1/academic-terms/${termId}/section-plan/release`, { curriculum_id: curriculumId, ...(yearLevel ? { year_level: yearLevel } : {}) })
  return parseEnvelope(payload)
}

export async function autoAssignSectionSchedule(termId: number, curriculumId: number, yearLevel?: number): Promise<readonly SectionPlan[]> {
  const payload = await postAuthenticatedJson(`/api/v1/academic-terms/${termId}/section-plan/auto-assign`, { curriculum_id: curriculumId, ...(yearLevel ? { year_level: yearLevel } : {}) })
  return parseAutoAssignEnvelope(payload)
}

export async function submitSectionPlan(termId: number, curriculumId: number): Promise<{ id: number; status: string }> {
  const payload = await postAuthenticatedJson(`/api/v1/academic-terms/${termId}/section-plan/submit`, { curriculum_id: curriculumId })
  return z.object({ data: z.object({ id: z.number(), status: z.string() }).passthrough() }).parse(payload).data
}
