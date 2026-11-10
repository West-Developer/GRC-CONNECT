"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { SaveSectionGradeDraftsInput } from "@/features/schemas/section-grade-schema"
import {
  getSectionGradeSheet,
  listGradeSubmissionSections,
  saveSectionGradeDrafts,
  submitSectionGrades,
} from "@/features/services/section-grade-service"

export const gradeSubmissionSectionsQueryKey = (userId: string | null) =>
  ["grade-submission-sections", userId] as const

export const sectionGradeSheetQueryKey = (
  userId: string | null,
  sectionId: number | null,
) => ["section-grade-sheet", userId, sectionId] as const

export function useGradeSubmissionSectionsQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: gradeSubmissionSectionsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => listGradeSubmissionSections(signal),
    enabled: enabled && session !== null,
  })
}

export function useSectionGradeSheetQuery(
  sectionId: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: sectionGradeSheetQueryKey(session?.userId ?? null, sectionId),
    queryFn: ({ signal }) => getSectionGradeSheet(sectionId!, signal),
    enabled: enabled && session !== null && sectionId !== null,
  })
}

function useRefreshSectionGradeQueries(sectionId: number | null) {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const userId = session?.userId ?? null

  return (data: Awaited<ReturnType<typeof getSectionGradeSheet>>) => {
    queryClient.setQueryData(sectionGradeSheetQueryKey(userId, sectionId), data)
    return queryClient.invalidateQueries({
      queryKey: gradeSubmissionSectionsQueryKey(userId),
    })
  }
}

export function useSaveSectionGradeDraftsMutation(sectionId: number | null) {
  const refresh = useRefreshSectionGradeQueries(sectionId)

  return useMutation({
    mutationFn: (input: SaveSectionGradeDraftsInput) =>
      saveSectionGradeDrafts(sectionId!, input),
    onSuccess: refresh,
  })
}

export function useSubmitSectionGradesMutation(sectionId: number | null) {
  const refresh = useRefreshSectionGradeQueries(sectionId)

  return useMutation({
    mutationFn: () => submitSectionGrades(sectionId!),
    onSuccess: refresh,
  })
}
