import { z } from "zod"

/** Mirrors `BuildAdmissionChecklist` (ADR 0037). */
export const admissionRequirementCategorySchema = z.enum([
  "freshman",
  "transferee",
  "additional",
])

export const admissionRequirementItemSchema = z
  .object({
    requirement_type_id: z.number().int().positive(),
    name: z.string().min(1),
    is_system: z.boolean(),
    is_submitted: z.boolean(),
    submitted_at: z.iso.datetime().nullable(),
  })
  .strict()

export const admissionChecklistSchema = z
  .object({
    type: z.literal("admission_requirements"),
    student: z
      .object({
        student_profile_id: z.number().int().positive(),
        student_number: z.string().min(1),
        name: z.string().min(1),
        student_type: z.string().nullable(),
        student_type_label: z.string().nullable(),
        admission_status: z.string().min(1),
      })
      .strict(),
    categories: z.array(
      z
        .object({
          category: admissionRequirementCategorySchema,
          label: z.string().min(1),
          items: z.array(admissionRequirementItemSchema),
        })
        .strict(),
    ),
    summary: z
      .object({
        required_count: z.number().int().nonnegative(),
        submitted_count: z.number().int().nonnegative(),
        missing_count: z.number().int().nonnegative(),
        complete: z.boolean(),
      })
      .strict(),
  })
  .strict()

export const admissionChecklistEnvelopeSchema = z
  .object({ data: admissionChecklistSchema })
  .strict()

export const setAdmissionRequirementSchema = z
  .object({ is_submitted: z.boolean() })
  .strict()

export const createAdmissionRequirementTypeSchema = z
  .object({
    name: z
      .string()
      .trim()
      .min(2, "Enter at least 2 characters.")
      .max(160, "Use at most 160 characters."),
    category: admissionRequirementCategorySchema,
  })
  .strict()

export const admissionRequirementTypeEnvelopeSchema = z
  .object({
    data: z
      .object({
        type: z.literal("admission_requirement_type"),
        id: z.number().int().positive(),
        category: admissionRequirementCategorySchema,
        name: z.string().min(1),
        is_system: z.boolean(),
      })
      .strict(),
  })
  .strict()

/**
 * The requirements that apply to a new student of a year level, listed before any student record
 * exists (the Create Account checklist). Mirrors `ListApplicableAdmissionRequirements`.
 */
export const admissionRequirementSelectionSchema = z
  .object({
    type: z.literal("admission_requirement_selection"),
    student_type: z.string().min(1),
    student_type_label: z.string().min(1),
    categories: z.array(
      z
        .object({
          category: admissionRequirementCategorySchema,
          label: z.string().min(1),
          items: z.array(
            z
              .object({
                requirement_type_id: z.number().int().positive(),
                name: z.string().min(1),
                is_system: z.boolean(),
              })
              .strict(),
          ),
        })
        .strict(),
    ),
  })
  .strict()

export const admissionRequirementSelectionEnvelopeSchema = z
  .object({ data: admissionRequirementSelectionSchema })
  .strict()

export type AdmissionRequirementSelection = z.infer<
  typeof admissionRequirementSelectionSchema
>
export type AdmissionChecklist = z.infer<typeof admissionChecklistSchema>
export type AdmissionRequirementCategory = z.infer<
  typeof admissionRequirementCategorySchema
>
export type CreateAdmissionRequirementTypeInput = z.infer<
  typeof createAdmissionRequirementTypeSchema
>
