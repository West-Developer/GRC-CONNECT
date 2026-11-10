"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { EnrollmentDocumentFilters } from "@/features/schemas/enrollment-document-schema"
import {
  getCertificateOfRegistration,
  listEnrollmentDocuments,
} from "@/features/services/enrollment-document-service"

export const enrollmentDocumentsQueryKey = (
  userId: string | null,
  filters: EnrollmentDocumentFilters,
) => ["enrollment-documents", userId, filters] as const

export function useEnrollmentDocumentsQuery(
  filters: EnrollmentDocumentFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentDocumentsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listEnrollmentDocuments(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

export function useCertificateOfRegistrationQuery(
  id: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: ["certificate-of-registration", session?.userId ?? null, id],
    queryFn: ({ signal }) => getCertificateOfRegistration(id as number, signal),
    enabled: enabled && session !== null && id !== null,
  })
}
