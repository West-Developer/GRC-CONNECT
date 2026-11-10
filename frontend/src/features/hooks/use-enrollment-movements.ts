"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type {
  EnrollmentMovementType,
  RecordProgramShiftInput,
} from "@/features/schemas/enrollment-movement-schema"
import {
  getEnrollmentMovements,
  recordProgramShift,
} from "@/features/services/enrollment-movement-service"

export const enrollmentMovementsQueryKey = (
  userId: string | null,
  termId: number,
  type: EnrollmentMovementType,
  college: string | null,
) => ["enrollment-movements", userId, termId, type, college] as const

export function useEnrollmentMovementsQuery(
  termId: number,
  type: EnrollmentMovementType,
  college: string | null,
  enabled = true,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentMovementsQueryKey(
      session?.userId ?? null,
      termId,
      type,
      college,
    ),
    queryFn: ({ signal }) =>
      getEnrollmentMovements(termId, type, college ?? undefined, signal),
    enabled: enabled && termId > 0 && session !== null,
  })
}

export function useRecordProgramShiftMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: RecordProgramShiftInput) => recordProgramShift(input),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["enrollment-movements"] }),
  })
}
