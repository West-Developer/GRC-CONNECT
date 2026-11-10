import { z } from "zod"

const classRosterEntryStatusValues = [
  "selected",
  "enrolled",
  "dropped",
] as const

export const classRosterEntryResourceSchema = z
  .object({
    type: z.literal("class_roster_entry"),
    id: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject_code: z.string().min(1),
    academic_term_id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1).optional(),
    status: z.enum(classRosterEntryStatusValues),
    status_label: z.string().min(1),
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

export const paginatedClassRosterSchema = z
  .object({
    data: z.array(classRosterEntryResourceSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const classRosterFiltersSchema = z
  .object({
    section_id: z.number().int().positive().optional(),
    academic_term_id: z.number().int().positive().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export type ClassRosterEntry = z.infer<typeof classRosterEntryResourceSchema>
export type ClassRosterFilters = z.input<typeof classRosterFiltersSchema>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
