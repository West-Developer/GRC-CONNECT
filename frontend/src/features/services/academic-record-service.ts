import {
  academicRecordEnvelopeSchema,
  academicRecordStudentLookupEnvelopeSchema,
  gradeSlipEnvelopeSchema,
  prospectusEnvelopeSchema,
  type AcademicRecord,
  type AcademicRecordStudentLookup,
  type GradeSlip,
  type Prospectus,
} from "@/features/schemas/academic-record-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const PROSPECTUS_PATH = "/api/v1/prospectus"
export const GRADE_SLIP_PATH = "/api/v1/grade-slip"
export const ACADEMIC_RECORD_PATH = "/api/v1/academic-record"
export const ACADEMIC_RECORD_STUDENTS_PATH = "/api/v1/academic-record/students"

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

/**
 * `studentId` omitted means "my own" — the Registrar workspaces supply it
 * explicitly to view another student's; the student workspace never does.
 */
export async function getProspectus(
  studentId?: number,
  signal?: AbortSignal,
): Promise<Prospectus> {
  const query = studentId !== undefined ? `?student_id=${studentId}` : ""
  const payload = await getAuthenticatedJson(
    `${PROSPECTUS_PATH}${query}`,
    signal,
  )
  return parse(prospectusEnvelopeSchema, payload, "prospectus").data
}

export async function getGradeSlip(
  academicTermId: number,
  studentId?: number,
  signal?: AbortSignal,
): Promise<GradeSlip> {
  const query = new URLSearchParams({
    academic_term_id: String(academicTermId),
  })
  if (studentId !== undefined) query.set("student_id", String(studentId))

  const payload = await getAuthenticatedJson(
    `${GRADE_SLIP_PATH}?${query.toString()}`,
    signal,
  )
  return parse(gradeSlipEnvelopeSchema, payload, "grade slip").data
}

/**
 * The student Grades screen's read model: every term the student has a
 * grade in, latest term first. `studentId` omitted means "my own" — same
 * convention as `getProspectus`.
 */
export async function getAcademicRecord(
  studentId?: number,
  signal?: AbortSignal,
): Promise<AcademicRecord> {
  const query = studentId !== undefined ? `?student_id=${studentId}` : ""
  const payload = await getAuthenticatedJson(
    `${ACADEMIC_RECORD_PATH}${query}`,
    signal,
  )
  return parse(academicRecordEnvelopeSchema, payload, "academic record").data
}

export async function searchAcademicRecordStudents(
  params: {
    search: string
    by?: "all" | "student_number" | "name"
    limit?: number
  },
  signal?: AbortSignal,
): Promise<AcademicRecordStudentLookup[]> {
  const query = new URLSearchParams({ search: params.search.trim() })
  if (params.by) query.set("by", params.by)
  if (params.limit) query.set("limit", String(params.limit))

  const payload = await getAuthenticatedJson(
    `${ACADEMIC_RECORD_STUDENTS_PATH}?${query.toString()}`,
    signal,
  )
  return parse(
    academicRecordStudentLookupEnvelopeSchema,
    payload,
    "academic record student lookup",
  ).data
}
