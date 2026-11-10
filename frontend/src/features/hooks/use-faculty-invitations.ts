"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { listFacultyInvitations } from "@/features/services/faculty-invitation-service"

export const facultyInvitationsQueryKey = (userId: string | null) =>
  ["faculty-invitations", userId] as const

export function useFacultyInvitationsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyInvitationsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => listFacultyInvitations(signal),
    enabled: session !== null,
  })
}
