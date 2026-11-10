"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { CreateAdmissionRequirementTypeInput } from "@/features/schemas/admission-requirements-schema"
import {
  createAdmissionRequirementType,
  getAdmissionChecklist,
  getAdmissionRequirementSelection,
  setAdmissionRequirementSubmitted,
} from "@/features/services/admission-requirements-service"

export const admissionChecklistQueryKey = (
  userId: string | null,
  studentId: number | null,
) => ["admission-requirements", userId, studentId] as const

/** `studentId` null = the signed-in Student's own checklist. */
export function useAdmissionChecklistQuery(studentId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: admissionChecklistQueryKey(session?.userId ?? null, studentId),
    queryFn: ({ signal }) => getAdmissionChecklist(studentId, signal),
    enabled:
      studentId === null
        ? session?.role === "student"
        : session?.role === "admission_staff",
  })
}

/** The Create Account checklist: what applies to a new student of the chosen type (none until one is chosen). */
export function useAdmissionRequirementSelectionQuery(
  studentType: string | null,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: [
      "admission-requirements",
      session?.userId ?? null,
      "selection",
      studentType,
    ] as const,
    queryFn: ({ signal }) =>
      getAdmissionRequirementSelection(studentType!, signal),
    enabled: session?.role === "admission_staff" && studentType !== null,
  })
}

export function useSetAdmissionRequirementMutation(studentId: number) {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { requirementTypeId: number; isSubmitted: boolean }) =>
      setAdmissionRequirementSubmitted(
        studentId,
        input.requirementTypeId,
        input.isSubmitted,
      ),
    onSuccess: (checklist) => {
      queryClient.setQueryData(
        admissionChecklistQueryKey(session?.userId ?? null, studentId),
        checklist,
      )
    },
  })
}

/** A new requirement joins every student of that category, so every cached checklist is stale. */
export function useCreateAdmissionRequirementTypeMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateAdmissionRequirementTypeInput) =>
      createAdmissionRequirementType(input),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["admission-requirements"] }),
  })
}
