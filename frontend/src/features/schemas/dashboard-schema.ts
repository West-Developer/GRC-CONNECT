import { z } from "zod"

export const enrollmentSummarySchema = z
  .object({
    type: z.literal("enrollment_summary"),
    academic_term_id: z.number().int().positive(),
    status_counts: z.record(z.string(), z.number().int().nonnegative()),
    funnel_counts: z.record(z.string(), z.number().int().nonnegative()),
    total_sections: z.number().int().nonnegative(),
    published_sections: z.number().int().nonnegative(),
    total_capacity: z.number().int().nonnegative(),
    total_enrolled_seats: z.number().int().nonnegative(),
    grade_status_counts: z.record(z.string(), z.number().int().nonnegative()),
  })
  .strict()

export const enrollmentSummaryEnvelopeSchema = z
  .object({ data: enrollmentSummarySchema })
  .strict()

export const yearOverYearCountSchema = z
  .object({
    school_year: z.string().min(1),
    enrollment_count: z.number().int().nonnegative(),
  })
  .strict()

export const institutionSummarySchema = z
  .object({
    type: z.literal("institution_summary"),
    status_counts: z.record(z.string(), z.number().int().nonnegative()),
    total_programs: z.number().int().nonnegative(),
    active_programs: z.number().int().nonnegative(),
    total_sections: z.number().int().nonnegative(),
    published_sections: z.number().int().nonnegative(),
    program_counts: z.record(z.string(), z.number().int().nonnegative()),
    year_over_year: z.array(yearOverYearCountSchema),
  })
  .strict()

export const institutionSummaryEnvelopeSchema = z
  .object({ data: institutionSummarySchema })
  .strict()

const policyValueStatusSchema = z.enum([
  "configured",
  "provisional",
  "unset",
  "no_mechanism",
])

const policyValueStateSchema = z
  .object({
    key: z.string().min(1),
    label: z.string().min(1),
    current_value: z.string().nullable(),
    status: policyValueStatusSchema,
    status_label: z.string().min(1),
    description: z.string().min(1),
    prd_reference: z.string().nullable(),
  })
  .strict()

export const policySettingsSummarySchema = z
  .object({
    type: z.literal("policy_settings_summary"),
    values: z.array(policyValueStateSchema),
  })
  .strict()

export const policySettingsEnvelopeSchema = z
  .object({ data: policySettingsSummarySchema })
  .strict()

export const analyticsYearOverYearPointSchema = z
  .object({
    school_year: z.string().min(1),
    semester: z.string().min(1),
    enrollee_count: z.number().int().nonnegative(),
    stopped_count: z.number().int().nonnegative().default(0),
    attrition_rate: z.number().nonnegative().default(0),
  })
  .strict()

const retentionBreakdownRowSchema = z
  .object({
    grade_status: z.string().min(1),
    enrollment_status: z.string().min(1),
    count: z.number().int().nonnegative(),
  })
  .strict()

export const programChairAnalyticsSummarySchema = z
  .object({
    type: z.literal("program_chair_analytics_summary"),
    academic_term_id: z.number().int().positive(),
    college: z.string().min(1),
    official_enrolled_count: z.number().int().nonnegative(),
    year_level: z.number().int().min(1).max(4).nullable(),
    enrollment_status_counts: z.record(
      z.string(),
      z.number().int().nonnegative(),
    ),
    grade_status_counts: z.record(z.string(), z.number().int().nonnegative()),
    retention_breakdown: z.array(retentionBreakdownRowSchema),
    year_over_year: z.array(analyticsYearOverYearPointSchema),
  })
  .strict()

export const programChairAnalyticsSummaryEnvelopeSchema = z
  .object({ data: programChairAnalyticsSummarySchema })
  .strict()

export type EnrollmentSummary = z.infer<typeof enrollmentSummarySchema>
export type InstitutionSummary = z.infer<typeof institutionSummarySchema>
export type PolicyValueState = z.infer<typeof policyValueStateSchema>
export type PolicySettingsSummary = z.infer<typeof policySettingsSummarySchema>
export type ProgramChairAnalyticsSummary = z.infer<
  typeof programChairAnalyticsSummarySchema
>
export type YearOverYearCount = z.infer<typeof yearOverYearCountSchema>
export type AnalyticsYearOverYearPoint = z.infer<
  typeof analyticsYearOverYearPointSchema
>

// --- Enrollment Dashboard drill-down (ADR 0024) ---------------------------
// Overview -> sections of a department -> students -> one student. The first
// two levels are aggregate-only; the last two are the audited student level.

const nonNegativeInt = z.number().int().nonnegative()

/** Display order everywhere: the four groups a student can sit in for a term. */
export const enrollmentStatusGroupValues = [
  "enrolled",
  "in_progress",
  "not_yet_done",
  "not_enrolled",
] as const

export const enrollmentStatusGroupSchema = z.enum(enrollmentStatusGroupValues)

const enrollmentStatusGroupCountsSchema = z
  .object({
    enrolled: nonNegativeInt,
    in_progress: nonNegativeInt,
    not_yet_done: nonNegativeInt,
    not_enrolled: nonNegativeInt,
  })
  .strict()

const utcDateTimeSchema = z.iso.datetime().nullable()

