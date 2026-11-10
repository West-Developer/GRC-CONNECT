"use client"

import { useQuery } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import { getFacultyMembers } from "@/features/services/faculty-directory-service"

export const facultyDirectoryQueryKey = (
  userId: string | null,
  includeInactive = false,
  college?: string,
) => ["faculty-directory", userId, includeInactive, college ?? null] as const

export function useFacultyDirectoryQuery(
  includeInactive = false,
  college?: string,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: facultyDirectoryQueryKey(
      session?.userId ?? null,
      includeInactive,
      college,
    ),
    queryFn: ({ signal }) => getFacultyMembers(signal, includeInactive, college),
    enabled: session !== null,
  })
}
