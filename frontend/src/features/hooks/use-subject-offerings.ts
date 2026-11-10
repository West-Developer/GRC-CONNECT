"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getSubjectOfferings } from "@/features/services/subject-offering-service"

export const subjectOfferingsQueryKey = (
  userId: string | null,
  academicTermId: number | null,
  curriculumId: number | null,
) => ["subject-offerings", userId, academicTermId, curriculumId] as const

export function useSubjectOfferingsQuery(
  academicTermId: number | null,
  curriculumId: number | null,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: subjectOfferingsQueryKey(
      session?.userId ?? null,
      academicTermId,
      curriculumId,
    ),
    queryFn: ({ signal }) =>
      getSubjectOfferings(
        { academic_term_id: academicTermId!, curriculum_id: curriculumId! },
        signal,
      ),
    enabled:
      session !== null && academicTermId !== null && curriculumId !== null,
  })
}
