import { z } from "zod"

export const withdrawableSubjectSchema = z
  .object({
    enrollment_subject_id: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    term_label: z.string().min(1),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number(),
    section_code: z.string().min(1),
  })
  .strict()

export const withdrawnSubjectSchema = z
  .object({
    academic_grade_id: z.number().int().positive(),
    term_label: z.string().min(1),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    section_code: z.string().nullable(),
    reason: z.string().nullable(),
    withdrawn_by: z.string().min(1),
    withdrawn_at: z.iso.datetime().nullable(),
  })
  .strict()

export const subjectWithdrawalOverviewSchema = z
  .object({
    data: z
      .object({
        withdrawable: z.array(withdrawableSubjectSchema),
        withdrawn: z.array(withdrawnSubjectSchema),
      })
      .strict(),
  })
  .strict()

export const withdrawSubjectsInputSchema = z
  .object({
    enrollment_subject_ids: z.array(z.number().int().positive()).min(1).max(20),
    reason: z.string().trim().min(3).max(1000),
  })
  .strict()

export type WithdrawableSubject = z.infer<typeof withdrawableSubjectSchema>
export type WithdrawnSubject = z.infer<typeof withdrawnSubjectSchema>
export type SubjectWithdrawalOverview = z.infer<
  typeof subjectWithdrawalOverviewSchema
>["data"]
export type WithdrawSubjectsInput = z.infer<typeof withdrawSubjectsInputSchema>
