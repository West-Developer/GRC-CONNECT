import { z } from "zod"

const timeSchema = z.string().regex(/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/, {
  message: "Use a 24-hour time in HH:mm:ss format.",
})

export const facultyAvailabilitySchema = z
  .object({
    type: z.literal("faculty_availability"),
    id: z.number().int().positive(),
    professor_id: z.number().int().positive(),
    day_of_week: z.number().int().min(1).max(6),
    starts_at_time: timeSchema,
    ends_at_time: timeSchema,
  })
  .strict()

export const facultyAvailabilitiesEnvelopeSchema = z
  .object({ data: z.array(facultyAvailabilitySchema) })
  .strict()

export const facultyAvailabilityEnvelopeSchema = z
  .object({ data: facultyAvailabilitySchema })
  .strict()

const availabilityInputShape = {
  day_of_week: z.number().int().min(1).max(6),
  starts_at_time: timeSchema,
  ends_at_time: timeSchema,
}

export const facultyAvailabilityInputSchema = z
  .object(availabilityInputShape)
  .strict()
  .superRefine((value, context) => {
    if (value.ends_at_time <= value.starts_at_time) {
      context.addIssue({
        code: "custom",
        path: ["ends_at_time"],
        message: "End time must be after start time.",
      })
    }
  })

export const facultySubjectPreferenceSchema = z
  .object({
    type: z.literal("faculty_subject_preference"),
    id: z.number().int().positive(),
    professor_id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    rank: z.number().int().positive(),
  })
  .strict()

export const facultySubjectPreferencesEnvelopeSchema = z
  .object({ data: z.array(facultySubjectPreferenceSchema) })
  .strict()

export const facultySubjectPreferenceEnvelopeSchema = z
  .object({ data: facultySubjectPreferenceSchema })
  .strict()

export const facultySubjectPreferenceInputSchema = z
  .object({
    academic_term_id: z.number().int().positive("Select an academic term."),
    subject_id: z.number().int().positive("Select a subject."),
    rank: z.number().int().positive("Rank must be at least 1."),
  })
  .strict()

export type FacultyAvailability = z.infer<typeof facultyAvailabilitySchema>
export type FacultyAvailabilityInput = z.infer<
  typeof facultyAvailabilityInputSchema
>
export type FacultySubjectPreference = z.infer<
  typeof facultySubjectPreferenceSchema
>
export type FacultySubjectPreferenceInput = z.infer<
  typeof facultySubjectPreferenceInputSchema
>

const curriculumSubjectSchema = z
  .object({
    id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().nonnegative(),
    paired_subject_id: z.number().int().positive().nullable().optional(),
  })
  .strict()

export const facultyPreferenceCatalogEntrySchema = z
  .object({
    curriculum_id: z.number().int().positive(),
    program_id: z.number().int().positive(),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    curriculum_name: z.string().min(1),
    effective_school_year: z.string().min(1),
    version_label: z.enum(["new", "old"]),
    semesters: z
      .array(
        z
          .object({
            semester: z.enum(["1st", "2nd"]),
            subjects: z.array(curriculumSubjectSchema),
          })
          .strict(),
      )
      .length(2),
  })
  .strict()

export const facultyPreferenceCatalogEnvelopeSchema = z
  .object({ data: z.array(facultyPreferenceCatalogEntrySchema) })
  .strict()

export const facultyCurriculumSubjectPreferenceSchema = z
  .object({
    type: z.literal("faculty_curriculum_subject_preference"),
    id: z.number().int().positive(),
    professor_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    semester: z.enum(["1st", "2nd"]),
    rank: z.number().int().positive(),
    origin: z.enum(["declared", "workbook_seeded"]),
  })
  .strict()

export const facultyCurriculumSubjectPreferencesEnvelopeSchema = z
  .object({ data: z.array(facultyCurriculumSubjectPreferenceSchema) })
  .strict()

export const facultyCurriculumSubjectPreferenceEnvelopeSchema = z
  .object({ data: facultyCurriculumSubjectPreferenceSchema })
  .strict()

export const facultyCurriculumSubjectPreferenceInputSchema = z
  .object({
    curriculum_id: z.number().int().positive("Select a curriculum."),
    semester: z.enum(["1st", "2nd"]),
    subject_id: z.number().int().positive("Select a subject."),
    rank: z.number().int().positive("Rank must be at least 1.").optional(),
  })
  .strict()

export const facultySpecializationSchema = z
  .object({
    type: z.literal("faculty-specialization"),
    id: z.number().int().positive(),
    professor_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    proficiency: z.enum(["primary", "secondary"]),
    proficiency_label: z.string().min(1),
    source: z.enum(["declared", "seeded", "program_chair_assigned"]),
    notes: z.string().nullable(),
    status: z.enum(["pending", "approved", "rejected"]),
    status_label: z.string().min(1),
    decided_at: z.string().nullable(),
    decision_reason: z.string().nullable(),
  })
  .strict()

export const facultySpecializationsEnvelopeSchema = z
  .object({ data: z.array(facultySpecializationSchema) })
  .strict()

export const facultySpecializationEnvelopeSchema = z
  .object({ data: facultySpecializationSchema })
  .strict()

export const facultySpecializationInputSchema = z
  .object({
    professor_id: z.number().int().positive().optional(),
    subject_id: z.number().int().positive("Select a subject."),
    proficiency: z.enum(["primary", "secondary"]),
  })
  .strict()

export const decideFacultySpecializationInputSchema = z
  .object({
    action: z.enum(["approve", "reject"]),
    reason: z.string().trim().min(1).max(1000).optional(),
  })
  .strict()

export const facultyTeachingHistorySchema = z
  .object({
    type: z.literal("faculty_teaching_history"),
    id: z.number().int().positive(),
    professor_id: z.number().int().positive(),
    curriculum_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    semester: z.enum(["1st", "2nd"]),
    source_kind: z.string().min(1),
    source_workbook: z.string().min(1),
    raw_alias: z.string().nullable(),
    evidence_count: z.number().int().positive(),
    subject_code: z.string().nullable(),
    subject_title: z.string().nullable(),
    curriculum_name: z.string().nullable(),
  })
  .strict()

export const facultyTeachingHistoriesEnvelopeSchema = z
  .object({ data: z.array(facultyTeachingHistorySchema) })
  .strict()

export type FacultyPreferenceCatalogEntry = z.infer<
  typeof facultyPreferenceCatalogEntrySchema
>
export type FacultyCurriculumSubjectPreference = z.infer<
  typeof facultyCurriculumSubjectPreferenceSchema
>
export type FacultyCurriculumSubjectPreferenceInput = z.infer<
  typeof facultyCurriculumSubjectPreferenceInputSchema
>
export type FacultySpecialization = z.infer<typeof facultySpecializationSchema>
export type FacultySpecializationInput = z.infer<
  typeof facultySpecializationInputSchema
>
export type DecideFacultySpecializationInput = z.infer<
  typeof decideFacultySpecializationInputSchema
>
export type FacultyTeachingHistory = z.infer<
  typeof facultyTeachingHistorySchema
>
