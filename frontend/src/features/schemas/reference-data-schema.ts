import { z } from "zod"

const optionalUtcDateTimeSchema = z.iso.datetime().nullable()

export const programSchema = z
  .object({
    type: z.literal("program"),
    id: z.number().int().positive(),
    code: z.string().min(1),
    name: z.string().min(1),
    status: z.enum(["active", "inactive"]),
    status_label: z.string().min(1),
  })
  .strict()

export const programsEnvelopeSchema = z
  .object({ data: z.array(programSchema) })
  .strict()

export const academicTermSchema = z
  .object({
    type: z.literal("academic-term"),
    id: z.number().int().positive(),
    school_year: z.string().min(1),
    semester: z.string().min(1),
    starts_at: optionalUtcDateTimeSchema,
    ends_at: optionalUtcDateTimeSchema,
    enrollment_opens_at: optionalUtcDateTimeSchema,
    enrollment_closes_at: optionalUtcDateTimeSchema,
    add_drop_opens_at: optionalUtcDateTimeSchema.optional(),
    add_drop_deadline_at: optionalUtcDateTimeSchema,
    // Set once per term by the Registrar Head; printed on every COR of the term.
    enrollment_platform: z
      .enum(["online", "face_to_face", "both"])
      .nullable()
      .optional(),
    enrollment_platform_label: z.string().nullable().optional(),
    grading_deadline_at: optionalUtcDateTimeSchema,
    closed_at: optionalUtcDateTimeSchema.optional(),
    archived_at: optionalUtcDateTimeSchema.optional(),
    status: z.enum([
      "draft",
      "for_dean_approval",
      "semester_ongoing",
      "semester_closed",
      "archived",
    ]),
    status_label: z.string().min(1),
    is_actionable_current: z.boolean().optional(),
    next_term_sequence: z
      .object({
        school_year: z.string(),
        semester: z.enum(["1st", "2nd"]),
      })
      .nullable()
      .optional(),
  })
  .strict()

export const academicTermsEnvelopeSchema = z
  .object({ data: z.array(academicTermSchema) })
  .strict()

export const subjectSchema = z
  .object({
    type: z.literal("subject"),
    id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    // Imported catalog placeholders can temporarily carry zero units. The
    // curriculum placement remains the authoritative source for generated
    // section units, so a zero here must not invalidate the entire catalog.
    units: z.number().nonnegative(),
    status: z.enum(["active", "inactive"]),
    status_label: z.string().min(1),
    is_completion_only: z.boolean(),
    // Optional: added for the room scheduler's lecture/laboratory pairing
    // rule. Declared optional so fixtures predating this field still parse.
    college: z.string().nullable().optional(),
    paired_subject_id: z.number().int().positive().nullable().optional(),
    room_requirement: z.enum(["lecture", "laboratory"]).nullable().optional(),
  })
  .strict()

export const subjectsEnvelopeSchema = z
  .object({ data: z.array(subjectSchema) })
  .strict()

export const sectionSchema = z
  .object({
    type: z.literal("section"),
    id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    section_plan_id: z.number().int().positive().nullable().optional(),
    subject_id: z.number().int().positive(),
    section_code: z.string().min(1),
    professor_id: z.number().int().positive().nullable(),
    professor_name: z.string().nullable().optional(),
    schedule_days: z.string().min(1).nullable(),
    starts_at_time: z.string().min(1).nullable(),
    ends_at_time: z.string().min(1).nullable(),
    room: z.string().min(1).nullable(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable().optional(),
    capacity: z.number().int().nonnegative(),
    capacity_source: z.enum(["plan", "manual"]),
    recommendation_source: z.string().nullable().optional(),
    recommended_capacity: z.number().int().positive().nullable().optional(),
    recommendation_prediction_run_id: z
      .number()
      .int()
      .positive()
      .nullable()
      .optional(),
    manual_override_reason: z.string().nullable().optional(),
    viability_threshold: z.number().int().positive().nullable(),
    enrolled_count: z.number().int().nonnegative(),
    remaining_seats: z.number().int().nonnegative(),
    // Null on rows created before block generation set the flag; the
    // enrollment pool treats null as "open to everyone".
    is_block_exclusive: z.boolean().nullable(),
    status: z.enum(["draft", "planned", "published", "closed", "cancelled"]),
    status_label: z.string().min(1),
  })
  .strict()

export const sectionsEnvelopeSchema = z
  .object({ data: z.array(sectionSchema) })
  .strict()

const curriculumSubjectSchema = z
  .object({
    subject_id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().positive().optional(),
    year_level: z.number().int().positive(),
    semester: z.string().min(1),
    is_required: z.boolean(),
    equivalent_source_subject_id: z
      .number()
      .int()
      .positive()
      .nullable()
      .optional(),
    equivalent_source_subject_code: z.string().min(1).nullable().optional(),
    equivalent_source_subject_title: z.string().min(1).nullable().optional(),
    prerequisites: z.array(
      z
        .object({
          prerequisite_subject_id: z.number().int().positive(),
          code: z.string().min(1),
          minimum_grade: z.string().min(1),
        })
        .strict(),
    ),
  })
  .strict()

export const curriculumSchema = z
  .object({
    type: z.literal("curriculum"),
    id: z.number().int().positive(),
    program_id: z.number().int().positive(),
    equivalency_source_curriculum_id: z
      .number()
      .int()
      .positive()
      .nullable()
      .optional(),
    equivalency_source_curriculum_name: z.string().min(1).nullable().optional(),
    name: z.string().min(1),
    effective_school_year: z.string().min(1),
    status: z.enum([
      "draft",
      "pending_dean_review",
      "pending_executive_review",
      "active",
      "archived",
    ]),
    status_label: z.string().min(1),
    max_units: z.number().nullable().optional(),
    default_max_units: z.number().optional(),
    effective_max_units: z.number().optional(),
    year_level_max_units: z
      .record(z.string(), z.number())
      .or(z.array(z.number()))
      .optional(),
    decided_at: z.string().nullable(),
    last_decision_reason: z.string().nullable(),
    subjects: z.array(curriculumSubjectSchema),
  })
  .strict()

export const curriculaEnvelopeSchema = z
  .object({ data: z.array(curriculumSchema) })
  .strict()

export type AcademicTerm = z.infer<typeof academicTermSchema>
export type Program = z.infer<typeof programSchema>
export type Subject = z.infer<typeof subjectSchema>
export type Section = z.infer<typeof sectionSchema>
export type Curriculum = z.infer<typeof curriculumSchema>
