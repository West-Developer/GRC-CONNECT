"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  DecideWithdrawalRequestInput,
  WithdrawalRequestFilters,
} from "@/features/schemas/withdrawal-request-schema"
import {
  createWithdrawalRequest,
  decideWithdrawalRequest,
  listWithdrawalRequests,
} from "@/features/services/withdrawal-request-service"

export const withdrawalRequestsQueryKey = (
  userId: string | null,
  filters: WithdrawalRequestFilters,
) => ["withdrawal-requests", userId, filters] as const

export function useWithdrawalRequestsQuery(
  filters: WithdrawalRequestFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: withdrawalRequestsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listWithdrawalRequests(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

/**
 * Deciding a withdrawal request (approve/reject) can change the underlying
 * enrollment's status (`approve` moves it to `withdrawn`), so both this
 * list and the student's own enrollment view are invalidated together —
 * the same cross-invalidation shape `useUpdateEnrollmentMutation` uses.
 */
function useInvalidateWithdrawalRequestQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["withdrawal-requests", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["enrollments", session?.userId ?? null],
        exact: true,
      }),
    ])
}

export function useCreateWithdrawalRequestMutation() {
  const invalidate = useInvalidateWithdrawalRequestQueries()

  return useMutation({
    mutationFn: ({
      enrollmentId,
      reason,
    }: {
      enrollmentId: number
      reason: string
    }) => createWithdrawalRequest(enrollmentId, { reason }),
    onSuccess: () => invalidate(),
  })
}

export function useDecideWithdrawalRequestMutation() {
  const invalidate = useInvalidateWithdrawalRequestQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: DecideWithdrawalRequestInput
    }) => decideWithdrawalRequest(id, input),
    onSuccess: () => invalidate(),
  })
}
