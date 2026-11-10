import {
  classRosterFiltersSchema,
  paginatedClassRosterSchema,
  type ClassRosterEntry,
  type ClassRosterFilters,
  type Paginated,
} from "@/features/schemas/class-roster-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const CLASS_ROSTERS_PATH = "/api/v1/class-rosters"

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

export async function listClassRoster(
  filters: ClassRosterFilters,
  signal?: AbortSignal,
): Promise<Paginated<ClassRosterEntry>> {
  const parsed = parse(classRosterFiltersSchema, filters, "class roster filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedClassRosterSchema,
    await getAuthenticatedJson(
      `${CLASS_ROSTERS_PATH}?${query.toString()}`,
      signal,
    ),
    "class roster list",
  )
}
