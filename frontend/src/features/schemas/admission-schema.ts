import { z } from "zod"

import { strongPasswordSchema } from "@/features/schemas/password-schema"

export const enrollmentCategorySchema = z.enum(["regular", "irregular"])
export const studentTypeSchema = z.enum([
  "freshman",
  "transferee",
  "returnee",
  "existing_student",
])
// `awaiting_payment`: a freshman whose first payment the Cashier has not confirmed (no setup email yet);
// `pending`: set up not finished; `active`: the student set the account up.
export const accountSetupStatusSchema = z.enum([
  "awaiting_payment",
  "pending",
  "active",
])
export const financialStatusSchema = z.enum(["scholar", "payee"])
export const admissionStatusSchema = z.enum([
  "pending",
  "admitted",
  "enrolled",
  "graduated",
  "withdrawn",
])
export const academicStandingSchema = z.enum(["good", "probation", "warning"])

export const personNameFieldsSchema = {
  first_name: z.string().trim().min(1, "Enter the student's first name."),
  // The full middle name. Every display of the name shows only its initial; only Admission and the student
  // themself ever see the full name (ADR 0053).
  middle_name: z
    .string()
    .trim()
    .max(100, "Middle name is too long.")
    .nullable()
    .optional(),
  last_name: z.string().trim().min(1, "Enter the student's last name."),
  suffix: z
    .string()
    .trim()
    .max(20, "Suffix is too long.")
    .nullable()
    .optional(),
}

/** `YYYY-MM-NNNNN`: the year and month the account was created in, then a 5-digit running number. */
export const STUDENT_NUMBER_PATTERN = /^\d{4}-(0[1-9]|1[0-2])-\d{5}$/
const STUDENT_NUMBER_FORMAT_MESSAGE =
  "Student number must be in YYYY-MM-NNNNN format (e.g. 2026-08-07107)."
const EXISTING_NUMBER_TYPE_MESSAGE =
  "Only a Transferee, a Returnee or an Existing Student can already have a student number."

/**
 * Every type except a Freshman can enter a student number it already has (ADR 0042, amended
 * 2026-10-04): a Freshman has none yet and always gets a new one.
 */
export function studentTypeCanHaveExistingNumber(
  studentType: string | null | undefined,
): boolean {
  return (
    studentType === "transferee" ||
    studentType === "returnee" ||
    studentType === "existing_student"
  )
}

/** The number the next new account would get, read before saving (not reserved). */
export const nextStudentNumberEnvelopeSchema = z.object({
  data: z.object({
    type: z.literal("next_student_number"),
    student_number: z.string().nullable(),
  }),
})

/** The student's gender as the CHED forms record it (ADR 0049). */
export const studentGenderSchema = z.enum(["M", "F"], {
  error: "Select the student's gender.",
})

/** The student's details the CHED forms ask for, typed by Admission when the account is created. */
export const CONTACT_NUMBER_PATTERN = /^[0-9+()\-\s]{7,30}$/
export const CONTACT_NUMBER_MESSAGE =
  "Enter a valid contact number (digits, +, spaces, dashes or brackets, 7 to 30 characters)."

const studentDetailFieldsSchema = {
  gender: studentGenderSchema,
  birth_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, "Enter the student's birth date.")
    .refine((value) => value > "1900-01-01", "Enter a valid birth date.")
    .refine(
      (value) => value < new Date().toISOString().slice(0, 10),
      "The birth date must be in the past.",
    ),
  citizenship: z
    .string()
    .trim()
    .min(1, "Enter the student's citizenship.")
    .max(100),
  contact_number: z
    .string()
    .trim()
    .regex(CONTACT_NUMBER_PATTERN, CONTACT_NUMBER_MESSAGE),
  street_barangay: z
    .string()
    .trim()
    .min(1, "Enter the street and barangay.")
    .max(255),
  town_city: z.string().trim().min(1, "Enter the town or city.").max(255),
  province: z.string().trim().min(1, "Enter the province.").max(255),
}

