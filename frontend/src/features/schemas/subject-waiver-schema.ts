import { z } from "zod"

export const subjectWaiverSchema = z
  .object({
    type: z.literal("subject_waiver"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    academic_term_id: z.number().int().positive(),
    reason: z.string().min(1),
    is_active: z.boolean(),
    granted_at: z.iso.datetime(),
    revoked_at: z.iso.datetime().nullable(),
  })
  .strict()

export const blockedSubjectSchema = z
  .object({
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    reasons: z.array(z.string()),
  })
  .strict()

export const subjectWaiverOverviewSchema = z
  .object({
    data: z.array(subjectWaiverSchema),
    meta: z
      .object({ blocked_subjects: z.array(blockedSubjectSchema) })
      .strict(),
  })
  .strict()

export const subjectWaiverEnvelopeSchema = z
  .object({ data: subjectWaiverSchema })
  .strict()

export const grantSubjectWaiverInputSchema = z
  .object({
    subject_id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    reason: z
      .string()
      .trim()
      .min(3, "Give a reason of at least 3 characters.")
      .max(1000),
  })
  .strict()

export type SubjectWaiver = z.infer<typeof subjectWaiverSchema>
export type BlockedSubject = z.infer<typeof blockedSubjectSchema>
export type SubjectWaiverOverview = z.infer<typeof subjectWaiverOverviewSchema>
export type GrantSubjectWaiverInput = z.input<
  typeof grantSubjectWaiverInputSchema
>