export const enrollmentStatusDepartmentSchema = z
  .object({
    department: z.string().min(1).nullable(),
    label: z.string().min(1),
    total: nonNegativeInt,
    groups: enrollmentStatusGroupCountsSchema,
    // Students per enrollment status from draft through enrolled, scoped to
    // this department — always present (see BuildEnrollmentStatusOverview).
    steps: z.record(z.string(), nonNegativeInt),
  })
  .strict()

export const enrollmentStatusOverviewSchema = z
  .object({
    type: z.literal("enrollment_status_overview"),
    academic_term_id: z.number().int().positive(),
    total_students: nonNegativeInt,
    groups: enrollmentStatusGroupCountsSchema,
    // Students per enrollment status from draft through enrolled.
    steps: z.record(z.string(), nonNegativeInt),
    departments: z.array(enrollmentStatusDepartmentSchema),
  })
  .strict()

export const enrollmentStatusOverviewEnvelopeSchema = z
  .object({ data: enrollmentStatusOverviewSchema })
  .strict()

export const enrollmentStatusSectionSchema = z
  .object({
    section_code: z.string().min(1).nullable(),
    total: nonNegativeInt,
    groups: enrollmentStatusGroupCountsSchema,
  })
  .strict()

export const enrollmentStatusSectionsSchema = z
  .object({
    type: z.literal("enrollment_status_sections"),
    academic_term_id: z.number().int().positive(),
    department: z.string().min(1).nullable(),
    sections: z.array(enrollmentStatusSectionSchema),
  })
  .strict()

export const enrollmentStatusSectionsEnvelopeSchema = z
  .object({ data: enrollmentStatusSectionsSchema })
  .strict()

export const enrollmentStatusStudentSchema = z
  .object({
    type: z.literal("enrollment_status_student"),
    student_profile_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    department: z.string().min(1).nullable(),
    year_level: z.number().int().positive(),
    section_code: z.string().min(1).nullable(),
    group: enrollmentStatusGroupSchema,
    group_label: z.string().min(1),
    enrollment_id: z.number().int().positive().nullable(),
    enrollment_status: z.string().min(1).nullable(),
    enrollment_status_label: z.string().min(1).nullable(),
    submitted_at: utcDateTimeSchema,
    enrolled_at: utcDateTimeSchema,
  })
  .strict()

const enrollmentStatusPaginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()

const enrollmentStatusPaginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().min(1).max(100),
    total: nonNegativeInt,
  })
  .passthrough()

export const enrollmentStatusStudentsEnvelopeSchema = z
  .object({
    data: z.array(enrollmentStatusStudentSchema),
    links: enrollmentStatusPaginationLinksSchema,
    meta: enrollmentStatusPaginationMetaSchema,
  })
  .strict()

export const enrollmentStatusStudentDetailSchema = z
  .object({
    type: z.literal("enrollment_status_student_detail"),
    academic_term_id: z.number().int().positive(),
    student_profile_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    program_code: z.string().min(1),
    program_name: z.string().min(1),
    department: z.string().min(1).nullable(),
    year_level: z.number().int().positive(),
    enrollment_category: z.string().min(1).nullable(),
    group: enrollmentStatusGroupSchema,
    group_label: z.string().min(1),
    section_code: z.string().min(1).nullable(),
    enrollment: z
      .object({
        id: z.number().int().positive(),
        status: z.string().min(1),
        status_label: z.string().min(1),
        total_units: z.number().nonnegative(),
        submitted_at: utcDateTimeSchema,
        registrar_decided_at: utcDateTimeSchema,
        payment_confirmed_at: utcDateTimeSchema,
        enrolled_at: utcDateTimeSchema,
      })
      .strict()
      .nullable(),
    subjects: z.array(
      z
        .object({
          subject_code: z.string().min(1),
          subject_title: z.string().min(1),
          units: z.number().nonnegative().nullable(),
          section_code: z.string().min(1).nullable(),
          status: z.string().min(1),
          status_label: z.string().min(1),
        })
        .strict(),
    ),
  })
  .strict()

export const enrollmentStatusStudentDetailEnvelopeSchema = z
  .object({ data: enrollmentStatusStudentDetailSchema })
  .strict()

export type EnrollmentStatusGroup = z.infer<typeof enrollmentStatusGroupSchema>
export type EnrollmentStatusGroupCounts = z.infer<
  typeof enrollmentStatusGroupCountsSchema
>
export type EnrollmentStatusDepartment = z.infer<
  typeof enrollmentStatusDepartmentSchema
>
export type EnrollmentStatusOverview = z.infer<
  typeof enrollmentStatusOverviewSchema
>
export type EnrollmentStatusSection = z.infer<
  typeof enrollmentStatusSectionSchema
>
export type EnrollmentStatusSections = z.infer<
  typeof enrollmentStatusSectionsSchema
>
export type EnrollmentStatusStudent = z.infer<
  typeof enrollmentStatusStudentSchema
>
export type EnrollmentStatusStudentsPage = z.infer<
  typeof enrollmentStatusStudentsEnvelopeSchema
>
export type EnrollmentStatusStudentDetail = z.infer<
  typeof enrollmentStatusStudentDetailSchema
>