export const provisionStudentSchema = z
  .object({
    ...personNameFieldsSchema,
    email: z.email("Enter a valid email address."),
    // The single address text is written by the server from the street, town and province.
    ...studentDetailFieldsSchema,
    // `false`: the server assigns the next number and none is sent. `true`: Admission types the
    // number a Returnee or an Existing Student already has.
    has_existing_student_number: z.boolean(),
    student_number: z.string().trim().optional(),
    program_id: z.number().int().positive("Select a program."),
    // entry_year is deliberately absent: the server derives it from the current ongoing academic
    // term and rejects it if sent. The type is chosen by Admission (stakeholder Doc 20); the
    // Regular / Irregular category is decided by the system and is never sent.
    year_level: z.number().int().min(1).max(4),
    student_type: z.enum(
      ["freshman", "transferee", "returnee", "existing_student"],
      { error: "Select the student type." },
    ),
    financial_status: financialStatusSchema.nullable().optional(),
    // The requirements Admission ticked off for this student (stakeholder Doc 20). Some may still be
    // missing: the account can be created anyway and the rest stay on the student's checklist.
    requirement_type_ids: z.array(z.number().int().positive()).optional(),
  })
  .strict()
  .superRefine((value, ctx) => {
    if (!value.has_existing_student_number) {
      if (value.student_number) {
        ctx.addIssue({
          code: "custom",
          path: ["student_number"],
          message: "A new student number is assigned automatically.",
        })
      }
      return
    }

    if (!studentTypeCanHaveExistingNumber(value.student_type)) {
      ctx.addIssue({
        code: "custom",
        path: ["has_existing_student_number"],
        message: EXISTING_NUMBER_TYPE_MESSAGE,
      })
    }

    if (!value.student_number) {
      ctx.addIssue({
        code: "custom",
        path: ["student_number"],
        message: "Enter the student's existing student number.",
      })
    } else if (!STUDENT_NUMBER_PATTERN.test(value.student_number)) {
      ctx.addIssue({
        code: "custom",
        path: ["student_number"],
        message: STUDENT_NUMBER_FORMAT_MESSAGE,
      })
    }
  })

export const studentProfileSchema = z
  .object({
    type: z.literal("student_profile"),
    id: z.number().int().positive(),
    user_id: z.number().int().positive(),
    student_number: z.string().min(1),
    name: z.string().min(1),
    first_name: z.string().min(1),
    // The full middle name: only present for Admission and for the student's own profile, null for everyone
    // else and for an older account that has only an initial.
    middle_name: z.string().nullable().default(null),
    middle_initial: z.string().nullable(),
    last_name: z.string().min(1),
    suffix: z.string().nullable(),
    email: z.email(),
    address: z.string().nullable(),
    // The details the CHED forms ask for (ADR 0049); null on an account that has none yet.
    gender: studentGenderSchema.nullable().default(null),
    birth_date: z.string().nullable().default(null),
    citizenship: z.string().nullable().default(null),
    contact_number: z.string().nullable().default(null),
    street_barangay: z.string().nullable().default(null),
    town_city: z.string().nullable().default(null),
    province: z.string().nullable().default(null),
    // The details above that were generated for the demo and not typed by Admission.
    generated_fields: z.array(z.string()).default([]),
    program_id: z.number().int().positive(),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    curriculum_id: z.number().int().positive(),
    entry_year: z.number().int().nullable(),
    curriculum_name: z.string().min(1),
    curriculum_effective_school_year: z.string().min(1),
    curriculum_max_units: z.number().nullable().optional(),
    curriculum_default_max_units: z.number().nullable().optional(),
    year_level: z.number().int().positive(),
    enrollment_category: enrollmentCategorySchema.nullable(),
    student_type: studentTypeSchema.nullable(),
    student_type_label: z.string().min(1).nullable(),
    admission_status: admissionStatusSchema,
    admission_status_label: z.string().min(1),
    academic_standing: academicStandingSchema,
    academic_standing_label: z.string().min(1),
    financial_status: financialStatusSchema.nullable(),
    financial_status_label: z.string().min(1).nullable(),
    requirements_verified_at: z.iso.datetime().nullable(),
    academic_setup_editable: z.boolean(),
    // `awaiting_payment`: a freshman who has taken a queue number but whose first payment the
    // Cashier has not confirmed — the account setup email is sent only after that.
    account_setup_status: accountSetupStatusSchema,
    invitation_delivery_status: z.enum(["not_sent", "sent", "failed"]),
    // Set when the Cashier confirms the freshman's first payment; null for everyone else.
    freshman_payment_confirmed_at: z.iso.datetime().nullable().default(null),
    // A newly created Transferee or Returnee has until this moment to get an enrollment approved by the
    // Registrar; while it is set they see only Enrollment and the account is then deactivated (ADR 0048).
    // Null for every other student and for accounts that existed before the rule.
    enrollment_deadline_at: z.iso.datetime().nullable().default(null),
    // Where a freshman is in the pay-first flow; null for every other student. `awaiting_section`:
    // the payment is held because every section was full and no enrollment exists yet.
    freshman_status: z
      .enum(["awaiting_payment", "awaiting_section", "enrolling"])
      .nullable()
      .default(null),
    // True while a freshman's payment is held as credit and not yet applied to an enrollment: the
    // Registrar's approval then completes it, so there is no queue number to claim and nothing to pay.
    advance_payment_held: z.boolean().default(false),
    // A freshman's queue number and the delivery of the emailed "view the live queue" link; both
    // null for a student who was not given a queue number.
    queue_ticket_number: z.string().min(1).nullable().default(null),
    queue_link_delivery_status: z
      .enum(["not_sent", "sent", "failed"])
      .nullable()
      .default(null),
  })
  .strict()

