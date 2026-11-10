"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import {
  claimQueueTicket,
  type KioskClaimCredentials,
} from "@/features/services/queue-ticket-service"
import { getStudentQueueView } from "@/features/services/student-queue-service"

export const studentQueueQueryKey = (viewerId: string | null) =>
  ["student-queue", viewerId] as const

interface StudentQueueQueryOptions {
  viewerId: string | null
  enabled: boolean
  token?: string
}

/**
 * Polls the Student-visible queue every three seconds, including while the
 * document is hidden. Browser and OS power-saving policies can still throttle
 * or pause timers, so a live update is not a hard real-time guarantee.
 */
export function useStudentQueueQuery({
  viewerId,
  enabled,
  token,
}: StudentQueueQueryOptions) {
  return useQuery({
    queryKey: studentQueueQueryKey(viewerId),
    queryFn: ({ signal }) => getStudentQueueView(signal, token),
    enabled: enabled && viewerId !== null,
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    refetchOnWindowFocus: "always",
  })
}

interface KioskQueueClaimMutationOptions extends KioskClaimCredentials {
  viewerId: string
}

export function useKioskQueueClaimMutation({
  viewerId,
  studentToken,
  kioskToken,
}: KioskQueueClaimMutationOptions) {
  const queryClient = useQueryClient()
  const credentials = { studentToken, kioskToken }

  return useMutation({
    mutationFn: (variables?: { signal?: AbortSignal }) =>
      claimQueueTicket(undefined, credentials, variables?.signal),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: studentQueueQueryKey(viewerId),
        exact: true,
      }),
  })
}
