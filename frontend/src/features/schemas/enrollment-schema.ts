import { z } from "zod"

import { sectionSchema } from "@/features/schemas/reference-data-schema"

/**
 * A section available to an eligible subject, extended with which
 * department it belongs to — `is_own_department` is false when the section
 * was sourced from another college's identical (same code + units) subject
 * row, per `BuildEligibleSubjectPool`'s cross-department sourcing.
 *
 * `subject_code`/`subject_title` are the section's OWN subject, which differs
 * from the placement's subject exactly when `is_own_department` is false —
 * surfaced so a student can see the real course name before picking a
 * cross-department section.
 */
export const eligibleSectionSchema = sectionSchema.extend({
  college: z.enum(["ccs", "coe", "coa", "cbae"]).nullable(),
  is_own_department: z.boolean(),
  subject_code: z.string(),
  subject_title: z.string(),
})

export const eligibleSubjectReasonSchema = z
  .object({
    code: z.enum([
      "eligible",
      "completed",
      "already_selected",
      "prerequisite",
      "prerequisite_advisory",
      "prerequisite_waived",
      "no_sections_available",
      "block_restricted",
      "block_other_year",
      "credit_mapping_pending",
    ]),
    message: z.string().min(1),
  })
  .strict()

export const eligibleSubjectSchema = z
  .object({
    type: z.literal("eligible_subject"),
    subject_id: z.number().int().positive(),
    code: z.string().min(1),
    title: z.string().min(1),
    units: z.number().positive(),
    paired_subject_id: z.number().int().positive().nullable(),
    year_level: z.number().int().positive(),
    semester: z.string().min(1),
    is_required: z.boolean(),
    is_eligible: z.boolean(),
    reasons: z.array(eligibleSubjectReasonSchema),
    preference_score: z.number().int().nullable(),
    preference_reasons: z.array(z.string()),
    available_sections: z.array(eligibleSectionSchema),
  })
  .strict()

export const eligibleSubjectsEnvelopeSchema = z
  .object({ data: z.array(eligibleSubjectSchema) })
  .strict()

