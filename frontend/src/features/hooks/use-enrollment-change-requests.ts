"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  EnrollmentChangeRequestFilters,
  StoreEnrollmentChangeRequestInput,
  UpdateEnrollmentChangeRequestInput,
} from "@/features/schemas/enrollment-change-request-schema"
import {
  createEnrollmentChangeRequest,
  listEnrollmentChangeRequests,
  updateEnrollmentChangeRequest,
} from "@/features/services/enrollment-change-request-service"

export const enrollmentChangeRequestsQueryKey = (
  userId: string | null,
  filters: EnrollmentChangeRequestFilters,
) => ["enrollment-change-requests", userId, filters] as const

export function useEnrollmentChangeRequestsQuery(
  filters: EnrollmentChangeRequestFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentChangeRequestsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listEnrollmentChangeRequests(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

function useInvalidateEnrollmentChangeRequestQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["enrollment-change-requests", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["enrollments", session?.userId ?? null],
      }),
    ])
}

export function useCreateEnrollmentChangeRequestMutation() {
  const invalidate = useInvalidateEnrollmentChangeRequestQueries()

  return useMutation({
    mutationFn: ({
      enrollmentId,
      input,
    }: {
      enrollmentId: number
      input: StoreEnrollmentChangeRequestInput
    }) => createEnrollmentChangeRequest(enrollmentId, input),
    onSuccess: () => invalidate(),
  })
}

export function useUpdateEnrollmentChangeRequestMutation() {
  const invalidate = useInvalidateEnrollmentChangeRequestQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: UpdateEnrollmentChangeRequestInput
    }) => updateEnrollmentChangeRequest(id, input),
    onSuccess: () => invalidate(),
  })
}
