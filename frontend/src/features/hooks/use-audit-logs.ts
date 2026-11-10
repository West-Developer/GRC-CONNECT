"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import {
  type AuditActorFilters,
  type AuditLogFilters,
} from "@/features/schemas/audit-schema"
import { getAuditActors, getAuditLogs } from "@/features/services/audit-service"

export const auditLogsQueryKey = (
  userId: string | null,
  filters: AuditLogFilters,
) => ["audit-logs", userId, filters] as const

export function useAuditLogsQuery(filters: AuditLogFilters, enabled = true) {
  const { session } = useAuth()
  return useQuery({
    queryKey: auditLogsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => getAuditLogs(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

export const auditActorsQueryKey = (
  userId: string | null,
  filters: AuditActorFilters,
) => ["audit-logs", "actors", userId, filters] as const

export function useAuditActorsQuery(
  filters: AuditActorFilters,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: auditActorsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => getAuditActors(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}
