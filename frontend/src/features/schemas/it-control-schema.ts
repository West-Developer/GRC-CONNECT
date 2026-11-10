import { z } from "zod"

import { strongPasswordSchema } from "@/features/schemas/password-schema"

const collegeSchema = z.enum(["ccs", "coe", "coa", "cbae"])
const accountStatusSchema = z.enum(["active", "disabled"])
// `archived` is not a status an account has: an archived account is disabled and hidden from the other views.
const accountStatusFilterSchema = z.enum(["active", "disabled", "archived"])

const paginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()

const paginationLinkSchema = z
  .object({
    url: z.string().url().nullable(),
    label: z.string().min(1),
    // Laravel's paginator adds the page number to each link; optional so older payloads still parse.
    page: z.number().int().positive().nullable().optional(),
    active: z.boolean(),
  })
  .strict()

const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    from: z.number().int().positive().nullable(),
    last_page: z.number().int().positive(),
    links: z.array(paginationLinkSchema),
    path: z.string().url(),
    per_page: z.number().int().min(1).max(100),
    to: z.number().int().positive().nullable(),
    total: z.number().int().nonnegative(),
  })
  .strict()

export const itControlStudentAccountSchema = z
  .object({
    type: z.literal("it-control-student-account"),
    id: z.number().int().positive(),
    user_id: z.number().int().positive(),
    student_number: z.string().min(1),
    name: z.string().min(1),
    first_name: z.string().nullable(),
    last_name: z.string().nullable(),
    suffix: z.string().nullable(),
    email: z.email(),
    program_code: z.string().min(1),
    college: collegeSchema.nullable(),
    year_level: z.number().int().positive(),
    enrollment_category: z.enum(["regular", "irregular"]).nullable(),
    academic_standing: z.enum(["good", "probation", "warning"]),
    status: accountStatusSchema,
    is_archived: z.boolean(),
    current_term_enrollment_status: z
      .enum([
        "draft",
        "pending_program_head_approval",
        "pending_student_review",
        "awaiting_first_payment",
        "pending_registrar_approval",
        "pending_payment",
        "enrolled",
        "rejected",
        "cancelled",
        "withdrawn",
      ])
      .nullable(),
    password_hint: z.literal("password"),
  })
  .strict()

export const itControlFacultyAccountSchema = z
  .object({
    type: z.literal("it-control-faculty-account"),
    id: z.number().int().positive(),
    name: z.string().min(1),
    first_name: z.string().nullable(),
    last_name: z.string().nullable(),
    suffix: z.string().nullable(),
    email: z.email(),
    college: collegeSchema.nullable(),
    employment_type: z.enum(["full_time", "part_time"]).nullable(),
    status: accountStatusSchema,
    is_archived: z.boolean(),
    availability_window_count: z.number().int().nonnegative(),
    subject_preference_count: z.number().int().nonnegative(),
    specialization_count: z.number().int().nonnegative(),
    password_hint: z.literal("password"),
  })
  .strict()

function paginatedSchema<Item extends z.ZodType>(itemSchema: Item) {
  return z
    .object({
      data: z.array(itemSchema),
      links: paginationLinksSchema,
      meta: paginationMetaSchema,
    })
    .strict()
}

export const paginatedItControlStudentAccountsSchema = paginatedSchema(
  itControlStudentAccountSchema,
)
export const paginatedItControlFacultyAccountsSchema = paginatedSchema(
  itControlFacultyAccountSchema,
)

// What IT Control gets back after editing, archiving or restoring an account (ADR 0054).
export const itControlAccountSchema = z
  .object({
    type: z.literal("it-control-account"),
    id: z.number().int().positive(),
    role: z.enum(["student", "faculty"]),
    name: z.string().min(1),
    first_name: z.string().nullable(),
    last_name: z.string().nullable(),
    suffix: z.string().nullable(),
    email: z.email(),
    status: accountStatusSchema,
    is_archived: z.boolean(),
    archived_at: z.string().datetime({ offset: true }).nullable(),
  })
  .strict()
