"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getAcademicTermWorkflows,
  updateAcademicTermWorkflow,
} from "@/features/services/reference-data-service"
import type { AcademicTermWorkflowAction } from "@/features/schemas/academic-term-workflow-schema"

export const academicTermWorkflowsQueryKey = (
  userId: string | null,
  academicTermId: number,
) => ["academic-term-workflows", userId, academicTermId] as const

export function useAcademicTermWorkflowsQuery(
  academicTermId: number,
  enabled = true,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: academicTermWorkflowsQueryKey(
      session?.userId ?? null,
      academicTermId,
    ),
    queryFn: ({ signal }) => getAcademicTermWorkflows(academicTermId, signal),
    enabled: enabled && academicTermId > 0 && session !== null,
  })
}

export function useUpdateAcademicTermWorkflowMutation(academicTermId: number) {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ workflowId, input }: { workflowId: number; input: AcademicTermWorkflowAction }) =>
      updateAcademicTermWorkflow(workflowId, input),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: academicTermWorkflowsQueryKey(
          session?.userId ?? null,
          academicTermId,
        ),
      }),
  })
}
