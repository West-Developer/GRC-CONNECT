import {
  notificationEnvelopeSchema,
  notificationResponseSchema,
  type Notification,
  type NotificationEnvelope,
} from "@/features/schemas/notification-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
} from "@/features/services/api-client"

export interface NotificationListOptions {
  unread?: boolean
  page?: number
  perPage?: number
}

function parseResponse<T>(
  schema: {
    safeParse: (
      payload: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  resourceName: string,
): T {
  const parsed = schema.safeParse(payload)

  if (!parsed.success) {
    throw new ApiClientError({
      kind: "contract",
      message: `The API responded, but its ${resourceName} payload did not match the published v1 contract.`,
      cause: parsed.error,
    })
  }

  return parsed.data
}

export function notificationListPath(
  options: NotificationListOptions = {},
): string {
  const parameters = new URLSearchParams()

  if (options.unread) {
    parameters.set("unread_only", "true")
  }

  parameters.set("page", String(options.page ?? 1))
  parameters.set("per_page", String(options.perPage ?? 20))

  return `/api/v1/notifications?${parameters.toString()}`
}

export async function getNotifications(
  options: NotificationListOptions = {},
  signal?: AbortSignal,
): Promise<NotificationEnvelope> {
  const payload = await getAuthenticatedJson(
    notificationListPath(options),
    signal,
  )
  return parseResponse(notificationEnvelopeSchema, payload, "notifications")
}

export async function markNotificationRead(id: number): Promise<Notification> {
  const payload = await patchAuthenticatedJson(
    `/api/v1/notifications/${id}/read`,
    {},
  )
  return parseResponse(notificationResponseSchema, payload, "notification").data
}

export async function markAllNotificationsRead(): Promise<{ message: string; marked_count: number }> {
  const payload = await patchAuthenticatedJson(
    `/api/v1/notifications/read-all`,
    {},
  )
  return payload as { message: string; marked_count: number }
}
