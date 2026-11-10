import { QueryClient } from "@tanstack/react-query"

import { isApiClientError } from "@/features/services/api-client"

/**
 * Retrying a 4xx is never useful — a 403/404/409/422/429 will fail again
 * identically, and retrying 429 specifically makes throttling worse. Only
 * retry failures that might be transient: network/offline (`kind:
 * "connection"`) and 5xx.
 */
function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  if (failureCount >= 1) {
    return false
  }

  if (!isApiClientError(error)) {
    return true
  }

  if (error.kind !== "http") {
    return error.kind === "connection"
  }

  return (error.status ?? 0) >= 500
}

/**
 * `placeholderData` for a filtered or paginated list: keep the previous page's
 * rows on screen while the next page loads, so paging or filtering never blanks
 * the table (ADR 0029). Private query keys are `[name, session.userId,
 * filters]` (see the "Do Not Change" list), so rows are only carried over when
 * the previous query belongs to the SAME user; a different (or missing) user
 * gets nothing rather than someone else's data.
 */
export function keepPreviousForSameUser(userId: string | null) {
  return <T>(
    previousData: T | undefined,
    previousQuery: { queryKey: readonly unknown[] } | undefined,
  ): T | undefined =>
    userId !== null && previousQuery?.queryKey[1] === userId
      ? previousData
      : undefined
}

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        gcTime: 15 * 60 * 1000,
        refetchOnWindowFocus: false,
        retry: shouldRetryQuery,
        staleTime: 5 * 60 * 1000,
      },
    },
  })
}
