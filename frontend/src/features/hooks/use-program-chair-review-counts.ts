"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import { getProgramChairReviewCounts } from "@/features/services/program-chair-review-counts-service"

export const programChairReviewCountsQueryKey = (userId: string | null) =>
  ["program-chair-review-counts", userId] as const

/**
 * What is waiting for the Program Chair, refreshed every 30 s so the numbers on the Enrollment tabs
 * follow the work. Only a Program Chair reads it; any other role makes no request.
 */
export function useProgramChairReviewCountsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: programChairReviewCountsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getProgramChairReviewCounts(signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: session?.role === "program_chair",
    refetchInterval: 30_000,
    refetchOnWindowFocus: "always",
  })
}
