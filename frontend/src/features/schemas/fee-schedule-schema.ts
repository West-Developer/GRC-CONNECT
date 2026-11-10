import { z } from "zod"

export const feeScheduleSchema = z
  .object({
    id: z.number().int().positive(),
    category: z.enum(["tuition", "miscellaneous"]),
    semester: z.enum(["1st", "2nd"]).nullable(),
    label: z.string().min(1),
    amount: z.string(),
    program_codes: z.array(z.string()).nullable(),
    is_active: z.boolean(),
    sort_order: z.number().int().nonnegative(),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
  })
  .strict()

export const feeSchedulesEnvelopeSchema = z
  .object({
    data: z.array(feeScheduleSchema),
  })
  .strict()

export const updateFeeSchedulePayloadSchema = z
  .object({
    tuition_rate_per_unit: z.string().min(1),
    miscellaneous_fees: z.array(
      z.object({
        id: z.number().int().positive().nullable().optional(),
        label: z.string().min(1),
        amount: z.string().min(1),
        semester: z.enum(["1st", "2nd"]).nullable().optional(),
        program_codes: z.array(z.string()).nullable().optional(),
        is_active: z.boolean().optional(),
        sort_order: z.number().int().nonnegative().optional(),
      }),
    ),
  })
  .strict()

export const updateFeeScheduleResponseSchema = z
  .object({
    message: z.string(),
    data: z.array(feeScheduleSchema),
  })
  .strict()

export type FeeSchedule = z.infer<typeof feeScheduleSchema>
export type UpdateFeeSchedulePayload = z.infer<typeof updateFeeSchedulePayloadSchema>
