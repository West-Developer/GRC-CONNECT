"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  cutOffQueueCycle,
  getQueueCycle,
  resumeQueueCycle,
} from "@/features/services/queue-cycle-service"

export const queueCycleQueryKey = (userId: string | null) =>
  ["queue-cycle", userId] as const

/**
 * Polls at the same 5s cadence as `useQueueTicketsQuery` on the same
 * screen — cut-off/resume is a Cashier action another window or another
 * accounting staff member may take, and the cut-off banner needs to
 * reflect that without a manual reload.
 */
export function useQueueCycleQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: queueCycleQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getQueueCycle(signal),
    enabled: enabled && session !== null,
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: "always",
  })
}

export function useCutOffQueueMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: cutOffQueueCycle,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queueCycleQueryKey(session?.userId ?? null),
      })
      void queryClient.invalidateQueries({
        queryKey: ["queue-tickets", session?.userId ?? null],
      })
    },
  })
}

export function useResumeQueueMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: resumeQueueCycle,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queueCycleQueryKey(session?.userId ?? null),
      })
      void queryClient.invalidateQueries({
        queryKey: ["queue-tickets", session?.userId ?? null],
      })
    },
  })
}
