import { z } from "zod"

/** Mirrors `BuildFacultyProfile` (stakeholder Doc 14, S17). Counts only. */
const gradeCountsSchema = z
  .object({
    draft: z.number().int().nonnegative(),
    submitted: z.number().int().nonnegative(),
    locked: z.number().int().nonnegative(),
    total: z.number().int().nonnegative(),
  })
  .strict()

export const facultyProfileSectionSchema = z
  .object({
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number().nonnegative(),
    schedule_days: z.string().nullable(),
    starts_at_time: z.string().nullable(),
    ends_at_time: z.string().nullable(),
    room: z.string().nullable(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable(),
    status: z.string().min(1),
    enrolled_count: z.number().int().nonnegative(),
    capacity: z.number().int().nonnegative(),
    grades: gradeCountsSchema,
  })
  .strict()

export const facultyProfileSchema = z
  .object({
    professor: z
      .object({
        id: z.number().int().positive(),
        name: z.string().min(1),
        college: z.string().nullable(),
        status: z.string().min(1),
        employment_type: z.enum(["full_time", "part_time"]).nullable(),
        employment_type_label: z.string().nullable(),
        masters_degree: z.string().nullable(),
      })
      .strict(),
    terms: z.array(
      z
        .object({
          academic_term_id: z.number().int().positive(),
          label: z.string().min(1),
          sections_count: z.number().int().positive(),
          total_units: z.number().nonnegative(),
        })
        .strict(),
    ),
    selected_term: z
      .object({
        academic_term_id: z.number().int().positive(),
        label: z.string().min(1),
        total_units: z.number().nonnegative(),
        sections: z.array(facultyProfileSectionSchema),
      })
      .strict()
      .nullable(),
  })
  .strict()

export const facultyProfileEnvelopeSchema = z
  .object({ data: facultyProfileSchema })
  .strict()

export type FacultyProfile = z.infer<typeof facultyProfileSchema>
export type FacultyProfileSection = z.infer<typeof facultyProfileSectionSchema>