export const studentProfileEnvelopeSchema = z
  .object({ data: studentProfileSchema })
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

export const studentProfileFiltersSchema = z
  .object({
    search: z.string().trim().max(255).optional(),
    // Admission's freshman view: narrow the directory to one student type and to where the account stands.
    student_type: studentTypeSchema.optional(),
    account_setup_status: accountSetupStatusSchema.optional(),
    // `pay_first`: only the students Admission is processing (they hold a queue number or have paid), newest
    // first. Left out: historical records, which would all read "Awaiting payment".
    intake: z.enum(["pay_first"]).optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()
export const paginatedStudentProfilesSchema = z
  .object({
    data: z.array(studentProfileSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const updateStudentProfileSchema = z
  .object({
    first_name: z.string().trim().min(1).optional(),
    middle_name: z.string().trim().max(100).nullable().optional(),
    last_name: z.string().trim().min(1).optional(),
    suffix: z.string().trim().max(20).nullable().optional(),
    email: z.email().optional(),
    // The address is written by the server from the three address parts below.
    gender: studentGenderSchema.optional(),
    birth_date: studentDetailFieldsSchema.birth_date.optional(),
    citizenship: studentDetailFieldsSchema.citizenship.optional(),
    contact_number: studentDetailFieldsSchema.contact_number.optional(),
    street_barangay: studentDetailFieldsSchema.street_barangay.optional(),
    town_city: studentDetailFieldsSchema.town_city.optional(),
    province: studentDetailFieldsSchema.province.optional(),
    student_number: z
      .string()
      .regex(/^\d{4}-(0[1-9]|1[0-2])-\d{5}$/)
      .optional(),
    program_id: z.number().int().positive().optional(),
    // entry_year is deliberately absent (see provisionStudentSchema). The type is Admission's choice,
    // corrected here until the first enrollment; the category is the system's and is never sent.
    year_level: z.number().int().min(1).max(4).optional(),
    student_type: studentTypeSchema.optional(),
    financial_status: financialStatusSchema.nullable().optional(),
    admission_status: admissionStatusSchema.optional(),
    reason: z.string().trim().min(1),
    identity_verified_in_person: z.literal(true),
  })
  .strict()

export const profileChangeRequestStatusSchema = z.enum([
  "pending",
  "approved",
  "rejected",
  "cancelled",
])
export const profileChangeRequestSchema = z
  .object({
    type: z.literal("student_profile_change_request"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    status: profileChangeRequestStatusSchema,
    status_label: z.string().min(1),
    official: z.object({
      name: z.string().min(1),
      first_name: z.string().min(1),
      middle_name: z.string().nullable().default(null),
      middle_initial: z.string().nullable(),
      last_name: z.string().min(1),
      suffix: z.string().nullable(),
      email: z.email(),
      address: z.string().nullable(),
    }),
    requested: z.object({
      name: z.string().min(1),
      first_name: z.string().min(1),
      middle_name: z.string().nullable().default(null),
      middle_initial: z.string().nullable(),
      last_name: z.string().min(1),
      suffix: z.string().nullable(),
      email: z.email(),
      address: z.string().min(1),
    }),
    reason: z.string().min(1),
    decision_notes: z.string().nullable(),
    identity_verified_at: z.iso.datetime().nullable(),
    requested_at: z.iso.datetime().nullable(),
    decided_at: z.iso.datetime().nullable(),
  })
  .strict()
export const profileChangeRequestEnvelopeSchema = z
  .object({ data: profileChangeRequestSchema })
  .strict()
export const profileChangeRequestFiltersSchema = z
  .object({
    status: profileChangeRequestStatusSchema.optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()
export const paginatedProfileChangeRequestsSchema = z
  .object({
    data: z.array(profileChangeRequestSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const storeProfileChangeRequestSchema = z
  .object({
    ...personNameFieldsSchema,
    email: z.email(),
    address: z.string().trim().min(1),
    reason: z.string().trim().min(1),
  })
  .strict()
export const decideProfileChangeRequestSchema = z
  .object({
    action: z.enum(["approve", "reject"]),
    identity_verified_in_person: z.literal(true),
    notes: z.string().trim().min(1).optional(),
  })
  .strict()
  .refine((input) => input.action !== "reject" || input.notes !== undefined, {
    path: ["notes"],
    message: "Enter decision notes when rejecting a request.",
  })

export const accountSetupSchema = z
  .object({
    email: z.email(),
    code: z
      .string()
      .trim()
      .regex(/^[0-9]{6}$/, "Enter the 6-digit code from your email."),
    password: strongPasswordSchema,
    password_confirmation: z.string().min(8),
  })
  .strict()
  .refine((input) => input.password === input.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords must match.",
  })
export const accountSetupEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("account-setup"),
      status: z.literal("active"),
    }),
  })
  .strict()

export const resendStudentAccountSetupSchema = z
  .object({
    email: z.email("Enter a valid email address."),
  })
  .strict()

export const resendStudentAccountSetupEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("resend-student-account-setup"),
      status: z.literal("sent"),
      message: z.string().optional(),
    }),
  })
  .strict()

export type ProvisionStudentInput = z.infer<typeof provisionStudentSchema>
export type StudentProfile = z.infer<typeof studentProfileSchema>
export type StudentProfileFilters = z.input<typeof studentProfileFiltersSchema>
export type UpdateStudentProfileInput = z.infer<
  typeof updateStudentProfileSchema
>
export type ProfileChangeRequest = z.infer<typeof profileChangeRequestSchema>
export type ProfileChangeRequestFilters = z.input<
  typeof profileChangeRequestFiltersSchema
>
export type StoreProfileChangeRequestInput = z.infer<
  typeof storeProfileChangeRequestSchema
>
export type DecideProfileChangeRequestInput = z.infer<
  typeof decideProfileChangeRequestSchema
>
export type AccountSetupInput = z.infer<typeof accountSetupSchema>
export type ResendStudentAccountSetupInput = z.infer<
  typeof resendStudentAccountSetupSchema
>
export type ResendStudentAccountSetupResponse = z.infer<
  typeof resendStudentAccountSetupEnvelopeSchema
>["data"]
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
