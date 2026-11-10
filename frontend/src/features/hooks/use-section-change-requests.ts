"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type {
  CreateSectionChangeRequestInput,
  DecideSectionChangeRequestInput,
} from "@/features/schemas/section-change-request-schema"
import {
  createSectionChangeRequest,
  decideSectionChangeRequest,
  listSectionChangeRequests,
} from "@/features/services/section-change-request-service"

export const sectionChangeRequestsQueryKey = (userId: string | null) =>
  ["section-change-requests", userId] as const

export function useSectionChangeRequestsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: sectionChangeRequestsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => listSectionChangeRequests(undefined, signal),
    enabled:
      session?.role === "program_chair" || session?.role === "registrar_head",
  })
}

/** A decision or a new request changes the list and, on approval, the sections. */
function useInvalidateAfterChange() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ["section-change-requests"] }),
      queryClient.invalidateQueries({ queryKey: ["sections"] }),
      queryClient.invalidateQueries({ queryKey: ["room-occupancy"] }),
    ])
}

export function useCreateSectionChangeRequestMutation() {
  const invalidate = useInvalidateAfterChange()

  return useMutation({
    mutationFn: ({
      sectionId,
      input,
    }: {
      sectionId: number
      input: CreateSectionChangeRequestInput
    }) => createSectionChangeRequest(sectionId, input),
    onSuccess: () => invalidate(),
  })
}

export function useDecideSectionChangeRequestMutation() {
  const invalidate = useInvalidateAfterChange()

  return useMutation({
    mutationFn: ({
      requestId,
      input,
    }: {
      requestId: number
      input: DecideSectionChangeRequestInput
    }) => decideSectionChangeRequest(requestId, input),
    onSuccess: () => invalidate(),
  })
}
