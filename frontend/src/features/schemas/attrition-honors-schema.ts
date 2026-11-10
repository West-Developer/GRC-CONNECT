import { z } from "zod"

const metricsSchema = z
  .object({
    baseline_count: z.number().int().nonnegative(),
    retained_count: z.number().int().nonnegative(),
    attrited_count: z.number().int().nonnegative(),
    attrition_rate: z.number().nonnegative(),
  })
  .strict()

// The report's own summary adds two informational counts that are never part of
// attrition: students still deciding (or enrolling) and graduates.
const attritionSummarySchema = metricsSchema.extend({
  undecided_count: z.number().int().nonnegative().optional(),
  graduated_count: z.number().int().nonnegative().optional(),
})

export const attritionReportEnvelopeSchema = z
  .object({
    data: z
      .object({
        type: z.literal("attrition_report"),
        baseline_term: z
          .object({
            id: z.number().int().positive(),
            school_year: z.string(),
            semester: z.string(),
          })
          .strict(),
        comparison_term: z
          .object({
            id: z.number().int().positive(),
            school_year: z.string(),
            semester: z.string(),
          })
          .strict(),
        generated_at: z.string(),
        summary: attritionSummarySchema,
        groups: z
          .object({
            colleges: z.array(
              z
                .object({ college: z.string().nullable() })
                .passthrough()
                .and(metricsSchema),
            ),
            programs: z.array(
              z
                .object({
                  college: z.string().nullable(),
                  program_id: z.number().int(),
                  program_code: z.string(),
                  program_name: z.string(),
                })
                .passthrough()
                .and(metricsSchema),
            ),
            year_levels: z.array(
              z
                .object({ year_level: z.number().int() })
                .passthrough()
                .and(metricsSchema),
            ),
          })
          .strict(),
      })
      .strict(),
  })
  .strict()

const honorSchema = z
  .object({
    type: z.literal("honor_student"),
    student_id: z.number().int(),
    student_number: z.string(),
    student_name: z.string(),
    program_id: z.number().int(),
    program_code: z.string(),
    program_name: z.string(),
    college: z.string().nullable(),
    year_level: z.number().int(),
    academic_term_id: z.number().int(),
    school_year: z.string(),
    semester: z.string(),
    gwa: z.string(),
    gwa_units: z.number(),
    excluded_subject_count: z.number().int(),
  })
  .strict()

export const honorsReportEnvelopeSchema = z
  .object({
    data: z.array(honorSchema),
    summary: z
      .object({ qualifier_count: z.number().int().nonnegative() })
      .strict(),
    meta: z
      .object({
        current_page: z.number().int(),
        last_page: z.number().int(),
        per_page: z.number().int(),
        total: z.number().int(),
      })
      .passthrough(),
  })
  .strict()

export type AttritionReport = z.infer<
  typeof attritionReportEnvelopeSchema
>["data"]
export type HonorsReport = z.infer<typeof honorsReportEnvelopeSchema>
