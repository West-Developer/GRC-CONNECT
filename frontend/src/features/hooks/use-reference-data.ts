"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { enrollmentScheduleQueryKey } from "@/features/hooks/use-enrollment-windows"
import {
  archiveAndCreateNextAcademicTerm,
  createAcademicTerm,
  getAcademicTerms,
  updateAcademicTerm,
  updateDraftAcademicTermIdentity,
  getCurricula,
  getPrograms,
  getSections,
  getSubjects,
} from "@/features/services/reference-data-service"
import type { ArchiveAndCreateNextInput } from "@/features/schemas/academic-term-schema"
import type { UpdateDraftAcademicTermIdentityInput } from "@/features/schemas/academic-term-schema"

export const academicTermsQueryKey = (userId: string | null) =>
  ["academic-terms", userId] as const
export const programsQueryKey = (userId: string | null) =>
  ["programs", userId] as const
export const subjectsQueryKey = (userId: string | null) =>
  ["subjects", userId] as const
export const curriculaQueryKey = (userId: string | null) =>
  ["curricula", userId] as const
export const sectionsQueryKey = (userId: string | null) =>
  ["sections", userId] as const

interface ReferenceDataQueryOptions {
  enabled?: boolean
}

interface AcademicTermsQueryOptions extends ReferenceDataQueryOptions {
  /**
   * Re-read the terms on this interval while the tab is visible (and straight away when the tab is
   * focused again). Off by default: only a page that must follow a Registrar's change live, such as
   * the student's Enrollment page, turns it on.
   */
  refetchIntervalMs?: number
}

export function useAcademicTermsQuery({
  enabled = true,
  refetchIntervalMs,
}: AcademicTermsQueryOptions = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: academicTermsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getAcademicTerms(signal),
    enabled: enabled && session !== null,
    ...(refetchIntervalMs === undefined
      ? {}
      : {
          refetchInterval: refetchIntervalMs,
          refetchIntervalInBackground: false,
          refetchOnWindowFocus: "always" as const,
        }),
  })
}

export function useCreateAcademicTermMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createAcademicTerm,
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: academicTermsQueryKey(session?.userId ?? null),
        exact: true,
      }),
  })
}

export function useUpdateAcademicTermMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      academicTermId,
      action,
    }: {
      academicTermId: number
      action: "open_enrollment" | "archive"
    }) => updateAcademicTerm(academicTermId, action),
    // The "Live enrollment status" grid reads the enrollment schedule, not the
    // term list, so it has to follow the transition too. Otherwise it keeps
    // saying "Enrollment not opened" for the query's whole stale time after a
    // successful Start enrollment.
    onSuccess: (_term, { academicTermId }) =>
      Promise.all([
        queryClient.invalidateQueries({
          queryKey: academicTermsQueryKey(session?.userId ?? null),
          exact: true,
        }),
        queryClient.invalidateQueries({
          queryKey: enrollmentScheduleQueryKey(
            academicTermId,
            session?.userId ?? null,
          ),
          exact: true,
        }),
      ]),
  })
}

export function useUpdateDraftAcademicTermIdentityMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      academicTermId,
      input,
    }: {
      academicTermId: number
      input: UpdateDraftAcademicTermIdentityInput
    }) => updateDraftAcademicTermIdentity(academicTermId, input),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: academicTermsQueryKey(session?.userId ?? null),
        exact: true,
      }),
  })
}

export function useArchiveAndCreateNextTermMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      academicTermId,
      next,
    }: {
      academicTermId: number
      next: ArchiveAndCreateNextInput
    }) => archiveAndCreateNextAcademicTerm(academicTermId, next),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: academicTermsQueryKey(session?.userId ?? null),
        exact: true,
      }),
  })
}

export function useProgramsQuery({
  enabled = true,
}: ReferenceDataQueryOptions = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: programsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getPrograms(signal),
    enabled: enabled && session !== null,
  })
}

export function useSubjectsQuery({
  enabled = true,
}: ReferenceDataQueryOptions = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: subjectsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getSubjects(signal),
    enabled: enabled && session !== null,
  })
}

export function useCurriculaQuery({
  enabled = true,
}: ReferenceDataQueryOptions = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: curriculaQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getCurricula(signal),
    enabled: enabled && session !== null,
  })
}

export function useSectionsQuery({
  enabled = true,
}: ReferenceDataQueryOptions = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: sectionsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getSections(signal),
    enabled: enabled && session !== null,
  })
}
