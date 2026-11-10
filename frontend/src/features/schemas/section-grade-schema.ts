import { z } from "zod"

import { gradeMarkValues } from "@/features/schemas/academic-grade-schema"

export const gradeSectionStateValues = [
  "not_started",
  "in_progress",
  "ready",
  "submitted",
  "locked",
] as const

export const gradeSectionSummarySchema = z
  .object({
    type: z.literal("grade_section_summary"),
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject: z
      .object({
        id: z.number().int().positive(),
        code: z.string().min(1),
        title: z.string().min(1),
        is_completion_only: z.boolean(),
      })
      .strict(),
    academic_term: z
      .object({
        id: z.number().int().positive(),
        school_year: z.string().min(1),
        semester: z.string().min(1),
      })
      .strict(),
    schedule: z
      .object({
        days: z.string().nullable(),
        starts_at_time: z.string().nullable(),
        ends_at_time: z.string().nullable(),
      })
      .strict(),
    enrolled_count: z.number().int().nonnegative(),
    recorded_count: z.number().int().nonnegative(),
    submitted_count: z.number().int().nonnegative(),
    locked_count: z.number().int().nonnegative(),
    missing_count: z.number().int().nonnegative(),
    state: z.enum(gradeSectionStateValues),
  })
  .strict()

export const sectionGradeRowSchema = z
  .object({
    enrollment_subject_id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    grade_id: z.number().int().positive().nullable(),
    mark: z.enum(gradeMarkValues).nullable(),
    mark_label: z.string().nullable(),
    remarks: z.string().nullable(),
    status: z.enum(["not_recorded", "draft", "submitted", "locked"]),
    status_label: z.string().min(1),
  })
  .strict()

export const sectionGradeSheetSchema = z
  .object({
    type: z.literal("section_grade_sheet"),
    section: gradeSectionSummarySchema,
    rows: z.array(sectionGradeRowSchema),
  })
  .strict()

export const gradeSectionSummariesEnvelopeSchema = z
  .object({ data: z.array(gradeSectionSummarySchema) })
  .strict()

export const sectionGradeSheetEnvelopeSchema = z
  .object({ data: sectionGradeSheetSchema })
  .strict()

export const saveSectionGradeDraftsInputSchema = z
  .object({
    grades: z
      .array(
        z
          .object({
            student_id: z.number().int().positive(),
            mark: z.enum(gradeMarkValues),
            remarks: z.string().nullable().optional(),
          })
          .strict(),
      )
      .min(1),
  })
  .strict()

export type GradeSectionSummary = z.infer<typeof gradeSectionSummarySchema>
export type SectionGradeRow = z.infer<typeof sectionGradeRowSchema>
export type SectionGradeSheet = z.infer<typeof sectionGradeSheetSchema>
export type SaveSectionGradeDraftsInput = z.infer<
  typeof saveSectionGradeDraftsInputSchema
>
