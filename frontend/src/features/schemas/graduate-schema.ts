import { z } from "zod"

export const graduateSchema = z
  .object({
    id: z.number().int().positive(),
    student_number: z.string().min(1),
    full_name: z.string().min(1),
    first_name: z.string().nullable().optional(),
    last_name: z.string().nullable().optional(),
    email: z.string().nullable().optional(),
    program_id: z.number().int().positive(),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    college: z.string().nullable().optional(),
    curriculum_id: z.number().int().nullable().optional(),
    curriculum_name: z.string().nullable().optional(),
    curriculum_version: z.string().nullable().optional(),
    entry_year: z.number().int().nullable().optional(),
    graduation_school_year: z.string().nullable().optional(),
    final_gpa: z.number().nullable().optional(),
  })
  .passthrough()

export type Graduate = z.infer<typeof graduateSchema>

const paginationLinksSchema = z
  .object({
    first: z.string().nullable().optional(),
    last: z.string().nullable().optional(),
    prev: z.string().nullable().optional(),
    next: z.string().nullable().optional(),
  })
  .passthrough()

const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().positive(),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const graduateListResponseSchema = z
  .object({
    data: z.array(graduateSchema),
    links: paginationLinksSchema.optional(),
    meta: paginationMetaSchema,
    summary: z
      .object({
        total_graduates: z.number().int().nonnegative(),
      })
      .passthrough()
      .optional(),
  })
  .passthrough()

export type GraduateListResponse = z.infer<typeof graduateListResponseSchema>

