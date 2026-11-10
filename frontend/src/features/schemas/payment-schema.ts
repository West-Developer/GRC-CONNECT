import { z } from "zod"

export const paymentResourceSchema = z
  .object({
    type: z.literal("payment"),
    id: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    student_number: z.string().min(1),
    external_reference: z.string().nullable(),
    amount: z.string().nullable(),
    confirmed_at: z.iso.datetime(),
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

export const paginatedPaymentsSchema = z
  .object({
    data: z.array(paymentResourceSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const paymentFiltersSchema = z
  .object({
    confirmed_on: z.iso.date().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export type Payment = z.infer<typeof paymentResourceSchema>
export type PaymentFilters = z.input<typeof paymentFiltersSchema>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
