import { z } from "zod"

const enrollmentChangeRequestTypeValues = ["add", "drop", "change_section"] as const
const enrollmentChangeRequestStatusValues = ["pending", "approved", "rejected"] as const

export const enrollmentChangeRequestSchema = z
  .object({
    type: z.literal("enrollment_change_request"),
    id: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    student_number: z.string().min(1),
    request_type: z.enum(enrollmentChangeRequestTypeValues),
    request_type_label: z.string().min(1),
    subject_code: z.string().min(1),
    from_section_code: z.string().nullable(),
    to_section_code: z.string().nullable(),
    reason: z.string().min(1),
    status: z.enum(enrollmentChangeRequestStatusValues),
    status_label: z.string().min(1),
    decided_at: z.iso.datetime().nullable(),
    decision_reason: z.string().nullable(),
    created_at: z.iso.datetime().nullable(),
  })
  .strict()

export const enrollmentChangeRequestEnvelopeSchema = z
  .object({ data: enrollmentChangeRequestSchema })
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

export const paginatedEnrollmentChangeRequestsSchema = z
  .object({
    data: z.array(enrollmentChangeRequestSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const enrollmentChangeRequestFiltersSchema = z
  .object({
    status: z.enum(enrollmentChangeRequestStatusValues).optional(),
    type: z.enum(enrollmentChangeRequestTypeValues).optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const storeEnrollmentChangeRequestInputSchema = z
  .object({
    type: z.enum(enrollmentChangeRequestTypeValues),
    from_section_id: z.number().int().positive().optional(),
    to_section_id: z.number().int().positive().optional(),
    reason: z.string().min(1),
  })
  .strict()

export const updateEnrollmentChangeRequestInputSchema = z
  .object({
    action: z.enum(["approve", "reject"]),
    reason: z.string().optional(),
  })
  .strict()

export type EnrollmentChangeRequest = z.infer<typeof enrollmentChangeRequestSchema>
export type EnrollmentChangeRequestFilters = z.input<
  typeof enrollmentChangeRequestFiltersSchema
>
export type StoreEnrollmentChangeRequestInput = z.infer<
  typeof storeEnrollmentChangeRequestInputSchema
>
export type UpdateEnrollmentChangeRequestInput = z.infer<
  typeof updateEnrollmentChangeRequestInputSchema
>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
