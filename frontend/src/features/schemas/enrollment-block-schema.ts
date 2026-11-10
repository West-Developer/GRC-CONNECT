import { z } from "zod"

export const enrollmentBlockReasonSchema = z
  .object({
    code: z.enum([
      "already_enrolled",
      "window_closed",
      "incomplete_schedule",
      "partially_completed",
      "prerequisite",
      "block_full",
    ]),
    message: z.string().min(1),
  })
  .strict()

const enrollmentBlockSubjectSchema = z
  .object({
    section_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    paired_subject_id: z.number().int().positive().nullable().optional(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().positive(),
    schedule_days: z.string().nullable(),
    starts_at_time: z.string().nullable(),
    ends_at_time: z.string().nullable(),
    room: z.string().nullable(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable().optional(),
    professor_name: z.string().nullable(),
    capacity: z.number().int().nonnegative(),
    enrolled_count: z.number().int().nonnegative(),
    remaining_seats: z.number().int().nonnegative(),
  })
  .strict()

/**
 * One block a regular student may enrol into as a unit — every subject in
 * it is enrolled together. `seats_remaining` is the binding constraint
 * (the MIN across every subject's section), and `is_selectable` folds in
 * every other reason a block might be withheld.
 */
export const enrollmentBlockSchema = z
  .object({
    type: z.literal("enrollment_block"),
    block_code: z.string().min(1),
    year_level: z.number().int().min(1).max(4),
    curriculum_id: z.number().int().positive(),
    section_plan_id: z.number().int().positive().nullable(),
    total_units: z.number().nonnegative(),
    seats_remaining: z.number().int().nonnegative(),
    capacity: z.number().int().positive().nullable(),
    is_selectable: z.boolean(),
    reasons: z.array(enrollmentBlockReasonSchema),
    preference_score: z.number().int().nullable(),
    preference_reasons: z.array(z.string()),
    subjects: z.array(enrollmentBlockSubjectSchema).min(1),
  })
  .strict()

export const enrollmentBlocksEnvelopeSchema = z
  .object({ data: z.array(enrollmentBlockSchema) })
  .strict()

export type EnrollmentBlockReason = z.infer<typeof enrollmentBlockReasonSchema>
export type EnrollmentBlockSubject = z.infer<
  typeof enrollmentBlockSubjectSchema
>
export type EnrollmentBlock = z.infer<typeof enrollmentBlockSchema>
