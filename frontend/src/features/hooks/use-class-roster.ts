"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { ClassRosterFilters } from "@/features/schemas/class-roster-schema"
import { listClassRoster } from "@/features/services/class-roster-service"

export const classRosterQueryKey = (
  userId: string | null,
  filters: ClassRosterFilters,
) => ["class-roster", userId, filters] as const

export function useClassRosterQuery(
  filters: ClassRosterFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: classRosterQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listClassRoster(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}
