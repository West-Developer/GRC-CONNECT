import { z } from "zod"

/** One row of the Registrar's read-only list of every student. */
export const registrarStudentSchema = z
  .object({
    type: z.literal("registrar_student"),
    id: z.number().int().positive(),
    student_number: z.string().min(1),
    name: z.string().min(1),
    program_code: z.string().min(1),
    year_level: z.number().int().positive(),
    // `regular` or `irregular`; null for a student with no grade record yet.
    enrollment_category: z.string().min(1).nullable(),
    academic_standing: z.string().min(1),
    academic_standing_label: z.string().min(1),
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

export const paginatedRegistrarStudentsSchema = z
  .object({
    data: z.array(registrarStudentSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const registrarStudentFiltersSchema = z
  .object({
    search: z.string().trim().max(255).optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export type RegistrarStudent = z.infer<typeof registrarStudentSchema>
export type RegistrarStudentFilters = z.input<
  typeof registrarStudentFiltersSchema
>
export type PaginatedRegistrarStudents = z.infer<
  typeof paginatedRegistrarStudentsSchema
>
