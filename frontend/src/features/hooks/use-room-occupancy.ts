"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getRoomOccupancy,
  getRoomOccupancySummary,
} from "@/features/services/room-occupancy-service"

export const roomOccupancyQueryKey = (
  userId: string | null,
  room: string | null,
  academicTermId: number,
) => ["room-occupancy", userId, room, academicTermId] as const

export function useRoomOccupancyQuery(
  room: string | null,
  academicTermId: number,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: roomOccupancyQueryKey(
      session?.userId ?? null,
      room,
      academicTermId,
    ),
    queryFn: ({ signal }) =>
      getRoomOccupancy(room ?? "", academicTermId, signal),
    enabled: session !== null && room !== null && academicTermId > 0,
  })
}

export const roomOccupancySummaryQueryKey = (
  userId: string | null,
  academicTermId: number,
) => ["room-occupancy-summary", userId, academicTermId] as const

/** One request for every room's use, so the tiles need no per-room fetch. */
export function useRoomOccupancySummaryQuery(academicTermId: number) {
  const { session } = useAuth()

  return useQuery({
    queryKey: roomOccupancySummaryQueryKey(
      session?.userId ?? null,
      academicTermId,
    ),
    queryFn: ({ signal }) => getRoomOccupancySummary(academicTermId, signal),
    enabled: session !== null && academicTermId > 0,
  })
}
