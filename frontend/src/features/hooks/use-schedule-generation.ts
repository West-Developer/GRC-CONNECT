"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getLatestScheduleGenerationRun } from "@/features/services/schedule-generation-service"

export const latestScheduleGenerationRunQueryKey = (
  userId: string | null,
  academicTermId: number,
) => ["schedule-generation-run", userId, academicTermId, "latest"] as const

/**
 * Keeps the latest college-scoped forecast in the shared query cache so a
 * Program Chair can leave Enrollment and return without losing the ready
 * Demand Forecast action while a background refresh is happening.
 */
export function useLatestScheduleGenerationRunQuery(
  academicTermId: number,
  enabled = true,
) {
  const { session } = useAuth()
  const userId = session?.userId ?? null

  return useQuery({
    queryKey: latestScheduleGenerationRunQueryKey(userId, academicTermId),
    queryFn: () => getLatestScheduleGenerationRun(academicTermId),
    enabled: enabled && session !== null && academicTermId > 0,
  })
}