export const itControlAccountResponseSchema = z
  .object({ data: itControlAccountSchema })
  .strict()
export const itControlAccountDeletedResponseSchema = z
  .object({
    data: z
      .object({ id: z.number().int().positive(), deleted: z.literal(true) })
      .strict(),
  })
  .strict()

/** The fields IT Control may change. The middle name is deliberately not one of them (ADR 0053). */
export const updateItControlAccountSchema = z
  .object({
    first_name: z.string().trim().min(1).max(255).optional(),
    last_name: z.string().trim().min(1).max(255).optional(),
    suffix: z.string().trim().max(20).nullable().optional(),
    email: z.email().max(255).optional(),
  })
  .strict()

export const accountEditFormSchema = z
  .object({
    first_name: z.string().trim().min(1, "Enter the first name.").max(255),
    last_name: z.string().trim().min(1, "Enter the last name.").max(255),
    suffix: z.string().trim().max(20, "Suffix is too long."),
    email: z.string().trim().max(255).pipe(z.email("Enter a valid email.")),
  })
  .strict()

export const changeItControlPasswordSchema = z
  .object({
    password: strongPasswordSchema.refine(
      (value) => new TextEncoder().encode(value).length <= 72,
      "The password is too long. Use a shorter password.",
    ),
    password_confirmation: z.string(),
  })
  .strict()
  .refine((value) => value.password === value.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  })

export type ChangeItControlPasswordInput = z.infer<
  typeof changeItControlPasswordSchema
>

export const studentAccountFiltersSchema = z
  .object({
    q: z.string().trim().min(1).max(255).optional(),
    college: collegeSchema.optional(),
    program_id: z.number().int().positive().optional(),
    year_level: z.number().int().positive().optional(),
    enrollment_category: z.enum(["regular", "irregular"]).optional(),
    status: accountStatusFilterSchema.optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const facultyAccountFiltersSchema = z
  .object({
    q: z.string().trim().min(1).max(255).optional(),
    college: collegeSchema.optional(),
    employment_type: z.enum(["full_time", "part_time"]).optional(),
    status: accountStatusFilterSchema.optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const studentAccountFilterFormSchema = z
  .object({
    q: z.string().max(255),
    college: collegeSchema.optional(),
    year_level: z.number().int().positive().optional(),
    enrollment_category: z.enum(["regular", "irregular"]).optional(),
    status: accountStatusFilterSchema.optional(),
  })
  .strict()

export const facultyAccountFilterFormSchema = z
  .object({
    q: z.string().max(255),
    college: collegeSchema.optional(),
    employment_type: z.enum(["full_time", "part_time"]).optional(),
    status: accountStatusFilterSchema.optional(),
  })
  .strict()

export type ItControlStudentAccount = z.infer<
  typeof itControlStudentAccountSchema
>
export type ItControlFacultyAccount = z.infer<
  typeof itControlFacultyAccountSchema
>
export type ItControlAccount = z.infer<typeof itControlAccountSchema>
export type UpdateItControlAccountInput = z.infer<
  typeof updateItControlAccountSchema
>
export type AccountEditForm = z.infer<typeof accountEditFormSchema>
export type PaginatedItControlStudentAccounts = z.infer<
  typeof paginatedItControlStudentAccountsSchema
>
export type PaginatedItControlFacultyAccounts = z.infer<
  typeof paginatedItControlFacultyAccountsSchema
>
export type StudentAccountFilters = z.input<typeof studentAccountFiltersSchema>
export type FacultyAccountFilters = z.input<typeof facultyAccountFiltersSchema>
export type StudentAccountFilterForm = z.infer<
  typeof studentAccountFilterFormSchema
>
export type FacultyAccountFilterForm = z.infer<
  typeof facultyAccountFilterFormSchema
>
