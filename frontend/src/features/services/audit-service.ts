import {
  auditActorFiltersSchema,
  auditLogFiltersSchema,
  paginatedAuditActorsSchema,
  paginatedAuditLogsSchema,
  type AuditActor,
  type AuditActorFilters,
  type AuditLog,
  type AuditLogFilters,
  type Paginated,
} from "@/features/schemas/audit-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const AUDIT_LOGS_PATH = "/api/v1/audit-logs"
export const AUDIT_ACTORS_PATH = "/api/v1/audit-logs/actors"

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

export async function getAuditLogs(
  filters: AuditLogFilters,
  signal?: AbortSignal,
): Promise<Paginated<AuditLog>> {
  const parsed = parse(auditLogFiltersSchema, filters, "audit filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedAuditLogsSchema,
    await getAuthenticatedJson(
      `${AUDIT_LOGS_PATH}?${query.toString()}`,
      signal,
    ),
    "audit log list",
  )
}

/** Who has audit activity, one row per user (Registrar Head only). */
export async function getAuditActors(
  filters: AuditActorFilters,
  signal?: AbortSignal,
): Promise<Paginated<AuditActor>> {
  const parsed = parse(auditActorFiltersSchema, filters, "audit filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedAuditActorsSchema,
    await getAuthenticatedJson(
      `${AUDIT_ACTORS_PATH}?${query.toString()}`,
      signal,
    ),
    "audit user list",
  )
}
