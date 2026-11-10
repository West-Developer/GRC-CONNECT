"use client"

import { useQuery } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import { getGraduates } from "@/features/services/graduate-service"

export function useGraduatesQuery(input: {
  programId?: number | null
  graduationSchoolYear?: string | null
  curriculumId?: number | null
  search?: string | null
  page?: number
  perPage?: number
} = {}) {
  const { session } = useAuth()
  const isAuthorized =
    session?.role === "registrar_head" ||
    session?.role === "registrar_staff" ||
    session?.role === "dean" ||
    session?.role === "executive_director" ||
    session?.role === "it_admin"

  return useQuery({
    queryKey: ["graduates", session?.userId ?? null, input],
    queryFn: ({ signal }) => getGraduates(input, signal),
    enabled: isAuthorized,
    refetchOnWindowFocus: true,
  })
}

