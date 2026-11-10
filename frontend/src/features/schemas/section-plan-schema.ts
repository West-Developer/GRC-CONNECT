import { z } from "zod"

export const sectionPlanSchema = z
  .object({
    type: z.literal("academic-term-section-plan"),
    id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    college: z.enum(["ccs", "coe", "coa", "cbae"]),
    year_level: z.number().int().min(1).max(4),
    section_count: z.number().int().min(0),
    students_per_block: z.number().int().min(1),
    status: z.enum(["draft", "submitted"]),
    status_label: z.string(),
    submitted_at: z.string().nullable(),
    // Optional (not just nullable) so parsing stays safe whether or not the
    // backend resource has started emitting these yet — see the Demand
    // Forecast "why this section" rework's sequencing note.
    recommendation_source: z.string().nullable().optional(),
    recommended_section_count: z
      .number()
      .int()
      .nonnegative()
      .nullable()
      .optional(),
    recommendation_is_overridden: z.boolean().optional(),
    recommendation_prediction_run_id: z
      .number()
      .int()
      .positive()
      .nullable()
      .optional(),
  })
  .strict()

export const sectionPlansEnvelopeSchema = z
  .object({ data: z.array(sectionPlanSchema) })
  .strict()
// The auto-assign endpoint is the only section-plan endpoint that adds a
// sibling `meta` key alongside `data` (via Laravel's `->additional([...])`),
// so it needs its own envelope instead of reusing `sectionPlansEnvelopeSchema`
// — that one is `.strict()` and would throw on the extra key. `meta` itself
// stays `.passthrough()` so additional telemetry fields the backend adds
// later don't break parsing here.
export const sectionPlansAutoAssignEnvelopeSchema = z
  .object({
    data: z.array(sectionPlanSchema),
    meta: z.object({ sections_updated: z.number().int().min(0) }).passthrough(),
  })
  .strict()
export const sectionPlanCountsSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    counts: z
      .record(z.string(), z.number().int().min(0).max(99))
      .refine((value) => Object.keys(value).length === 4),
    // One entry per year level, matching `StoreSectionPlanRequest`'s
    // `size:4` rule on the same key.
    students_per_block: z
      .record(z.string(), z.number().int().min(1).max(300))
      .refine((value) => Object.keys(value).length === 4),
  })
  .strict()

export type SectionPlan = z.infer<typeof sectionPlanSchema>
export type SectionPlanCounts = z.infer<typeof sectionPlanCountsSchema>
