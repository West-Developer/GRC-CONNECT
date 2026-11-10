import {
  subjectWithdrawalOverviewSchema,
  withdrawSubjectsInputSchema,
  type SubjectWithdrawalOverview,
  type WithdrawSubjectsInput,
} from "@/features/schemas/subject-withdrawal-schema"
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

/** The Registrar Head's view of one student's subjects: withdrawable and already withdrawn. */
export async function getSubjectWithdrawalOverview(
  studentId: number,
  signal?: AbortSignal,
): Promise<SubjectWithdrawalOverview> {
  return parse(
    subjectWithdrawalOverviewSchema,
    await getAuthenticatedJson(
      `/api/v1/students/${studentId}/subject-withdrawals`,
      signal,
    ),
    "subject withdrawal list",
  ).data
}

/** Withdraws the chosen subjects; the response is the overview after the withdrawal. */
export async function withdrawStudentSubjects(
  studentId: number,
  input: WithdrawSubjectsInput,
): Promise<SubjectWithdrawalOverview> {
  const parsed = parse(
    withdrawSubjectsInputSchema,
    input,
    "subject withdrawal request",
  )

  return parse(
    subjectWithdrawalOverviewSchema,
    await postAuthenticatedJson(
      `/api/v1/students/${studentId}/subject-withdrawals`,
      parsed,
    ),
    "subject withdrawal",
  ).data
}
