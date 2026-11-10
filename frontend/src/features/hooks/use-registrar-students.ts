"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { RegistrarStudentFilters } from "@/features/schemas/registrar-student-schema"
import { listRegistrarStudents } from "@/features/services/registrar-student-service"

export const registrarStudentsQueryKey = (
  userId: string | null,
  filters: RegistrarStudentFilters,
) => ["registrar-students", userId, filters] as const

/** The Registrar's read-only list of every student. */
export function useRegistrarStudentsQuery(filters: RegistrarStudentFilters) {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_staff" || session?.role === "registrar_head"

  return useQuery({
    queryKey: registrarStudentsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listRegistrarStudents(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: authorized,
    refetchOnWindowFocus: "always",
  })
}
