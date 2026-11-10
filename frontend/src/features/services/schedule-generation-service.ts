import { z } from "zod"
import {
  facultyLoadReportSchema,
  scheduleGenerationRunEnvelopeSchema,
  type FacultyEmploymentType,
  type FacultyLoadReport,
  type ScheduleGenerationRun,
} from "@/features/schemas/schedule-generation-schema"
import {
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  postAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"

/** Starts a college-scoped, idempotent predictive schedule recommendation run. */
export async function startScheduleGeneration(
  termId: number,
): Promise<ScheduleGenerationRun> {
  const payload = await postAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/schedule-generation-runs`,
    {},
  )

  return scheduleGenerationRunEnvelopeSchema.parse(payload).data
}

export async function getScheduleGenerationRun(
  runId: number,
): Promise<ScheduleGenerationRun> {
  const payload = await getAuthenticatedJson(
    `/api/v1/schedule-generation-runs/${runId}`,
  )
  return scheduleGenerationRunEnvelopeSchema.parse(payload).data
}

export async function getLatestScheduleGenerationRun(
  termId: number,
): Promise<ScheduleGenerationRun | null> {
  const payload = await getAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/schedule-generation-runs/latest`,
  )
  const parsed = scheduleGenerationRunEnvelopeSchema.safeParse(payload)
  if (parsed.success) return parsed.data.data
  if (
    typeof payload === "object" &&
    payload !== null &&
    "data" in payload &&
    (payload as { data?: unknown }).data === null
  )
    return null
  throw new Error("The latest schedule generation response was invalid.")
}

export async function getFacultyLoadReport(
  termId: number,
): Promise<FacultyLoadReport> {
  const payload = await getAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/faculty-load-report`,
  )
  return z.object({ data: facultyLoadReportSchema }).strict().parse(payload)
    .data
}

/** Program Head or Dean: the maximum units for one employment type (ADR 0033). */
export async function saveFacultyLoadLimit(
  termId: number,
  employmentType: FacultyEmploymentType,
  maxUnits: number,
): Promise<number> {
  const payload = await putAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/faculty-load-limits/${employmentType}`,
    { max_units: maxUnits },
  )
  return z
    .object({
      data: z.object({ max_units: z.number().positive() }).passthrough(),
    })
    .parse(payload).data.max_units
}

/** One professor's own maximum for the term, with the reason it is needed. */
export async function saveFacultyLoadOverride(
  termId: number,
  professorId: number,
  input: { max_units: number; reason: string },
): Promise<void> {
  await putAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/faculty-load-overrides/${professorId}`,
    input,
  )
}

export async function clearFacultyLoadOverride(
  termId: number,
  professorId: number,
): Promise<void> {
  await deleteAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/faculty-load-overrides/${professorId}`,
  )
}

export async function saveFacultyLoadThreshold(
  termId: number,
  maxUnits: number,
): Promise<number> {
  const payload = await putAuthenticatedJson(
    `/api/v1/academic-terms/${termId}/faculty-load-threshold`,
    { max_units: maxUnits },
  )
  return z
    .object({ data: z.object({ max_units: z.number().positive() }).strict() })
    .parse(payload).data.max_units
}
