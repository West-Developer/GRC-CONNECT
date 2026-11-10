"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getCurricula } from "@/features/services/reference-data-service"

export const curriculaQueryKey = (userId: string | null) =>
  ["curricula", userId] as const

export function useCurriculaQuery() {
  const { session } = useAuth()
  return useQuery({
    queryKey: curriculaQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getCurricula(signal),
    enabled: session !== null,
  })
}
