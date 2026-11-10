"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { WithdrawSubjectsInput } from "@/features/schemas/subject-withdrawal-schema"
import {
  getSubjectWithdrawalOverview,
  withdrawStudentSubjects,
} from "@/features/services/subject-withdrawal-service"

export const subjectWithdrawalsQueryKey = (
  userId: string | null,
  studentId: number | null,
) => ["subject-withdrawals", userId, studentId] as const

export function useSubjectWithdrawalOverviewQuery(studentId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: subjectWithdrawalsQueryKey(session?.userId ?? null, studentId),
    queryFn: ({ signal }) => getSubjectWithdrawalOverview(studentId!, signal),
    enabled: session?.role === "registrar_head" && studentId !== null,
  })
}

/** A withdrawal changes the student's Prospectus, grades and enrollment, so those refetch too. */
export function useWithdrawSubjectsMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      studentId,
      input,
    }: {
      studentId: number
      input: WithdrawSubjectsInput
    }) => withdrawStudentSubjects(studentId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["subject-withdrawals"] })
      void queryClient.invalidateQueries({ queryKey: ["prospectus"] })
      void queryClient.invalidateQueries({ queryKey: ["academic-record"] })
      void queryClient.invalidateQueries({ queryKey: ["grade-slip"] })
      void queryClient.invalidateQueries({ queryKey: ["academic-grades"] })
    },
  })
}
