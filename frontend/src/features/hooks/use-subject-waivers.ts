"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { GrantSubjectWaiverInput } from "@/features/schemas/subject-waiver-schema"
import {
  getSubjectWaiverOverview,
  grantSubjectWaiver,
  revokeSubjectWaiver,
} from "@/features/services/subject-waiver-service"

export const subjectWaiversQueryKey = (
  userId: string | null,
  studentId: number | null,
  termId: number | null,
) => ["subject-waivers", userId, studentId, termId] as const

export function useSubjectWaiverOverviewQuery(
  studentId: number | null,
  termId: number | null,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: subjectWaiversQueryKey(
      session?.userId ?? null,
      studentId,
      termId,
    ),
    queryFn: ({ signal }) =>
      getSubjectWaiverOverview(studentId!, termId!, signal),
    enabled:
      session?.role === "registrar_head" &&
      studentId !== null &&
      termId !== null,
  })
}

/** Granting or revoking changes the list and which subjects are still blocked. */
function useInvalidateSubjectWaivers() {
  const queryClient = useQueryClient()

  return () => queryClient.invalidateQueries({ queryKey: ["subject-waivers"] })
}

export function useGrantSubjectWaiverMutation() {
  const invalidate = useInvalidateSubjectWaivers()

  return useMutation({
    mutationFn: ({
      studentId,
      input,
    }: {
      studentId: number
      input: GrantSubjectWaiverInput
    }) => grantSubjectWaiver(studentId, input),
    onSuccess: () => invalidate(),
  })
}

export function useRevokeSubjectWaiverMutation() {
  const invalidate = useInvalidateSubjectWaivers()

  return useMutation({
    mutationFn: (waiverId: number) => revokeSubjectWaiver(waiverId),
    onSuccess: () => invalidate(),
  })
}
