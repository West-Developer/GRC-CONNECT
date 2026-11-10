import { z } from "zod"

export const subjectOfferingSchema = z
  .object({
    type: z.literal("subject-offering"),
    id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    subject_units: z.number(),
    year_level: z.number().int().positive(),
    semester: z.string().min(1),
    min_section_capacity: z.number().int().positive(),
    max_section_capacity: z.number().int().positive(),
    recommended_sections: z.number().int().nonnegative(),
  })
  .strict()

export const subjectOfferingsEnvelopeSchema = z
  .object({ data: z.array(subjectOfferingSchema) })
  .strict()

export const subjectOfferingFiltersSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
  })
  .strict()

export const subjectOfferingInputSchema = z
  .object({
    subject_id: z.number().int().positive(),
    year_level: z.number().int().positive("Year level must be at least 1."),
    semester: z.string().trim().min(1, "Select a semester."),
    min_section_capacity: z
      .number()
      .int()
      .min(1, "Enter a minimum section capacity."),
    max_section_capacity: z
      .number()
      .int()
      .min(1, "Enter a maximum section capacity."),
    recommended_sections: z
      .number()
      .int()
      .min(0, "Enter a recommended section count."),
  })
  .strict()
  .refine((value) => value.max_section_capacity >= value.min_section_capacity, {
    message: "Maximum capacity must be at least the minimum.",
    path: ["max_section_capacity"],
  })

export const replaceSubjectOfferingsInputSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    offerings: z.array(subjectOfferingInputSchema),
  })
  .strict()

export type SubjectOffering = z.infer<typeof subjectOfferingSchema>
export type SubjectOfferingFilters = z.infer<
  typeof subjectOfferingFiltersSchema
>
export type SubjectOfferingInput = z.infer<typeof subjectOfferingInputSchema>
export type ReplaceSubjectOfferingsInput = z.infer<
  typeof replaceSubjectOfferingsInputSchema
>
