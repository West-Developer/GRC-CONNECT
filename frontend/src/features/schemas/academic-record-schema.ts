import { z } from "zod"

import { gradeMarkValues } from "@/features/schemas/academic-grade-schema"

const gradeStatusValues = ["draft", "submitted", "locked"] as const
const semesterSlotValues = ["1st", "2nd"] as const

export const prospectusPrerequisiteSchema = z
  .object({
    subject_id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    minimum_grade: z.string().min(1),
  })
  .strict()

export const prospectusEntryCreditSchema = z
  .object({
    source_institution: z.string().min(1),
    source_subject_title: z.string().min(1),
    source_grade: z.string().nullable(),
    status: z.enum(["approved", "endorsed"]),
    status_label: z.string().min(1),
    awaiting_registrar_head_approval: z.boolean(),
  })
  .strict()

export const prospectusEntrySchema = z
  .object({
    subject_id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().nonnegative(),
    is_required: z.boolean(),
    offered_either_semester: z.boolean(),
    is_completion_only: z.boolean(),
    mark: z.enum(gradeMarkValues).nullable(),
    mark_label: z.string().nullable(),
    final_grade: z.string().nullable(),
    status: z.enum(gradeStatusValues).nullable(),
    status_label: z.string().nullable(),
    academic_term_id: z.number().int().positive().nullable(),
    term_label: z.string().nullable(),
    attempt_count: z.number().int().nonnegative(),
    // The previous school's grade for a subject credited to this one (ADR 0050): "approved" once the
    // Registrar Head approved it, "endorsed" while the Program Chair has passed it on and the Head has
    // yet to decide. Null when nothing is credited to the subject (and for a server that predates it).
    credit: prospectusEntryCreditSchema.nullable().default(null),
    prerequisites: z.array(prospectusPrerequisiteSchema),
  })
  .strict()

export const prospectusSemesterSchema = z
  .object({
    year_level: z.number().int().min(1).max(4),
    semester: z.enum(semesterSlotValues),
    semester_label: z.string().min(1),
    entries: z.array(prospectusEntrySchema),
  })
  .strict()

export const prospectusUnplacedEntrySchema = z
  .object({
    subject_id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().nonnegative(),
    mark: z.enum(gradeMarkValues).nullable(),
    mark_label: z.string().nullable(),
    final_grade: z.string().nullable(),
    status: z.enum(gradeStatusValues),
    status_label: z.string().min(1),
    academic_term_id: z.number().int().positive(),
    term_label: z.string().min(1),
  })
  .strict()

export const prospectusSchema = z
  .object({
    type: z.literal("prospectus"),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    curriculum_id: z.number().int().positive(),
    curriculum_name: z.string().min(1),
    effective_school_year: z.string().min(1),
    year_level: z.number().int().min(1).max(4),
    enrollment_category: z.string().nullable(),
    enrollment_category_label: z.string().nullable(),
    enrollment_category_derived_at: z.iso.datetime().nullable(),
    curriculum_transition: z
      .object({
        source_curriculum_name: z.string().min(1),
        target_curriculum_name: z.string().min(1),
        migrated_at: z.iso.datetime(),
        credits: z.array(
          z
            .object({
              source_code: z.string().min(1),
              source_title: z.string().min(1),
              target_code: z.string().min(1),
              target_title: z.string().min(1),
            })
            .strict(),
        ),
      })
      .nullable()
      .optional(),
    // Subjects credited from another school (approved by the Registrar and
    // mapped to a GRC subject by the Program Chair; ADR 0026). They carry no
    // GRC grade. Defaults to none for a server that predates the field.
    transferee_credits: z
      .array(
        z
          .object({
            source_institution: z.string().min(1),
            source_subject_code: z.string(),
            source_subject_title: z.string().min(1),
            source_grade: z.string().nullable(),
            credited_units: z.number(),
            source_school_year: z.string().nullable(),
            source_semester: z.string().nullable(),
            target_code: z.string().nullable(),
            target_title: z.string().nullable(),
            // "approved" (credited) or "endorsed" (passed on by the Program Chair, awaiting the
            // Registrar Head, ADR 0050); defaults for a server that predates the fields.
            status: z.enum(["approved", "endorsed"]).default("approved"),
            status_label: z.string().min(1).default("Credited"),
            awaiting_registrar_head_approval: z.boolean().default(false),
          })
          .strict(),
      )
      .default([]),
    semesters: z.array(prospectusSemesterSchema),
    unplaced_entries: z.array(prospectusUnplacedEntrySchema),
  })
  .strict()

