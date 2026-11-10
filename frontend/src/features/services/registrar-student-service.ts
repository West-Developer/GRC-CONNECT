import {
  paginatedRegistrarStudentsSchema,
  registrarStudentFiltersSchema,
  type PaginatedRegistrarStudents,
  type RegistrarStudentFilters,
} from "@/features/schemas/registrar-student-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const REGISTRAR_STUDENTS_PATH = "/api/v1/students"

/** The Registrar's read-only list of every student, searchable by student number or name. */
export async function listRegistrarStudents(
  filters: RegistrarStudentFilters,
  signal?: AbortSignal,
): Promise<PaginatedRegistrarStudents> {
  const filterResult = registrarStudentFiltersSchema.safeParse(filters)
  if (!filterResult.success) {
    throw new ApiClientError({
      kind: "contract",
      message: "The student list filter is not valid.",
      cause: filterResult.error,
    })
  }
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(filterResult.data)) {
    if (value !== undefined && value !== "") query.set(key, String(value))
  }

  const result = paginatedRegistrarStudentsSchema.safeParse(
    await getAuthenticatedJson(
      `${REGISTRAR_STUDENTS_PATH}?${query.toString()}`,
      signal,
    ),
  )
  if (result.success) return result.data
  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its student list did not match the published v1 contract.",
    cause: result.error,
  })
}
