"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getLectureLabAdjacency } from "@/features/services/lecture-lab-adjacency-service"

export const lectureLabAdjacencyQueryKey = (
  userId: string | null,
  termId: number | null,
) => ["lecture-lab-adjacency", userId, termId] as const

export function useLectureLabAdjacencyQuery(termId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: lectureLabAdjacencyQueryKey(session?.userId ?? null, termId),
    queryFn: ({ signal }) => getLectureLabAdjacency(termId!, signal),
    enabled: session?.role === "program_chair" && termId !== null,
  })
}
