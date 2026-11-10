"use client"

import { useQuery } from "@tanstack/react-query"

import { getPublicApiHealth } from "@/features/services/health-service"

export const publicApiHealthQueryKey = ["service-health", "public-api"] as const

export function useHealthQuery() {
  return useQuery({
    queryKey: publicApiHealthQueryKey,
    queryFn: ({ signal }) => getPublicApiHealth({ signal }),
  })
}
