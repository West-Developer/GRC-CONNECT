import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { notificationEnvelopeSchema } from "@/features/schemas/notification-schema"
import {
  getNotifications,
  markNotificationRead,
} from "@/features/services/notification-service"

const notification = {
  type: "notification",
  id: 7,
  notification_type: "schedule_published",
  message: "The schedule has been published.",
  read_at: null,
  created_at: "2026-07-29T10:00:00Z",
} as const

const notificationEnvelope = {
  data: [notification],
  links: {
    first: "http://127.0.0.1:8000/api/v1/notifications?page=1",
    last: "http://127.0.0.1:8000/api/v1/notifications?page=2",
    prev: null,
    next: "http://127.0.0.1:8000/api/v1/notifications?page=2",
  },
  meta: { current_page: 1, last_page: 2, per_page: 20, total: 21 },
} as const

describe("notification service", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("parses the private paginated notification resource", () => {
    expect(
      notificationEnvelopeSchema.parse(notificationEnvelope).data[0],
    ).toMatchObject({
      id: 7,
      notification_type: "schedule_published",
      read_at: null,
    })
  })

  it("preserves the unread filter and pagination inputs", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(notificationEnvelope), { status: 200 }),
    )

    await expect(
      getNotifications({ unread: true, page: 2, perPage: 10 }),
    ).resolves.toEqual(notificationEnvelope)

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/v1/notifications?unread_only=true&page=2&per_page=10",
      expect.objectContaining({ method: "GET" }),
    )
  })

  it("marks only the selected notification as read through the owned route", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { ...notification, read_at: "2026-07-29T10:02:00Z" },
        }),
        { status: 200 },
      ),
    )

    await expect(markNotificationRead(7)).resolves.toMatchObject({
      id: 7,
      read_at: "2026-07-29T10:02:00Z",
    })
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/notifications/7/read"),
      expect.objectContaining({ method: "PATCH" }),
    )
  })
})
