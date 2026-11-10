import { z } from "zod"

const gradeStatusValues = ["draft", "submitted", "locked"] as const

// Mirrors App\Domain\Academic\GradeMark's exact case order.
export const gradeMarkValues = [
  "1.00",
  "1.25",
  "1.50",
  "1.75",
  "2.00",
  "2.25",
  "2.50",
  "2.75",
  "3.00",
  "5.00",
  "C",
  "NC",
  "INC",
  "DRP",
  "W",
] as const

/** Leadership subjects use only Complete or Incomplete marks. */
export const completionOnlyMarkValues = ["C", "INC"] as const

/** Every mark an ordinary (non-completion-only) subject may take. */
export const academicMarkValues = [
  "1.00",
  "1.25",
  "1.50",
  "1.75",
  "2.00",
  "2.25",
  "2.50",
  "2.75",
  "3.00",
  "5.00",
  "INC",
] as const

export const academicGradeSchema = z
  .object({
    type: z.literal("academic_grade"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    section_id: z.number().int().positive().nullable(),
    academic_term_id: z.number().int().positive(),
    mark: z.enum(gradeMarkValues).nullable(),
    mark_label: z.string().nullable(),
    final_grade: z.string().nullable(),
    remarks: z.string().nullable(),
    status: z.enum(gradeStatusValues),
    status_label: z.string().min(1),
    submitted_at: z.iso.datetime().nullable(),
    locked_at: z.iso.datetime().nullable(),
    student_name: z.string().nullable().optional(),
    subject_title: z.string().nullable().optional(),
    section_code: z.string().nullable().optional(),
    professor_id: z.number().int().nullable().optional(),
    professor_name: z.string().nullable().optional(),
    college: z.string().nullable().optional(),
    school_year: z.string().nullable().optional(),
    semester: z.string().nullable().optional(),
  })
  .strict()

export const academicGradeEnvelopeSchema = z
  .object({ data: academicGradeSchema })
  .strict()

const paginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()
const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().min(1).max(500),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const paginatedAcademicGradesSchema = z
  .object({
    data: z.array(academicGradeSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const academicGradeFiltersSchema = z
  .object({
    student_id: z.number().int().positive().optional(),
    subject_id: z.number().int().positive().optional(),
    professor_id: z.number().int().positive().optional(),
    section_id: z.number().int().positive().optional(),
    academic_term_id: z.number().int().positive().optional(),
    status: z.enum(gradeStatusValues).optional(),
    search: z.string().optional(),
    college: z.string().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(500).default(20),
  })
  .strict()

/** One professor with grades awaiting lock: a row of the Registrar Head's approvals. */
export const gradeApprovalProfessorSchema = z
  .object({
    professor_id: z.number().int().positive(),
    professor_name: z.string().nullable(),
    college: z.string().nullable(),
    subject_count: z.number().int().nonnegative(),
    grade_count: z.number().int().nonnegative(),
  })
  .strict()

export const paginatedGradeApprovalProfessorsSchema = z
  .object({
    data: z.array(gradeApprovalProfessorSchema),
    meta: z
      .object({
        current_page: z.number().int().positive(),
        last_page: z.number().int().positive(),
        per_page: z.number().int().min(1).max(500),
        total: z.number().int().nonnegative(),
        /** Every grade awaiting lock under the current filter, over all professors. */
        total_grades: z.number().int().nonnegative(),
      })
      .strict(),
  })
  .strict()

export const gradeApprovalProfessorFiltersSchema = z
  .object({
    academic_term_id: z.number().int().positive().optional(),
    college: z.string().optional(),
    search: z.string().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(12),
  })
  .strict()

/** One section (a subject taught in a section) a professor has grades awaiting lock in. */
export const gradeApprovalSectionSchema = z
  .object({
    section_id: z.number().int().positive().nullable(),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().nullable(),
    section_code: z.string().nullable(),
    grade_count: z.number().int().nonnegative(),
  })
  .strict()

export const gradeApprovalSectionsEnvelopeSchema = z
  .object({ data: z.array(gradeApprovalSectionSchema) })
  .strict()

export const storeAcademicGradeInputSchema = z
  .object({
    student_id: z.number().int().positive(),
    subject_id: z.number().int().positive(),
    section_id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    mark: z.enum(gradeMarkValues).optional(),
    remarks: z.string().optional(),
  })
  .strict()

/**
 * No `action` means a plain content edit; `action: submit`/`lock` is a pure
 * status transition — mirroring the backend's `UpdateAcademicGradeRequest`,
 * content fields and `action` are never sent together.
 */
export const updateAcademicGradeInputSchema = z.union([
  z
    .object({
      mark: z.enum(gradeMarkValues).optional(),
      remarks: z.string().optional(),
    })
    .strict(),
  z.object({ action: z.enum(["submit", "lock"]) }).strict(),
])

export const lockAllAcademicGradesInputSchema = z
  .object({
    academic_term_id: z.number().int().positive().optional(),
    college: z.string().trim().optional(),
    grade_ids: z.array(z.number().int().positive()).optional(),
  })
  .strict()

export const lockAllAcademicGradesResultSchema = z
  .object({
    locked_count: z.number().int().nonnegative(),
    message: z.string().min(1),
  })
  .strict()

export const lockAllAcademicGradesEnvelopeSchema = z
  .object({
    data: lockAllAcademicGradesResultSchema,
  })
  .strict()

export type AcademicGrade = z.infer<typeof academicGradeSchema>
export type AcademicGradeFilters = z.input<typeof academicGradeFiltersSchema>
export type GradeApprovalProfessor = z.infer<
  typeof gradeApprovalProfessorSchema
>
export type PaginatedGradeApprovalProfessors = z.infer<
  typeof paginatedGradeApprovalProfessorsSchema
>
export type GradeApprovalProfessorFilters = z.input<
  typeof gradeApprovalProfessorFiltersSchema
>
export type GradeApprovalSection = z.infer<typeof gradeApprovalSectionSchema>
export type StoreAcademicGradeInput = z.infer<
  typeof storeAcademicGradeInputSchema
>
export type UpdateAcademicGradeInput = z.infer<
  typeof updateAcademicGradeInputSchema
>
export type LockAllAcademicGradesInput = z.infer<
  typeof lockAllAcademicGradesInputSchema
>
export type LockAllAcademicGradesResult = z.infer<
  typeof lockAllAcademicGradesResultSchema
>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
