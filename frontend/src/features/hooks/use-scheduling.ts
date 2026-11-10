"use client"

import { useQuery } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import { getScheduleProposals, getScheduleReviewSections } from "@/features/services/scheduling-service"

export const scheduleProposalsQueryKey = (userId: string | null) =>
  ["schedule-proposals", userId] as const
export const scheduleReviewSectionsQueryKey = (userId: string | null, proposalId: number | null) =>
  ["schedule-review-sections", userId, proposalId] as const
export function useScheduleProposalsQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()
  return useQuery({
    queryKey: scheduleProposalsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getScheduleProposals(signal),
    enabled: enabled && session !== null,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: "always",
  })
}

export function useScheduleReviewSectionsQuery(proposalId: number | null) {
  const { session } = useAuth()
  return useQuery({
    queryKey: scheduleReviewSectionsQueryKey(session?.userId ?? null, proposalId),
    queryFn: ({ signal }) => getScheduleReviewSections(proposalId!, signal),
    enabled: session !== null && proposalId !== null,
  })
}
