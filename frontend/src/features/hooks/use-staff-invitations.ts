"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { listStaffInvitations } from "@/features/services/staff-invitation-service"

export const staffInvitationsQueryKey = (userId: string | null) =>
  ["staff-invitations", userId] as const

export function useStaffInvitationsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: staffInvitationsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => listStaffInvitations(signal),
    enabled: session !== null,
  })
}
