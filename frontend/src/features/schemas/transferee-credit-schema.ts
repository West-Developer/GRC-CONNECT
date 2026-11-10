import { z } from "zod"

/**
 * A credit starts as a Student's private `draft`, is sent (`pending`), mapped
 * and endorsed by the Program Chair (`endorsed`), and decided by the Registrar
 * Head (`approved` | `rejected`); the Head may also return an endorsed credit to
 * the Program Chair (`pending` again). See ADR 0026, amended.
 */
const transfereeCreditStatusValues = [
  "draft",
  "pending",
  "endorsed",
  "approved",
  "rejected",
] as const

export const transfereeCreditResourceSchema = z
  .object({
    type: z.literal("transferee_credit"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    // Defaults keep a response from a server that predates these fields (and
    // older fixtures) parsing; the current API always sends them.
    student_name: z.string().default(""),
    source_institution: z.string().min(1),
    // Empty when the student did not know the code.
    source_subject_code: z.string(),
    source_subject_title: z.string().min(1),
    source_grade: z.string().nullable(),
    credited_units: z.number().positive(),
    source_school_year: z.string().nullable().optional(),
    source_semester: z.string().nullable().optional(),
    subject_id: z.number().int().positive().nullable(),
    subject_code: z.string().nullable(),
    subject_title: z.string().nullable().default(null),
    requested_by_student: z.boolean().default(false),
    status: z.enum(transfereeCreditStatusValues),
    status_label: z.string().min(1),
    endorsed_at: z.iso.datetime().nullable().default(null),
    submitted_at: z.iso.datetime().nullable().default(null),
    returned_at: z.iso.datetime().nullable().default(null),
    return_reason: z.string().nullable().default(null),
    processed_at: z.iso.datetime().nullable(),
    created_at: z.iso.datetime().nullable(),
  })
  .strict()

export const transfereeCreditEnvelopeSchema = z
  .object({ data: transfereeCreditResourceSchema })
  .strict()

/** What `POST /transferee-credits/submit` answers: the credits that call sent (empty on a repeat). */
export const submittedTransfereeCreditsEnvelopeSchema = z
  .object({ data: z.array(transfereeCreditResourceSchema) })
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
    per_page: z.number().int().min(1).max(100),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const paginatedTransfereeCreditsSchema = z
  .object({
    data: z.array(transfereeCreditResourceSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const transfereeCreditFiltersSchema = z
  .object({
    status: z.enum(transfereeCreditStatusValues).optional(),
    student_id: z.number().int().positive().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

/**
 * A student asks for themselves (no `student_id`, no `subject_id`: the API pins
 * the student and mapping is the Program Chair's decision); a Program Chair
 * records one for a student in their college and may map it. The units may be
 * fractional (1.5); the code may be left out.
 */
export const createTransfereeCreditInputSchema = z
  .object({
    student_id: z.number().int().positive().optional(),
    source_institution: z.string().min(1),
    source_subject_code: z.string().optional(),
    source_subject_title: z.string().min(1),
    source_grade: z.string().min(1).optional(),
    credited_units: z.number().positive().max(99.9),
    source_school_year: z.string().min(1).optional(),
    source_semester: z.string().min(1).optional(),
    subject_id: z.number().int().positive().optional(),
  })
  .strict()

/** The Program Chair's corrections to a pending credit, mapping included. */
export const updateTransfereeCreditInputSchema = z
  .object({
    source_institution: z.string().min(1).optional(),
    source_subject_code: z.string().optional(),
    source_subject_title: z.string().min(1).optional(),
    source_grade: z.string().nullable().optional(),
    credited_units: z.number().positive().max(99.9).optional(),
    source_school_year: z.string().nullable().optional(),
    source_semester: z.string().nullable().optional(),
    subject_id: z.number().int().positive().nullable().optional(),
  })
  .strict()

/**
 * Every step after a credit exists (one PATCH, told apart by `action`):
 * the Program Chair `endorse`s (optionally mapping in the same step) or
 * `decline`s a pending request; the Registrar Head `approve`s, `reject`s or
 * `return`s an endorsed one. `decline`, `reject` and `return` need a reason.
 */
export const transfereeCreditActionInputSchema = z.union([
  z
    .object({
      action: z.literal("endorse"),
      subject_id: z.number().int().positive().optional(),
      credited_units: z.number().positive().max(99.9).optional(),
    })
    .strict(),
  z
    .object({ action: z.literal("decline"), reason: z.string().min(1) })
    .strict(),
  z.object({ action: z.literal("approve") }).strict(),
  z.object({ action: z.literal("reject"), reason: z.string().min(1) }).strict(),
  z.object({ action: z.literal("return"), reason: z.string().min(1) }).strict(),
])

/** The Registrar Head's three decisions. */
export const decideTransfereeCreditInputSchema = z.union([
  z.object({ action: z.literal("approve") }).strict(),
  z.object({ action: z.literal("reject"), reason: z.string().min(1) }).strict(),
  z.object({ action: z.literal("return"), reason: z.string().min(1) }).strict(),
])

/** A candidate GRC subject for a credit, computed on demand by the API. */
export const creditSubjectSuggestionSchema = z
  .object({
    type: z.literal("credit_subject_suggestion"),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number().nonnegative(),
    year_level: z.number().int().positive(),
    semester: z.string().min(1),
    score: z.number().min(0).max(1),
    reasons: z.array(z.string()),
  })
  .strict()

export const creditSubjectSuggestionsEnvelopeSchema = z
  .object({ data: z.array(creditSubjectSuggestionSchema) })
  .strict()

/**
 * What the Program Chair's side-by-side screen loads for one student: the
 * suggestions for each pending credit, and the subjects of the student's own
 * curriculum to choose from. Advice only: nothing in it is a mapping.
 */
export const curriculumSubjectOptionSchema = z
  .object({
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number().nonnegative(),
    year_level: z.number().int().positive(),
    semester: z.string().min(1),
  })
  .strict()

export const creditSuggestionGroupSchema = z
  .object({
    credit_id: z.number().int().positive(),
    suggestions: z.array(creditSubjectSuggestionSchema),
  })
  .strict()

export const creditMappingWorkspaceEnvelopeSchema = z
  .object({
    data: z
      .object({
        student_id: z.number().int().positive(),
        credits: z.array(creditSuggestionGroupSchema),
        curriculum_subjects: z.array(curriculumSubjectOptionSchema),
      })
      .strict(),
  })
  .strict()

export type TransfereeCredit = z.infer<typeof transfereeCreditResourceSchema>
export type TransfereeCreditStatus = TransfereeCredit["status"]
export type TransfereeCreditFilters = z.input<
  typeof transfereeCreditFiltersSchema
>
export type CreateTransfereeCreditInput = z.infer<
  typeof createTransfereeCreditInputSchema
>
export type UpdateTransfereeCreditInput = z.infer<
  typeof updateTransfereeCreditInputSchema
>
export type TransfereeCreditActionInput = z.infer<
  typeof transfereeCreditActionInputSchema
>
export type DecideTransfereeCreditInput = z.infer<
  typeof decideTransfereeCreditInputSchema
>
export type CreditSubjectSuggestion = z.infer<
  typeof creditSubjectSuggestionSchema
>
export type CurriculumSubjectOption = z.infer<
  typeof curriculumSubjectOptionSchema
>
export type CreditSuggestionGroup = z.infer<typeof creditSuggestionGroupSchema>
export type CreditMappingWorkspace = z.infer<
  typeof creditMappingWorkspaceEnvelopeSchema
>["data"]
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
