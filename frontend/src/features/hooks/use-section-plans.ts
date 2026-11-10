"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import { autoAssignSectionSchedule, getSectionPlans, releaseSectionPlan, saveSectionPlan, submitSectionPlan } from "@/features/services/section-plan-service"
import type { SectionPlanCounts } from "@/features/schemas/section-plan-schema"

export const sectionPlansQueryKey = (userId: string | null, termId: number, curriculumId?: number) => ["section-plans", userId, termId, curriculumId ?? "all"] as const

export function useSectionPlansQuery(termId: number, enabled = true) {
  const { session } = useAuth()
  return useQuery({
    queryKey: sectionPlansQueryKey(session?.userId ?? null, termId),
    queryFn: ({ signal }) => getSectionPlans(termId, undefined, signal),
    enabled: enabled && termId > 0 && session !== null,
  })
}

export function useSectionPlanMutations(termId: number) {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["section-plans", session?.userId ?? null, termId] })
  const save = useMutation({ mutationFn: (input: SectionPlanCounts) => saveSectionPlan(input), onSuccess: invalidate })
  const release = useMutation({ mutationFn: (input: { curriculumId: number; yearLevel?: number }) => releaseSectionPlan(termId, input.curriculumId, input.yearLevel), onSuccess: invalidate })
  const submit = useMutation({ mutationFn: (curriculumId: number) => submitSectionPlan(termId, curriculumId), onSuccess: invalidate })
  const autoAssign = useMutation({ mutationFn: (input: { curriculumId: number; yearLevel?: number }) => autoAssignSectionSchedule(termId, input.curriculumId, input.yearLevel), onSuccess: invalidate })
  return { save, release, submit, autoAssign }
}
