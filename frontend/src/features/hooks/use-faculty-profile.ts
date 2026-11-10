"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getFacultyProfile } from "@/features/services/faculty-directory-service"

export const facultyProfileQueryKey = (
  userId: string | null,
  professorId: number | null,
  academicTermId: number | null,
) => ["faculty-profile", userId, professorId, academicTermId] as const

export function useFacultyProfileQuery(
  professorId: number | null,
  academicTermId: number | null,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyProfileQueryKey(
      session?.userId ?? null,
      professorId,
      academicTermId,
    ),
    queryFn: ({ signal }) =>
      getFacultyProfile(professorId!, academicTermId ?? undefined, signal),
    enabled: session?.role === "registrar_head" && professorId !== null,
  })
}
