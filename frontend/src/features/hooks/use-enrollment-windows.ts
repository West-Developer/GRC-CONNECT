"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { SaveEnrollmentScheduleInput } from "@/features/schemas/enrollment-window-schema"
import {
  getEnrollmentSchedule,
  saveEnrollmentSchedule,
} from "@/features/services/enrollment-window-service"

export const enrollmentScheduleQueryKey = (
  academicTermId: number | null,
  userId: string | null,
) => ["enrollment-schedule", academicTermId, userId] as const

/**
 * `refetchIntervalMs` re-reads the schedule while the tab is visible (and when it is focused again),
 * so a student sees the Registrar Head open enrollment without reloading. Off by default.
 */
export function useEnrollmentScheduleQuery(
  academicTermId: number | null,
  enabled = true,
  { refetchIntervalMs }: { refetchIntervalMs?: number } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentScheduleQueryKey(academicTermId, session?.userId ?? null),
    queryFn: ({ signal }) => getEnrollmentSchedule(academicTermId!, signal),
    enabled: enabled && session !== null && academicTermId !== null,
    ...(refetchIntervalMs === undefined
      ? {}
      : {
          refetchInterval: refetchIntervalMs,
          refetchIntervalInBackground: false,
          refetchOnWindowFocus: "always" as const,
        }),
  })
}

export function useSaveEnrollmentScheduleMutation(academicTermId: number | null) {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveEnrollmentScheduleInput) =>
      saveEnrollmentSchedule(academicTermId!, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: enrollmentScheduleQueryKey(academicTermId, session?.userId ?? null),
          exact: true,
        }),
        queryClient.invalidateQueries({
          queryKey: ["academic-terms", session?.userId ?? null],
          exact: true,
        }),
      ])
    },
  })
}
