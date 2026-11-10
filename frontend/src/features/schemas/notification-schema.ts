import { z } from "zod"

const optionalUtcDateTimeSchema = z.iso.datetime().nullable()

// Deliberately not a `z.enum` of known `NotificationType` values: the
// backend enum already has 11 cases and grows independently of this
// frontend build. A `.strict()` object with a narrow enum here means any one
// unrecognized notification in the page fails the whole array's parse and
// the bell renders "Notifications are unavailable right now." for every
// notification type, not just the new one. Presentation (icon/tone/label)
// is instead driven by a client-side lookup with a default fallback — see
// `notification-presentation.ts`.
export const notificationSchema = z
  .object({
    type: z.literal("notification"),
    id: z.number().int().positive(),
    notification_type: z.string().min(1),
    message: z.string().min(1),
    read_at: optionalUtcDateTimeSchema,
    created_at: optionalUtcDateTimeSchema,
  })
  .strict()

const paginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()

const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().min(1).max(100),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const notificationEnvelopeSchema = z
  .object({
    data: z.array(notificationSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const notificationResponseSchema = z
  .object({ data: notificationSchema })
  .strict()

export type Notification = z.infer<typeof notificationSchema>
export type NotificationEnvelope = z.infer<typeof notificationEnvelopeSchema>
