"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { FacultyEmploymentType } from "@/features/schemas/schedule-generation-schema"
import { sectionsQueryKey } from "@/features/hooks/use-reference-data"
import { assignSectionProfessor } from "@/features/services/scheduling-service"
import {
  clearFacultyLoadOverride,
  getFacultyLoadReport,
  saveFacultyLoadLimit,
  saveFacultyLoadOverride,
  saveFacultyLoadThreshold,
} from "@/features/services/schedule-generation-service"

export const facultyLoadReportQueryKey = (
  userId: string | null,
  termId: number,
) => ["faculty-load-report", userId, termId] as const

export function useFacultyLoadReportQuery(termId: number) {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyLoadReportQueryKey(session?.userId ?? null, termId),
    queryFn: () => getFacultyLoadReport(termId),
    enabled: termId > 0,
  })
}

/** Every change to a limit or override changes who is flagged as overloaded. */
function useRefreshFacultyLoadReport() {
  const queryClient = useQueryClient()

  return () =>
    queryClient.invalidateQueries({ queryKey: ["faculty-load-report"] })
}

export function useSaveFacultyLoadLimitMutation(termId: number) {
  const refresh = useRefreshFacultyLoadReport()

  return useMutation({
    mutationFn: ({
      employmentType,
      maxUnits,
    }: {
      employmentType: FacultyEmploymentType
      maxUnits: number
    }) => saveFacultyLoadLimit(termId, employmentType, maxUnits),
    onSuccess: () => refresh(),
  })
}

/** The college-wide default that applies when nothing more specific does. */
export function useSaveFacultyLoadThresholdMutation(termId: number) {
  const refresh = useRefreshFacultyLoadReport()

  return useMutation({
    mutationFn: (maxUnits: number) =>
      saveFacultyLoadThreshold(termId, maxUnits),
    onSuccess: () => refresh(),
  })
}

export function useSaveFacultyLoadOverrideMutation(termId: number) {
  const refresh = useRefreshFacultyLoadReport()

  return useMutation({
    mutationFn: ({
      professorId,
      maxUnits,
      reason,
    }: {
      professorId: number
      maxUnits: number
      reason: string
    }) =>
      saveFacultyLoadOverride(termId, professorId, {
        max_units: maxUnits,
        reason,
      }),
    onSuccess: () => refresh(),
  })
}

export function useClearFacultyLoadOverrideMutation(termId: number) {
  const refresh = useRefreshFacultyLoadReport()

  return useMutation({
    mutationFn: (professorId: number) =>
      clearFacultyLoadOverride(termId, professorId),
    onSuccess: () => refresh(),
  })
}

/** The Dean assigning or removing a section's professor moves the load figures. */
export function useAssignSectionProfessorMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      sectionId,
      professorId,
      reason,
    }: {
      sectionId: number
      professorId: number | null
      reason?: string
    }) => assignSectionProfessor(sectionId, professorId, reason),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: ["faculty-load-report"] }),
        queryClient.invalidateQueries({
          queryKey: sectionsQueryKey(session?.userId ?? null),
        }),
      ]),
  })
}
