import {
  recordStudentAccountPaymentInputSchema,
  studentAccountEnvelopeSchema,
  type RecordStudentAccountPaymentInput,
  type StudentAccount,
} from "@/features/schemas/student-account-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const OWN_STUDENT_ACCOUNT_PATH = "/api/v1/student-account"

function studentAccountPath(studentId: number): string {
  return `/api/v1/students/${studentId}/account`
}

function parseAccount(value: unknown, label: string): StudentAccount {
  const result = studentAccountEnvelopeSchema.safeParse(value)
  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function getOwnStudentAccount(
  signal?: AbortSignal,
): Promise<StudentAccount> {
  return parseAccount(
    await getAuthenticatedJson(OWN_STUDENT_ACCOUNT_PATH, signal),
    "student account",
  )
}

export async function getStudentAccount(
  studentId: number,
  signal?: AbortSignal,
): Promise<StudentAccount> {
  return parseAccount(
    await getAuthenticatedJson(studentAccountPath(studentId), signal),
    "student account",
  )
}

export async function recordStudentAccountPayment(
  studentId: number,
  input: RecordStudentAccountPaymentInput,
): Promise<StudentAccount> {
  return parseAccount(
    await postAuthenticatedJson(
      `${studentAccountPath(studentId)}-payments`,
      recordStudentAccountPaymentInputSchema.parse(input),
    ),
    "recorded account payment",
  )
}
