import {
  enrollmentMovementsEnvelopeSchema,
  programShiftResponseSchema,
  recordProgramShiftInputSchema,
  type EnrollmentMovements,
  type EnrollmentMovementType,
  type RecordProgramShiftInput,
} from "@/features/schemas/enrollment-movement-schema"
import {
  ApiClientError,
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

/** Drops, withdrawals, or course shifts for one term (aggregate counts only). */
export async function getEnrollmentMovements(
  academicTermId: number,
  type: EnrollmentMovementType,
  college?: string,
  signal?: AbortSignal,
): Promise<EnrollmentMovements> {
  const params = new URLSearchParams({
    academic_term_id: String(academicTermId),
    type,
  })
  if (college) params.set("college", college)

  return parse(
    enrollmentMovementsEnvelopeSchema,
    await getAuthenticatedJson(
      `/api/v1/analytics/enrollment-movements?${params.toString()}`,
      signal,
    ),
    "enrollment movements report",
  ).data
}

/** Registrar: record that a student shifted to another course (ADR 0034). */
export async function recordProgramShift(
  input: RecordProgramShiftInput,
): Promise<void> {
  const parsed = parse(recordProgramShiftInputSchema, input, "course shift")
  parse(
    programShiftResponseSchema,
    await postAuthenticatedJson("/api/v1/program-shifts", parsed),
    "course shift",
  )
}
