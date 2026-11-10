import { z } from "zod"

export const enrollmentMovementTypes = [
  "drops",
  "withdrawals",
  "shifts",
] as const
export type EnrollmentMovementType = (typeof enrollmentMovementTypes)[number]

/** Mirrors `BuildEnrollmentMovementReport` (ADR 0034). Counts only. */
export const enrollmentMovementsEnvelopeSchema = z
  .object({
    data: z
      .object({
        type: z.literal("enrollment_movements"),
        movement: z.enum(enrollmentMovementTypes),
        academic_term_id: z.number().int().positive(),
        total: z.number().int().nonnegative(),
        by_department: z.array(
          z
            .object({
              college: z.string().min(1),
              label: z.string().min(1),
              count: z.number().int().nonnegative(),
            })
            .strict(),
        ),
        groups: z.array(
          z
            .object({
              label: z.string().min(1),
              count: z.number().int().nonnegative(),
              from_program_code: z.string().nullable(),
              to_program_code: z.string().nullable(),
            })
            .strict(),
        ),
      })
      .strict(),
  })
  .strict()

export const programShiftResponseSchema = z
  .object({
    data: z
      .object({
        type: z.literal("program_shift"),
        id: z.number().int().positive(),
        from_program_id: z.number().int().positive(),
        to_program_id: z.number().int().positive(),
        academic_term_id: z.number().int().positive(),
      })
      .strict(),
  })
  .strict()

export const recordProgramShiftInputSchema = z
  .object({
    student_number: z.string().trim().min(1, "Enter the student number."),
    to_program_id: z.number().int().positive("Choose the new course."),
    academic_term_id: z.number().int().positive(),
    reason: z
      .string()
      .trim()
      .min(3, "Give a reason of at least 3 characters.")
      .max(1000),
  })
  .strict()

export type EnrollmentMovements = z.infer<
  typeof enrollmentMovementsEnvelopeSchema
>["data"]
export type RecordProgramShiftInput = z.infer<
  typeof recordProgramShiftInputSchema
>
