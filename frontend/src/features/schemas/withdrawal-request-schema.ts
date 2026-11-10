import { z } from "zod"

const withdrawalStatusValues = ["pending", "approved", "rejected"] as const

export const withdrawalRequestResourceSchema = z
  .object({
    type: z.literal("withdrawal_request"),
    id: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    student_number: z.string().min(1),
    reason: z.string().min(1),
    status: z.enum(withdrawalStatusValues),
    status_label: z.string().min(1),
    processed_at: z.iso.datetime().nullable(),
    created_at: z.iso.datetime().nullable(),
  })
  .strict()

export const withdrawalRequestEnvelopeSchema = z
  .object({ data: withdrawalRequestResourceSchema })
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

export const paginatedWithdrawalRequestsSchema = z
  .object({
    data: z.array(withdrawalRequestResourceSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const withdrawalRequestFiltersSchema = z
  .object({
    status: z.enum(withdrawalStatusValues).optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const createWithdrawalRequestInputSchema = z
  .object({ reason: z.string().min(1) })
  .strict()

export const decideWithdrawalRequestInputSchema = z
  .object({
    action: z.enum(["approve", "reject"]),
    reason: z.string().min(1).optional(),
  })
  .strict()

export type WithdrawalRequest = z.infer<typeof withdrawalRequestResourceSchema>
export type WithdrawalRequestFilters = z.input<
  typeof withdrawalRequestFiltersSchema
>
export type CreateWithdrawalRequestInput = z.infer<
  typeof createWithdrawalRequestInputSchema
>
export type DecideWithdrawalRequestInput = z.infer<
  typeof decideWithdrawalRequestInputSchema
>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
