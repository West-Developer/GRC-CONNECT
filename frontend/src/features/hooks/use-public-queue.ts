"use client"

import { useQuery } from "@tanstack/react-query"

import { isApiClientError } from "@/features/services/api-client"
import { getPublicQueueView } from "@/features/services/public-queue-service"

export const publicQueueQueryKey = (token: string | null) =>
  ["public-queue", token] as const

/** True when the server does not know this link (it was replaced, or it never existed). */
export function isUnknownLinkError(error: unknown): boolean {
  return isApiClientError(error) && error.status === 404
}

/**
 * Polls a freshman's live queue every three seconds, including while the tab is
 * hidden (so the "you are being called" alert still fires, as for a signed-in
 * student). It stops asking once the server says it does not know the link —
 * asking again cannot change that — but keeps trying through any other failure,
 * such as a dropped connection on campus Wi-Fi. The query key holds the token: it
 * is the only thing identifying the view, and it is never written to storage.
 */
export function usePublicQueueQuery(token: string | null) {
  return useQuery({
    queryKey: publicQueueQueryKey(token),
    queryFn: ({ signal }) => getPublicQueueView(token!, signal),
    enabled: token !== null && token !== "",
    retry: false,
    refetchInterval: (query) =>
      isUnknownLinkError(query.state.error) ? false : 3_000,
    refetchIntervalInBackground: true,
    refetchOnWindowFocus: "always",
  })
}
