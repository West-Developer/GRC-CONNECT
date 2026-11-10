"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  DecideProfileChangeRequestInput,
  ProfileChangeRequestFilters,
  ProvisionStudentInput,
  StoreProfileChangeRequestInput,
  StudentProfileFilters,
  UpdateStudentProfileInput,
} from "@/features/schemas/admission-schema"
import {
  cancelProfileChangeRequest,
  createProfileChangeRequest,
  decideProfileChangeRequest,
  getNextStudentNumber,
  getRegistrarStudentProfile,
  getStudentProfile,
  listProfileChangeRequests,
  listStudentProfiles,
  provisionStudent,
  resendAccountSetupInvitation,
  resendQueueLink,
  reviseProfileChangeRequest,
  updateStudentProfile,
} from "@/features/services/admission-service"

export const studentDirectoryQueryKey = (
  userId: string | null,
  filters: StudentProfileFilters,
) => ["student-directory", userId, filters] as const
export const ownStudentProfileQueryKey = (userId: string | null) =>
  ["student-profile", userId] as const
export const registrarStudentProfileQueryKey = (
  userId: string | null,
  studentId: number | null,
) => ["registrar-student-profile", userId, studentId] as const
export const profileChangeRequestsQueryKey = (
  userId: string | null,
  filters: ProfileChangeRequestFilters,
) => ["student-profile-change-requests", userId, filters] as const

export function useStudentDirectoryQuery(filters: StudentProfileFilters) {
  const { session } = useAuth()
  return useQuery({
    queryKey: studentDirectoryQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listStudentProfiles(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: session?.role === "admission_staff",
  })
}

export const nextStudentNumberQueryKey = (userId: string | null) =>
  ["next-student-number", userId] as const

/**
 * The number the next account would get, shown in the Admission form before saving. Only read
 * while the form is asking for a new number (`enabled`), and refreshed after each save.
 */
export function useNextStudentNumberQuery(enabled: boolean) {
  const { session } = useAuth()
  return useQuery({
    queryKey: nextStudentNumberQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getNextStudentNumber(signal),
    enabled: enabled && session?.role === "admission_staff",
  })
}

export function useOwnStudentProfileQuery() {
  const { session } = useAuth()
  return useQuery({
    queryKey: ownStudentProfileQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getStudentProfile(undefined, signal),
    enabled: session?.role === "student",
  })
}

export function useRegistrarStudentProfileQuery(studentId: number | null) {
  const { session } = useAuth()
  return useQuery({
    queryKey: registrarStudentProfileQueryKey(
      session?.userId ?? null,
      studentId,
    ),
    queryFn: ({ signal }) => getRegistrarStudentProfile(studentId!, signal),
    enabled: session?.role === "registrar_head" && studentId !== null,
  })
}

export function useProfileChangeRequestsQuery(
  filters: ProfileChangeRequestFilters,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: profileChangeRequestsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listProfileChangeRequests(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: session?.role === "student" || session?.role === "admission_staff",
  })
}

function useInvalidateStudentRecords() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["student-directory", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["student-profile", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["student-profile-change-requests", session?.userId ?? null],
      }),
      // A save takes the number previewed for the form; the preview moves on to the next one.
      queryClient.invalidateQueries({
        queryKey: nextStudentNumberQueryKey(session?.userId ?? null),
      }),
    ])
}

export function useProvisionStudentMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    // `privacyNoticeVersion`: the Data Privacy Notice version Admission showed the applicant (ADR 0055).
    mutationFn: ({
      input,
      privacyNoticeVersion,
    }: {
      input: ProvisionStudentInput
      privacyNoticeVersion: string
    }) => provisionStudent(input, privacyNoticeVersion),
    onSuccess: invalidate,
  })
}

export function useUpdateStudentProfileMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: UpdateStudentProfileInput
    }) => updateStudentProfile(id, input),
    onSuccess: invalidate,
  })
}

export function useResendAccountSetupInvitationMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: resendAccountSetupInvitation,
    onSuccess: invalidate,
  })
}

export function useResendQueueLinkMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: resendQueueLink,
    onSuccess: invalidate,
  })
}

export function useCreateProfileChangeRequestMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: createProfileChangeRequest,
    onSuccess: invalidate,
  })
}

export function useReviseProfileChangeRequestMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: StoreProfileChangeRequestInput
    }) => reviseProfileChangeRequest(id, input),
    onSuccess: invalidate,
  })
}

export function useCancelProfileChangeRequestMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: cancelProfileChangeRequest,
    onSuccess: invalidate,
  })
}

export function useDecideProfileChangeRequestMutation() {
  const invalidate = useInvalidateStudentRecords()
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: DecideProfileChangeRequestInput
    }) => decideProfileChangeRequest(id, input),
    onSuccess: invalidate,
  })
}

export type { ProvisionStudentInput }
