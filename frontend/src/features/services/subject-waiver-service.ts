import {
  grantSubjectWaiverInputSchema,
  subjectWaiverEnvelopeSchema,
  subjectWaiverOverviewSchema,
  type GrantSubjectWaiverInput,
  type SubjectWaiver,
  type SubjectWaiverOverview,
} from "@/features/schemas/subject-waiver-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
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

/** The Registrar Head's view of one student's prerequisite waivers (ADR 0031). */
export async function getSubjectWaiverOverview(
  studentId: number,
  academicTermId: number,
  signal?: AbortSignal,
): Promise<SubjectWaiverOverview> {
  return parse(
    subjectWaiverOverviewSchema,
    await getAuthenticatedJson(
      `/api/v1/students/${studentId}/subject-waivers?academic_term_id=${academicTermId}`,
      signal,
    ),
    "subject waiver list",
  )
}

export async function grantSubjectWaiver(
  studentId: number,
  input: GrantSubjectWaiverInput,
): Promise<SubjectWaiver> {
  const parsed = parse(
    grantSubjectWaiverInputSchema,
    input,
    "subject waiver request",
  )
  const envelope = parse(
    subjectWaiverEnvelopeSchema,
    await postAuthenticatedJson(
      `/api/v1/students/${studentId}/subject-waivers`,
      parsed,
    ),
    "subject waiver",
  )
  return envelope.data
}

export async function revokeSubjectWaiver(
  waiverId: number,
): Promise<SubjectWaiver> {
  const envelope = parse(
    subjectWaiverEnvelopeSchema,
    await deleteAuthenticatedJson(`/api/v1/subject-waivers/${waiverId}`),
    "subject waiver",
  )
  return envelope.data
}
