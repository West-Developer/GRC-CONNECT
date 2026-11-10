import { z } from "zod"

const queueCycleStatusValues = ["open", "cut_off", "closed"] as const

export const queueCycleResourceSchema = z
  .object({
    type: z.literal("queue_cycle"),
    id: z.number().int().positive(),
    opened_on: z.iso.date(),
    status: z.enum(queueCycleStatusValues),
    status_label: z.string().min(1),
    cut_off_at: z.iso.datetime().nullable(),
    cut_off_service_date: z.iso.date().nullable(),
  })
  .strict()

export const queueCycleEnvelopeSchema = z
  .object({ data: queueCycleResourceSchema })
  .strict()

export const nullableQueueCycleEnvelopeSchema = z
  .object({ data: queueCycleResourceSchema.nullable() })
  .strict()

export type QueueCycle = z.infer<typeof queueCycleResourceSchema>
