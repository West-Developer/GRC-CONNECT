"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { NotificationEnvelope } from "@/features/schemas/notification-schema"
import {
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  type NotificationListOptions,
} from "@/features/services/notification-service"

export const notificationQueryKey = (
  options: NotificationListOptions,
  userId: string | null,
) =>
  [
    "notifications",
    userId,
    options.unread ?? false,
    options.page ?? 1,
    options.perPage ?? 20,
  ] as const

export function useNotificationsQuery(
  options: NotificationListOptions = {},
  open = false,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: notificationQueryKey(options, session?.userId ?? null),
    queryFn: ({ signal }) => getNotifications(options, signal),
    enabled: session !== null && open,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnMount: "always",
    refetchOnWindowFocus: "always",
  })
}

const UNREAD_COUNT_OPTIONS: NotificationListOptions = {
  unread: true,
  page: 1,
  perPage: 1,
}

/**
 * The true unread total (`meta.total`), not a count of the current page's
 * items — the sheet previously derived its "N unread" figure by filtering
 * whichever page was loaded, which undercounted as soon as there was more
 * than one page. This is one of the targeted workflow queries that polls: the
 * bell is where a user expects to learn about another role's action without
 * manually revisiting the page.
 */
export function useUnreadNotificationCountQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: notificationQueryKey(UNREAD_COUNT_OPTIONS, session?.userId ?? null),
    queryFn: ({ signal }) => getNotifications(UNREAD_COUNT_OPTIONS, signal),
    enabled: session !== null,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: "always",
    select: (envelope) => envelope.meta.total,
  })
}

/**
 * Applies "notification `id` was just read" to one cached notifications
 * envelope. The bell's unread badge is `meta.total` of the unread-only
 * queries, so those lose the item and one from the total; the "all" list keeps
 * the row but stamps `read_at`. Envelopes that don't contain the id and aren't
 * unread-only are returned untouched.
 */
function markReadInEnvelope(
  envelope: NotificationEnvelope,
  id: number,
  unreadOnly: boolean,
  readAt: string,
): NotificationEnvelope {
  if (unreadOnly) {
    return {
      ...envelope,
      data: envelope.data.filter((notification) => notification.id !== id),
      meta: { ...envelope.meta, total: Math.max(0, envelope.meta.total - 1) },
    }
  }

  if (!envelope.data.some((notification) => notification.id === id)) {
    return envelope
  }

  return {
    ...envelope,
    data: envelope.data.map((notification) =>
      notification.id === id && notification.read_at === null
        ? { ...notification, read_at: readAt }
        : notification,
    ),
  }
}

/**
 * Marks one notification read and updates the bell immediately (optimistic),
 * so the unread badge drops the moment a notification is clicked instead of
 * waiting for the PATCH plus a refetch. Only ever called for notifications
 * the UI knows are unread. On failure the previous cache is restored; either
 * way the server's truth is refetched afterwards.
 */
export function useMarkNotificationReadMutation() {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const scope = ["notifications", session?.userId ?? null] as const

  return useMutation({
    mutationFn: markNotificationRead,
    onMutate: async (id: number) => {
      await queryClient.cancelQueries({ queryKey: scope })
      const previous = queryClient.getQueriesData<NotificationEnvelope>({
        queryKey: scope,
      })
      const readAt = new Date().toISOString().replace(/\.\d{3}Z$/, "Z")

      for (const [key, envelope] of previous) {
        if (!envelope) continue
        // Key layout: ["notifications", userId, unreadOnly, page, perPage].
        queryClient.setQueryData<NotificationEnvelope>(
          key,
          markReadInEnvelope(envelope, id, key[2] === true, readAt),
        )
      }

      return { previous }
    },
    onError: (_error, _id, context) => {
      for (const [key, envelope] of context?.previous ?? []) {
        queryClient.setQueryData(key, envelope)
      }
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: scope }),
  })
}

/**
 * Calls the bulk read-all endpoint in one request and optimistically
 * clears unread notification counts and badges immediately.
 */
export function useMarkAllNotificationsReadMutation() {
  const queryClient = useQueryClient()
  const { session } = useAuth()

  return useMutation({
    mutationFn: markAllNotificationsRead,
    onSuccess: () => {
      queryClient.setQueriesData(
        { queryKey: ["notifications", session?.userId ?? null] },
        (old: unknown) => {
          if (!old || typeof old !== "object") return old
          const envelope = old as { meta?: { total?: number }; data?: Array<{ read_at: string | null }> }
          if (envelope.meta) {
            return {
              ...envelope,
              meta: { ...envelope.meta, total: 0 },
              data: envelope.data
                ? envelope.data.map((n) => ({
                    ...n,
                    read_at: n.read_at ?? new Date().toISOString(),
                  }))
                : [],
            }
          }
          return old
        },
      )
      void queryClient.invalidateQueries({
        queryKey: ["notifications", session?.userId ?? null],
      })
    },
  })
}
