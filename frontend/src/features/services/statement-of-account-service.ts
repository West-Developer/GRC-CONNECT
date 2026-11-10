import {
  statementOfAccountEnvelopeSchema,
  type StatementOfAccount,
} from "@/features/schemas/statement-of-account-schema"
import {
  ApiClientError,
  getAuthenticatedBlob,
  getAuthenticatedJson,
} from "@/features/services/api-client"

function basePath(studentId: number | null): string {
  return studentId === null
    ? "/api/v1/me/statement-of-account"
    : `/api/v1/students/${studentId}/statement-of-account`
}

function withTerm(path: string, academicTermId: number | null): string {
  return academicTermId === null
    ? path
    : `${path}?academic_term_id=${academicTermId}`
}

/**
 * A student's Statement of Account. `studentId` null reads the signed-in
 * Student's own; a number is the served Student, for Accounting Staff.
 */
export async function getStatementOfAccount(
  studentId: number | null,
  academicTermId: number | null,
  signal?: AbortSignal,
): Promise<StatementOfAccount> {
  const result = statementOfAccountEnvelopeSchema.safeParse(
    await getAuthenticatedJson(
      withTerm(basePath(studentId), academicTermId),
      signal,
    ),
  )
  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its statement of account did not match the published v1 contract.",
    cause: result.error,
  })
}

/** Downloads the printable PDF of the same statement. */
export async function downloadStatementOfAccountPdf(
  studentId: number | null,
  academicTermId: number | null,
  studentNumber: string,
  signal?: AbortSignal,
): Promise<void> {
  const blob = await getAuthenticatedBlob(
    withTerm(`${basePath(studentId)}/pdf`, academicTermId),
    signal,
  )
  const url = window.URL.createObjectURL(blob)
  const anchor = document.createElement("a")
  anchor.href = url
  anchor.download = `SOA-${studentNumber.replace(/[^a-zA-Z0-9_-]/g, "_")}.pdf`
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  window.URL.revokeObjectURL(url)
}