const enrollmentSubjectSchema = z
  .object({
    section_id: z.number().int().positive(),
    section_code: z.string().optional(),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    paired_subject_id: z.number().int().positive().nullable(),
    subject_title: z.string().min(1),
    units: z.number().nullable().optional(),
    schedule_days: z.string().nullable().optional(),
    starts_at_time: z.string().nullable().optional(),
    ends_at_time: z.string().nullable().optional(),
    room: z.string().nullable().optional(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable().optional(),
    professor_name: z.string().nullable().optional(),
    status: z.enum(["selected", "enrolled", "dropped"]),
    status_label: z.string().min(1),
  })
  .strict()

const queueTicketSchema = z
  .object({
    ticket_number: z.string().min(1),
    queue_date: z.string().min(1),
    status: z.enum(["waiting", "serving", "served", "cancelled"]),
    status_label: z.string().min(1),
    priority: z.enum(["regular", "priority"]),
    priority_label: z.string().min(1),
    position: z.number().int().nonnegative().nullable(),
  })
  .strict()

const assessmentItemSchema = z
  .object({
    id: z.number().int().positive().optional(),
    category: z.enum([
      "tuition",
      "miscellaneous",
      "scholarship_discount",
      "full_payment_discount",
    ]),
    category_label: z.string().min(1),
    label: z.string().min(1),
    quantity: z.string().nullable(),
    unit_amount: z.string().nullable(),
    amount: z.string().nullable(),
  })
  .strict()

const assessmentSchema = z
  .object({
    total_amount: z.string().nullable(),
    currency: z.string().min(1),
    assessed_at: z.iso.datetime(),
    items: z.array(assessmentItemSchema),
  })
  .strict()

const enrollmentStatusValues = [
  "draft",
  "pending_program_head_approval",
  "pending_student_review",
  // A transferee's chosen subjects wait here for the Cashier's first payment (ADR 0043, amended 2026-10-05).
  "awaiting_first_payment",
  "pending_registrar_approval",
  "pending_payment",
  "enrolled",
  "rejected",
  "cancelled",
  "withdrawn",
] as const

/** A subject as the Program Chair's change history keeps it. */
const revisionSubjectSchema = z
  .object({
    section_id: z.number().int().positive(),
    section_code: z.string().nullable(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number().nonnegative(),
  })
  .strict()

/**
 * One round of the Program Chair changing a student's subjects (ADR 0040):
 * what was added and removed, the Chair's reason, and the student's answer
 * (with the student's reason when they declined).
 */
export const enrollmentRevisionSchema = z
  .object({
    id: z.number().int().positive(),
    proposed_by_role: z
      .enum(["student", "program_chair"])
      .nullable()
      .optional(),
    note: z.string().min(1),
    added_subjects: z.array(revisionSubjectSchema),
    removed_subjects: z.array(revisionSubjectSchema),
    units_before: z.number().nonnegative(),
    units_after: z.number().nonnegative(),
    status: z.enum(["pending", "accepted", "declined", "superseded"]),
    student_reason: z.string().nullable(),
    proposed_at: z.iso.datetime().nullable(),
    responded_at: z.iso.datetime().nullable(),
  })
  .strict()

export const enrollmentSchema = z
  .object({
    type: z.literal("enrollment"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1).nullable(),
    student_year_level: z.number().int().positive().nullable(),
    student_financial_status: z.enum(["scholar", "payee"]).nullable(),
    student_financial_status_label: z.string().min(1).nullable(),
    student_enrollment_category: z.string().nullable().optional(),
    is_irregular: z.boolean().optional(),
    is_late_enrollee: z.boolean().optional(),
    academic_term_id: z.number().int().positive(),
    status: z.enum(enrollmentStatusValues),
    status_label: z.string().min(1),
    total_units: z.number().nonnegative(),
    requires_overload_approval: z.boolean(),
    // The regular unit maximum (before load is considered overload), and the overload maximum.
    regular_unit_maximum: z.number().nonnegative().nullable().optional(),
    // The unit maximum the student was measured against, and whether the load is over it (an irregular
    // student may submit one after confirming; the Program Chair then corrects it). Optional so a response
    // from a server that predates them still parses.
    unit_maximum: z.number().nonnegative().nullable().optional(),
    exceeds_unit_maximum: z.boolean().optional(),
    program_head_comment: z.string().nullable(),
    submitted_at: z.iso.datetime().nullable(),
    program_head_decided_at: z.iso.datetime().nullable(),
    registrar_decided_at: z.iso.datetime().nullable(),
    payment_confirmed_at: z.iso.datetime().nullable(),
    enrolled_at: z.iso.datetime().nullable(),
    subjects: z.array(enrollmentSubjectSchema),
    // Defaults to none so a response from a backend that predates ADR 0040 still parses.
    revisions: z.array(enrollmentRevisionSchema).default([]),
    queue_ticket: queueTicketSchema.nullable(),
    assessment: assessmentSchema.nullable(),
  })
  .strict()

export const enrollmentEnvelopeSchema = z
  .object({ data: enrollmentSchema })
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

export const paginatedEnrollmentsSchema = z
  .object({
    data: z.array(enrollmentSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const enrollmentFiltersSchema = z
  .object({
    status: z.enum(enrollmentStatusValues).optional(),
    academic_term_id: z.number().int().positive().optional(),
    enrollment_ids: z
      .array(z.number().int().positive())
      .min(1)
      .max(100)
      .optional(),
    without_queue_ticket: z.boolean().optional(),
    current_term: z.boolean().optional(),
    search: z.string().trim().optional(),
    // Only the enrollments whose load is over the unit maximum they were measured against.
    exceeds_unit_maximum: z.boolean().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

/**
 * Two mutually exclusive submission shapes, matching
 * `StoreEnrollmentRequest`'s `sections` xor `block_code`: an irregular
 * student submits explicit per-subject sections, a regular student submits
 * one block code and the server expands it to sections itself.
 */
export const storeEnrollmentInputSchema = z.union([
  z
    .object({
      academic_term_id: z.number().int().positive("Select an academic term."),
      sections: z
        .array(z.object({ section_id: z.number().int().positive() }).strict())
        .min(1, "Select at least one section before submitting."),
      // The irregular student's "yes, I am sure" for a load over the unit maximum.
      units_confirmed: z.boolean().optional(),
    })
    .strict(),
  z
    .object({
      academic_term_id: z.number().int().positive("Select an academic term."),
      block_code: z.string().min(1, "Select a block before submitting."),
    })
    .strict(),
])

export const updateEnrollmentInputSchema = z
  .object({
    action: z.enum([
      "program_head_approve",
      "program_head_reject",
      "registrar_approve",
      "registrar_reject",
      "void",
      "student_cancel",
      // The student's answer to the Program Chair's changes (ADR 0040).
      "student_accept_revision",
      "student_decline_revision",
    ]),
    reason: z.string().min(1).optional(),
    // Only for `void`: the Registrar is acting on the student's request.
    requested_by_student: z.boolean().optional(),
    // Required only when the target enrollment's own
    // requires_overload_approval is true — see UpdateEnrollmentRequest.
    overload_acknowledged: z.boolean().optional(),
    // Only meaningful for the two Program Head decisions: an optional note
    // for the student.
    program_head_comment: z.string().max(2000).optional(),
  })
  .strict()

/**
 * A Program Head's full replacement list of section ids for a pending
 * enrollment — see `ReviseEnrollmentSubjects` on the backend.
 */
export const reviseEnrollmentSubjectsInputSchema = z
  .object({
    section_ids: z.array(z.number().int().positive()).min(1),
    // Why the subjects were changed; the student reads it before answering.
    note: z.string().trim().min(1, "Tell the student why.").max(2000),
    overload_acknowledged: z.boolean().optional(),
  })
  .strict()

export const confirmPaymentInputSchema = z
  .object({
    external_reference: z.string().optional(),
    amount: z.number().min(1000).optional(),
    promissory_note_on_file: z.boolean().optional(),
    // ADR 0046: `full` carries the net the Cashier was shown and no amount.
    payment_type: z.enum(["full", "installment"]).optional(),
    quoted_amount: z
      .string()
      .regex(/^\d+(\.\d{1,2})?$/)
      .optional(),
  })
  .strict()

/** What a full payment collects for an enrollment right now (ADR 0046). */
export const paymentQuoteSchema = z
  .object({
    type: z.literal("payment_quote"),
    total_amount: z.string().min(1),
    full_payment: z
      .object({
        applies: z.boolean(),
        percentage: z.string().nullable(),
        discount_amount: z.string().nullable(),
        net_total: z.string().min(1),
        reason: z.enum(["off", "scheduled", "expired", "scholar"]).nullable(),
      })
      .strict(),
  })
  .strict()

export const paymentQuoteEnvelopeSchema = z
  .object({ data: paymentQuoteSchema })
  .strict()

export type PaymentQuote = z.infer<typeof paymentQuoteSchema>

// ADR 0025: the three scholarship tiers the Cashier can assign at payment time.
export const scholarshipPercentageValues = [100, 40, 20] as const
export type ScholarshipPercentage = (typeof scholarshipPercentageValues)[number]

export const scholarshipDiscountInputSchema = z
  .object({
    percentage: z.union([z.literal(100), z.literal(40), z.literal(20)]),
  })
  .strict()

export const adjustEnrollmentAssessmentInputSchema = z
  .object({
    reason: z
      .string()
      .trim()
      .min(3, "Enter a reason for this fee adjustment.")
      .max(1000),
    items: z
      .array(
        z
          .object({
            id: z.number().int().positive(),
            amount: z
              .string()
              .regex(/^\d{1,8}(?:\.\d{1,2})?$/)
              .optional(),
            unit_amount: z
              .string()
              .regex(/^\d{1,8}(?:\.\d{1,2})?$/)
              .optional(),
          })
          .strict(),
      )
      .min(1),
  })
  .strict()

const paymentConfirmationPaymentSchema = z
  .object({
    external_reference: z.string().nullable(),
    amount: z.string().nullable(),
    promissory_note_on_file: z.boolean(),
    confirmed_at: z.iso.datetime().nullable(),
  })
  .strict()

const paymentConfirmationDocumentSchema = z
  .object({
    id: z.number().nullable().optional(),
    document_type: z.literal("cor").nullable(),
    document_number: z.string().nullable(),
    generated_at: z.iso.datetime().nullable(),
  })
  .strict()

export const paymentConfirmationEnvelopeSchema = z
  .object({
    data: z
      .object({
        enrollment: enrollmentSchema,
        payment: paymentConfirmationPaymentSchema,
        document: paymentConfirmationDocumentSchema,
      })
      .strict(),
  })
  .strict()

export type EligibleSubjectReason = z.infer<typeof eligibleSubjectReasonSchema>
export type EligibleSubject = z.infer<typeof eligibleSubjectSchema>
export type Enrollment = z.infer<typeof enrollmentSchema>
export type EnrollmentAssessment = z.infer<typeof assessmentSchema>
export type EnrollmentAssessmentItem = z.infer<typeof assessmentItemSchema>
export type EnrollmentFilters = z.input<typeof enrollmentFiltersSchema>
export type StoreEnrollmentInput = z.infer<typeof storeEnrollmentInputSchema>
export type UpdateEnrollmentInput = z.infer<typeof updateEnrollmentInputSchema>
export type ReviseEnrollmentSubjectsInput = z.infer<
  typeof reviseEnrollmentSubjectsInputSchema
>
export type ConfirmPaymentInput = z.infer<typeof confirmPaymentInputSchema>
export type AdjustEnrollmentAssessmentInput = z.infer<
  typeof adjustEnrollmentAssessmentInputSchema
>
export type PaymentConfirmation = z.infer<
  typeof paymentConfirmationEnvelopeSchema
>["data"]
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
