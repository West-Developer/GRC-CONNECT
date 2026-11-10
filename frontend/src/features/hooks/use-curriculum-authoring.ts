"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { curriculaQueryKey } from "@/features/hooks/use-curricula"
import { subjectsQueryKey } from "@/features/hooks/use-reference-data"
import type { CurriculumSubjectPlacementInput } from "@/features/schemas/curriculum-schema"
import {
  addCurriculumSubjectPlacement,
  applyCurriculumMigration,
  getCurrentCurriculumSubjects,
  previewCurriculumMigration,
} from "@/features/services/curriculum-service"

export const currentCurriculumSubjectsQueryKey = (
  userId: string | null,
  programId: number | null,
) => ["current-curriculum-subjects", userId, programId] as const

export function useCurrentCurriculumSubjectsQuery(programId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: currentCurriculumSubjectsQueryKey(
      session?.userId ?? null,
      programId,
    ),
    queryFn: ({ signal }) => {
      if (programId === null) {
        return Promise.reject(new Error("A program is required."))
      }

      return getCurrentCurriculumSubjects(programId, signal)
    },
    enabled: session !== null && programId !== null && programId > 0,
  })
}

export function useAddCurriculumSubjectPlacementMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const userId = session?.userId ?? null

  return useMutation({
    mutationFn: ({
      curriculumId,
      input,
    }: {
      curriculumId: number
      input: CurriculumSubjectPlacementInput
    }) => addCurriculumSubjectPlacement(curriculumId, input),
    onSuccess: (_curriculum, { input }) => {
      void queryClient.invalidateQueries({
        queryKey: curriculaQueryKey(userId),
        exact: true,
      })
      if (input.source === "new")
        void queryClient.invalidateQueries({
          queryKey: subjectsQueryKey(userId),
          exact: true,
        })
    },
  })
}

export function useCurriculumMigrationPreviewMutation() {
  return useMutation({
    mutationFn: ({
      curriculumId,
      studentNumber,
    }: {
      curriculumId: number
      studentNumber: string
    }) => previewCurriculumMigration(curriculumId, studentNumber),
  })
}

export function useApplyCurriculumMigrationMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const userId = session?.userId ?? null

  return useMutation({
    mutationFn: ({
      curriculumId,
      studentId,
      equivalencyIds,
    }: {
      curriculumId: number
      studentId: number
      equivalencyIds: readonly number[]
    }) =>
      applyCurriculumMigration(curriculumId, {
        student_id: studentId,
        equivalency_ids: equivalencyIds,
      }),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: curriculaQueryKey(userId),
        exact: true,
      }),
  })
}
