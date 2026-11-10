import { z } from "zod"

const migrationSubjectSchema = z
  .object({
    id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
  })
  .strict()

export const curriculumMigrationPreviewSchema = z
  .object({
    student: z
      .object({
        id: z.number().int().positive(),
        student_number: z.string().min(1),
      })
      .strict(),
    source_curriculum_id: z.number().int().positive(),
    target_curriculum_id: z.number().int().positive(),
    credit_candidates: z.array(
      z
        .object({
          equivalency_id: z.number().int().positive(),
          source_subject: migrationSubjectSchema,
          target_subject: migrationSubjectSchema,
          source_completion: z
            .object({
              academic_grade_id: z.number().int().positive(),
              final_grade: z.string().min(1).nullable(),
            })
            .strict(),
        })
        .strict(),
    ),
  })
  .strict()

export const curriculumMigrationPreviewEnvelopeSchema = z
  .object({ data: curriculumMigrationPreviewSchema })
  .strict()

export const curriculumMigrationResultSchema = z
  .object({
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    source_curriculum_id: z.number().int().positive(),
    target_curriculum_id: z.number().int().positive(),
    credited_subject_ids: z.array(z.number().int().positive()),
    migrated_at: z.iso.datetime(),
  })
  .strict()

export const curriculumMigrationResultEnvelopeSchema = z
  .object({ data: curriculumMigrationResultSchema })
  .strict()

export type CurriculumMigrationPreview = z.infer<
  typeof curriculumMigrationPreviewSchema
>
export type CurriculumMigrationResult = z.infer<
  typeof curriculumMigrationResultSchema
>
