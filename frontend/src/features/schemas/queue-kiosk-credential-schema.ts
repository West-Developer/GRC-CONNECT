import { z } from "zod"

export const queueKioskCredentialResourceSchema = z
  .object({
    type: z.literal("queue_kiosk_credential"),
    email: z.string().min(1),
    password: z.string(),
  })
  .strict()

export const queueKioskCredentialEnvelopeSchema = z
  .object({ data: queueKioskCredentialResourceSchema })
  .strict()

export const updateQueueKioskCredentialInputSchema = z
  .object({ password: z.string().min(8).max(255) })
  .strict()

export type QueueKioskCredential = z.infer<
  typeof queueKioskCredentialResourceSchema
>
export type UpdateQueueKioskCredentialInput = z.infer<
  typeof updateQueueKioskCredentialInputSchema
>
