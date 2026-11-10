import { z } from "zod"

const optionalUtcDateTimeSchema = z.iso.datetime().nullable()

export const academicTermWorkflowSchema = z
  .object({
    type: z.literal("academic-term-workflow"),
    id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    college: z.enum(["ccs", "coe", "coa", "cbae"]),
    college_label: z.string().min(1),
    stage: z.enum([
      "draft",
      "curriculum_preparation",
      "faculty_input",
      "schedule_preparation",
      "for_dean_approval",
    ]),
    stage_label: z.string().min(1),
    curriculum_completed_at: optionalUtcDateTimeSchema,
    faculty_reviewed_at: optionalUtcDateTimeSchema,
    schedule_submitted_at: optionalUtcDateTimeSchema,
  })
  .strict()

export const academicTermWorkflowsEnvelopeSchema = z
  .object({ data: z.array(academicTermWorkflowSchema) })
  .strict()

export type AcademicTermWorkflow = z.infer<typeof academicTermWorkflowSchema>

export const academicTermWorkflowActionSchema = z
  .object({
    action: z.enum([
      "start_curriculum_preparation",
      "complete_curriculum_preparation",
      "complete_faculty_input",
    ]),
  })
  .strict()

export type AcademicTermWorkflowAction = z.infer<
  typeof academicTermWorkflowActionSchema
>
