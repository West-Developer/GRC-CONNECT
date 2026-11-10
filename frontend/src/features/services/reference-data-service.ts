import {
  academicTermsEnvelopeSchema,
  academicTermSchema,
  curriculaEnvelopeSchema,
  programsEnvelopeSchema,
  sectionsEnvelopeSchema,
  subjectsEnvelopeSchema,
  type AcademicTerm,
  type Curriculum,
  type Program,
  type Section,
  type Subject,
} from "@/features/schemas/reference-data-schema"
import {
  archiveAndCreateNextInputSchema,
  storeAcademicTermInputSchema,
  updateDraftAcademicTermIdentityInputSchema,
  type ArchiveAndCreateNextInput,
  type StoreAcademicTermInput,
  type UpdateDraftAcademicTermIdentityInput,
} from "@/features/schemas/academic-term-schema"
import { z } from "zod"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"
import {
  academicTermWorkflowsEnvelopeSchema,
  academicTermWorkflowSchema,
  academicTermWorkflowActionSchema,
  type AcademicTermWorkflow,
  type AcademicTermWorkflowAction,
} from "@/features/schemas/academic-term-workflow-schema"

export const ACADEMIC_TERMS_PATH = "/api/v1/academic-terms"
export const PROGRAMS_PATH = "/api/v1/programs"
export const SUBJECTS_PATH = "/api/v1/subjects"
export const CURRICULA_PATH = "/api/v1/curricula"
export const SECTIONS_PATH = "/api/v1/sections"
export const ACADEMIC_TERM_WORKFLOWS_PATH = "/api/v1/academic-term-workflows"

