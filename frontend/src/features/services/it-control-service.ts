import {
  facultyAccountFiltersSchema,
  changeItControlPasswordSchema,
  itControlAccountDeletedResponseSchema,
  itControlAccountResponseSchema,
  paginatedItControlFacultyAccountsSchema,
  paginatedItControlStudentAccountsSchema,
  studentAccountFiltersSchema,
  updateItControlAccountSchema,
  type FacultyAccountFilters,
  type ChangeItControlPasswordInput,
  type ItControlAccount,
  type PaginatedItControlFacultyAccounts,
  type PaginatedItControlStudentAccounts,
  type StudentAccountFilters,
  type UpdateItControlAccountInput,
} from "@/features/schemas/it-control-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const IT_CONTROL_STUDENTS_PATH = "/api/v1/it-control/students"
export const IT_CONTROL_FACULTY_PATH = "/api/v1/it-control/faculty"
export const IT_CONTROL_ACCOUNTS_PATH = "/api/v1/it-control/accounts"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  label: string,
): T {
  const result = schema.safeParse(payload)
  if (result.success) return result.data

  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

function queryString(filters: Record<string, string | number | undefined>) {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined) query.set(key, String(value))
  }

  return query.toString()
}

export async function getItControlStudentAccounts(
  filters: StudentAccountFilters,
  signal?: AbortSignal,
): Promise<PaginatedItControlStudentAccounts> {
  const parsed = parse(studentAccountFiltersSchema, filters, "student filters")
  const query = queryString(parsed)

  return parse(
    paginatedItControlStudentAccountsSchema,
    await getAuthenticatedJson(`${IT_CONTROL_STUDENTS_PATH}?${query}`, signal),
    "student account list",
  )
}

export async function getItControlFacultyAccounts(
  filters: FacultyAccountFilters,
  signal?: AbortSignal,
): Promise<PaginatedItControlFacultyAccounts> {
  const parsed = parse(facultyAccountFiltersSchema, filters, "faculty filters")
  const query = queryString(parsed)

  return parse(
    paginatedItControlFacultyAccountsSchema,
    await getAuthenticatedJson(`${IT_CONTROL_FACULTY_PATH}?${query}`, signal),
    "faculty account list",
  )
}

/** Edits the name parts and email of a Student or Faculty account. `id` is the account (user) id. */
export async function updateItControlAccount(
  id: number,
  input: UpdateItControlAccountInput,
): Promise<ItControlAccount> {
  const request = parse(
    updateItControlAccountSchema,
    input,
    "account change request",
  )

  return parse(
    itControlAccountResponseSchema,
    await patchAuthenticatedJson(`${IT_CONTROL_ACCOUNTS_PATH}/${id}`, request),
    "account",
  ).data
}

export async function archiveItControlAccount(
  id: number,
): Promise<ItControlAccount> {
  return parse(
    itControlAccountResponseSchema,
    await postAuthenticatedJson(`${IT_CONTROL_ACCOUNTS_PATH}/${id}/archive`),
    "archived account",
  ).data
}

export async function changeItControlPassword(
  id: number,
  input: ChangeItControlPasswordInput,
): Promise<ItControlAccount> {
  const request = parse(
    changeItControlPasswordSchema,
    input,
    "password change request",
  )

  return parse(
    itControlAccountResponseSchema,
    await postAuthenticatedJson(
      `${IT_CONTROL_ACCOUNTS_PATH}/${id}/password`,
      request,
    ),
    "account",
  ).data
}

export async function restoreItControlAccount(
  id: number,
): Promise<ItControlAccount> {
  return parse(
    itControlAccountResponseSchema,
    await postAuthenticatedJson(`${IT_CONTROL_ACCOUNTS_PATH}/${id}/restore`),
    "restored account",
  ).data
}

/** Permanently deletes an account that was never used; the server refuses one with records (archive it instead). */
export async function deleteItControlAccount(id: number): Promise<void> {
  parse(
    itControlAccountDeletedResponseSchema,
    await deleteAuthenticatedJson(`${IT_CONTROL_ACCOUNTS_PATH}/${id}`),
    "deleted account",
  )
}