export const prospectusEnvelopeSchema = z
  .object({ data: prospectusSchema })
  .strict()

export const gradeSlipRowSchema = z
  .object({
    academic_grade_id: z.number().int().positive(),
    subject_id: z.number().int().positive().optional(),
    paired_subject_id: z.number().int().positive().nullable().optional(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().nonnegative(),
    mark: z.enum(gradeMarkValues).nullable(),
    mark_label: z.string().nullable(),
    final_grade: z.string().nullable(),
    section_code: z.string().nullable(),
    professor_name: z.string().nullable(),
    status: z.enum(gradeStatusValues),
    status_label: z.string().min(1),
    counts_toward_gpa: z.boolean(),
  })
  .strict()

export const gradeSlipSchema = z
  .object({
    type: z.literal("grade_slip"),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    year_level: z.number().int().min(1).max(4),
    enrollment_category: z.string().nullable(),
    enrollment_category_label: z.string().nullable(),
    academic_term_id: z.number().int().positive(),
    school_year: z.string().min(1),
    semester: z.string().min(1),
    term_label: z.string().min(1),
    rows: z.array(gradeSlipRowSchema),
    total_academic_units: z.number().nonnegative(),
    gpa_units: z.number().nonnegative(),
    // A string (or null), never a number -- mirrors final_grade's
    // precedent of never coercing a grade value to a float on the wire.
    gpa: z.string().nullable(),
    excluded_from_gpa_count: z.number().int().nonnegative(),
    generated_at: z.iso.datetime(),
  })
  .strict()

export const gradeSlipEnvelopeSchema = z
  .object({ data: gradeSlipSchema })
  .strict()

/**
 * One term's slice of `academicRecordSchema` — identical row/total fields
 * to `gradeSlipSchema`, minus the student-identity fields (hoisted to the
 * record's top level instead). `AcademicRecordView` reconstructs a full
 * `GradeSlip` by merging the record's student fields into whichever term
 * is selected, so `GradeSlipDocument` can render either shape unmodified.
 */
export const academicRecordTermSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    school_year: z.string().min(1),
    semester: z.string().min(1),
    term_label: z.string().min(1),
    rows: z.array(gradeSlipRowSchema),
    total_academic_units: z.number().nonnegative(),
    gpa_units: z.number().nonnegative(),
    gpa: z.string().nullable(),
    excluded_from_gpa_count: z.number().int().nonnegative(),
  })
  .strict()

export const academicRecordSchema = z
  .object({
    type: z.literal("academic_record"),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().optional(),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    year_level: z.number().int().min(1).max(4),
    enrollment_category: z.string().nullable(),
    enrollment_category_label: z.string().nullable(),
    // Latest term first -- the backend sorts this; the frontend never re-sorts.
    terms: z.array(academicRecordTermSchema),
  })
  .strict()

export const academicRecordEnvelopeSchema = z
  .object({ data: academicRecordSchema })
  .strict()

export const academicRecordStudentLookupSchema = z
  .object({
    type: z.literal("academic_record_student"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    name: z.string().min(1),
    first_name: z.string().min(1),
    last_name: z.string().optional().default(""),
    email: z.string().email(),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    year_level: z.number().int().min(1).max(4),
    enrollment_category: z.string().nullable(),
    enrollment_category_label: z.string().nullable(),
    academic_standing: z.string(),
    academic_standing_label: z.string(),
  })
  .strict()

export const academicRecordStudentLookupEnvelopeSchema = z
  .object({ data: z.array(academicRecordStudentLookupSchema) })
  .strict()

export type ProspectusEntry = z.infer<typeof prospectusEntrySchema>
export type ProspectusSemester = z.infer<typeof prospectusSemesterSchema>
export type ProspectusUnplacedEntry = z.infer<
  typeof prospectusUnplacedEntrySchema
>
export type Prospectus = z.infer<typeof prospectusSchema>
export type GradeSlipRow = z.infer<typeof gradeSlipRowSchema>
export type GradeSlip = z.infer<typeof gradeSlipSchema>
export type AcademicRecordTerm = z.infer<typeof academicRecordTermSchema>
export type AcademicRecord = z.infer<typeof academicRecordSchema>
export type AcademicRecordStudentLookup = z.infer<
  typeof academicRecordStudentLookupSchema
>
