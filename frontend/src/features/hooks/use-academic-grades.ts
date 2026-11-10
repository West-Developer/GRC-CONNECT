"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  AcademicGradeFilters,
  GradeApprovalProfessorFilters,
  LockAllAcademicGradesInput,
  UpdateAcademicGradeInput,
} from "@/features/schemas/academic-grade-schema"
import {
  createAcademicGrade,
  listAcademicGrades,
  listGradeApprovalProfessors,
  listGradeApprovalSections,
  lockAllAcademicGrades,
  updateAcademicGrade,
} from "@/features/services/academic-grade-service"

export const academicGradesQueryKey = (
  userId: string | null,
  filters: AcademicGradeFilters,
) => ["academic-grades", userId, filters] as const

export function useAcademicGradesQuery(
  filters: AcademicGradeFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: academicGradesQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listAcademicGrades(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

/**
 * The approvals' first level, one row per professor. Keyed under the same
 * `["academic-grades", userId]` prefix as the grade list, so locking a grade
 * (which invalidates that prefix) refreshes these counts too.
 */
export function useGradeApprovalProfessorsQuery(
  filters: GradeApprovalProfessorFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: [
      "academic-grades",
      session?.userId ?? null,
      "approval-professors",
      filters,
    ] as const,
    queryFn: ({ signal }) => listGradeApprovalProfessors(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

/** The approvals' second level: one professor's sections. */
export function useGradeApprovalSectionsQuery(
  filters: { professor_id: number; college?: string },
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: [
      "academic-grades",
      session?.userId ?? null,
      "approval-sections",
      filters,
    ] as const,
    queryFn: ({ signal }) => listGradeApprovalSections(filters, signal),
    enabled: enabled && session !== null,
  })
}

function useInvalidateAcademicGradeQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    queryClient.invalidateQueries({
      queryKey: ["academic-grades", session?.userId ?? null],
    })
}

export function useCreateAcademicGradeMutation() {
  const invalidate = useInvalidateAcademicGradeQueries()

  return useMutation({
    mutationFn: createAcademicGrade,
    onSuccess: () => invalidate(),
  })
}

export function useUpdateAcademicGradeMutation() {
  const invalidate = useInvalidateAcademicGradeQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: UpdateAcademicGradeInput
    }) => updateAcademicGrade(id, input),
    onSuccess: () => invalidate(),
  })
}

export function useLockAllAcademicGradesMutation() {
  const invalidate = useInvalidateAcademicGradeQueries()

  return useMutation({
    mutationFn: (input?: LockAllAcademicGradesInput) =>
      lockAllAcademicGrades(input),
    onSuccess: () => invalidate(),
  })
}

