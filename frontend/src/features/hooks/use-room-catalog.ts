"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getRoomOptions } from "@/features/services/room-catalog-service"

export const roomOptionsQueryKey = (userId: string | null) => ["room-options", userId] as const

export function useRoomOptionsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: roomOptionsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getRoomOptions(signal),
    enabled: session !== null,
  })
}