function parseResponse<T>(
  schema: {
    safeParse: (
      payload: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  resourceName: string,
): T {
  const parsed = schema.safeParse(payload)

  if (!parsed.success) {
    throw new ApiClientError({
      kind: "contract",
      message: `The API responded, but its ${resourceName} payload did not match the published v1 contract.`,
      cause: parsed.error,
    })
  }

  return parsed.data
}

export async function getAcademicTerms(
  signal?: AbortSignal,
): Promise<readonly AcademicTerm[]> {
  const payload = await getAuthenticatedJson(ACADEMIC_TERMS_PATH, signal)
  return parseResponse(academicTermsEnvelopeSchema, payload, "academic terms")
    .data
}

const academicTermEnvelopeSchema = z
  .object({ data: academicTermSchema })
  .strict()

/**
 * Converts each browser `datetime-local` value (no seconds, no timezone
 * suffix) to a full ISO 8601 string before it reaches the API.
 */
export async function createAcademicTerm(
  input: StoreAcademicTermInput,
): Promise<AcademicTerm> {
  const parsedInput = parseResponse(
    storeAcademicTermInputSchema,
    input,
    "academic term create request",
  )
  const payload = await postAuthenticatedJson(ACADEMIC_TERMS_PATH, {
    school_year: parsedInput.school_year,
    semester: parsedInput.semester,
    enrollment_opens_at: new Date(
      parsedInput.enrollment_opens_at,
    ).toISOString(),
    enrollment_closes_at: new Date(
      parsedInput.enrollment_closes_at,
    ).toISOString(),
    add_drop_deadline_at: new Date(
      parsedInput.add_drop_deadline_at,
    ).toISOString(),
  })
  return parseResponse(
    academicTermEnvelopeSchema,
    payload,
    "created academic term",
  ).data
}

export async function updateAcademicTerm(
  academicTermId: number,
  action: "open_enrollment" | "archive",
): Promise<AcademicTerm> {
  const payload = await patchAuthenticatedJson(
    `${ACADEMIC_TERMS_PATH}/${academicTermId}`,
    { action },
  )

  return parseResponse(
    academicTermEnvelopeSchema,
    payload,
    "updated academic term",
  ).data
}

/**
 * Corrects only a Draft term's school year and semester. Enrollment schedule
 * dates remain on their dedicated endpoint and cannot be changed here.
 */
export async function updateDraftAcademicTermIdentity(
  academicTermId: number,
  input: UpdateDraftAcademicTermIdentityInput,
): Promise<AcademicTerm> {
  const parsedInput = parseResponse(
    updateDraftAcademicTermIdentityInputSchema,
    input,
    "draft academic term identity update request",
  )
  const payload = await patchAuthenticatedJson(
    `${ACADEMIC_TERMS_PATH}/${academicTermId}/draft-identity`,
    parsedInput,
  )

  return parseResponse(
    academicTermEnvelopeSchema,
    payload,
    "updated draft academic term",
  ).data
}

/**
 * Archives `academicTermId` and opens `next` as the new current term, in
 * one transaction. Returns the newly created term — the Registrar's next
 * screen is about the new cycle, not the one just retired.
 */
export async function archiveAndCreateNextAcademicTerm(
  academicTermId: number,
  next: ArchiveAndCreateNextInput,
): Promise<AcademicTerm> {
  const parsedInput = parseResponse(
    archiveAndCreateNextInputSchema,
    next,
    "archive-and-create-next request",
  )
  const payload = await postAuthenticatedJson(
    `${ACADEMIC_TERMS_PATH}/${academicTermId}/archive-and-create-next`,
    parsedInput,
  )

  return parseResponse(
    academicTermEnvelopeSchema,
    payload,
    "created academic term",
  ).data
}

export async function getAcademicTermWorkflows(
  academicTermId: number,
  signal?: AbortSignal,
): Promise<readonly AcademicTermWorkflow[]> {
  const payload = await getAuthenticatedJson(
    `${ACADEMIC_TERM_WORKFLOWS_PATH}?academic_term_id=${academicTermId}`,
    signal,
  )

  return parseResponse(
    academicTermWorkflowsEnvelopeSchema,
    payload,
    "academic term workflows",
  ).data
}

export async function updateAcademicTermWorkflow(
  workflowId: number,
  input: AcademicTermWorkflowAction,
): Promise<AcademicTermWorkflow> {
  const parsedInput = parseResponse(
    academicTermWorkflowActionSchema,
    input,
    "academic term workflow action",
  )
  const payload = await patchAuthenticatedJson(
    `${ACADEMIC_TERM_WORKFLOWS_PATH}/${workflowId}`,
    parsedInput,
  )

  return parseResponse(
    z.object({ data: academicTermWorkflowSchema }).strict(),
    payload,
    "updated academic term workflow",
  ).data
}

export async function getPrograms(
  signal?: AbortSignal,
): Promise<readonly Program[]> {
  const payload = await getAuthenticatedJson(PROGRAMS_PATH, signal)
  return parseResponse(programsEnvelopeSchema, payload, "programs").data
}

export async function getSubjects(
  signal?: AbortSignal,
): Promise<readonly Subject[]> {
  const payload = await getAuthenticatedJson(SUBJECTS_PATH, signal)
  return parseResponse(subjectsEnvelopeSchema, payload, "subjects").data
}

export async function getCurricula(
  signal?: AbortSignal,
): Promise<readonly Curriculum[]> {
  const payload = await getAuthenticatedJson(CURRICULA_PATH, signal)
  return parseResponse(curriculaEnvelopeSchema, payload, "curricula").data
}

export async function getSections(
  signal?: AbortSignal,
): Promise<readonly Section[]> {
  const payload = await getAuthenticatedJson(SECTIONS_PATH, signal)
  return parseResponse(sectionsEnvelopeSchema, payload, "sections").data
}

export function getActiveAcademicTerm(
  terms: readonly AcademicTerm[] | undefined,
): AcademicTerm | null {
  return (
    terms?.find(
      (term) =>
        term.is_actionable_current ?? term.status === "semester_ongoing",
    ) ?? null
  )
}

export function formatAcademicTerm(term: AcademicTerm): string {
  return `${term.school_year} · ${term.semester}`
}

/**
 * Computes the next chronological academic term in sequence:
 * - 1st sem -> 2nd sem of same school year
 * - 2nd sem -> 1st sem of next school year
 */
export function getNextAcademicTermSequence(term: {
  school_year: string
  semester: string
}): { school_year: string; semester: "1st" | "2nd" } {
  if (term.semester === "1st") {
    return {
      school_year: term.school_year,
      semester: "2nd",
    }
  }

  const match = /^(\d{4})-(\d{4})$/.exec(term.school_year.trim())
  if (match) {
    const nextStart = parseInt(match[1], 10) + 1
    const nextEnd = parseInt(match[2], 10) + 1
    return {
      school_year: `${nextStart}-${nextEnd}`,
      semester: "1st",
    }
  }

  return {
    school_year: term.school_year,
    semester: "1st",
  }
}
