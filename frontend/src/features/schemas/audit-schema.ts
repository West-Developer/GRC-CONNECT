import { z } from "zod"

import { userRoles } from "@/features/auth/roles"

const nullableObject = z.record(z.string(), z.unknown()).nullable()

// Filter-dropdown option lists, kept in sync with `App\Domain\Audit\AuditAction`
// / `AuditableType` on a best-effort basis. This list does NOT gate what the
// `audit_log` resource itself may contain (see `auditLogSchema` below) — a
// backend action added here late is simply not filterable yet, rather than
// breaking the whole page the way a `.strict()` enum on the resource field
// would.
export const auditActions = [
  "curriculum.created",
  "curriculum.updated",
  "faculty_availability.created",
  "faculty_availability.updated",
  "faculty_availability.deleted",
  "faculty_subject_preference.created",
  "faculty_subject_preference.updated",
  "faculty_subject_preference.deleted",
  "section.created",
  "section.updated",
  "section.professor_reassigned",
  "section_change_request.requested",
  "section_change_request.approved",
  "section_change_request.rejected",
  "section_change_request.cancelled",
  "schedule_proposal.created",
  "schedule_proposal.dean_approved",
  "schedule_proposal.dean_returned",
  "schedule_proposal.executive_approved",
  "schedule_proposal.executive_returned",
  "schedule_proposal.published",
  "section.published",
  "schedule_proposal.closed",
  "student_profile.provisioned",
  "audit_log.list_viewed",
  "faculty_directory.list_viewed",
  "enrollment.submitted",
  "enrollment.program_head_approved",
  "enrollment.program_head_rejected",
  "enrollment.registrar_approved",
  "enrollment.registrar_rejected",
  "enrollment.voided",
  "enrollment.cancelled_by_student",
  "enrollment_subject_waiver.granted",
  "enrollment_subject_waiver.revoked",
  "admission_requirement.updated",
  "admission_requirement_type.created",
  "academic_grade.created",
  "academic_grade.updated",
  "academic_grade.submitted",
  "academic_grade.locked",
  "queue_ticket.serving_started",
  "queue_ticket.served",
  "enrollment.payment_confirmed",
  "withdrawal_request.created",
  "withdrawal_request.approved",
  "withdrawal_request.rejected",
  "transferee_credit.created",
  "transferee_credit.updated",
  "transferee_credit.approved",
  "transferee_credit.rejected",
  "academic_term.created",
  "subject_offerings.replaced",
  "section_plan.submitted",
  "academic_term_workflow.curriculum_started",
  "academic_term_workflow.curriculum_completed",
  "academic_term_workflow.faculty_reviewed",
  "academic_term.closed",
  "academic_term.archived",
  "academic_term.enrollment_opened",
  "academic_term.enrollment_schedule_updated",
  "queue_kiosk_credential.viewed",
  "queue_kiosk.password_changed",
] as const
export const auditableTypes = [
  "curriculum",
  "faculty_availability",
  "faculty_subject_preference",
  "section",
  "section_change_request",
  "schedule_proposal",
  "student_profile",
  "audit_log",
  "faculty_directory",
  "enrollment",
  "academic_grade",
  "queue_ticket",
  "withdrawal_request",
  "transferee_credit",
  "academic_term",
  "subject_offering",
  "academic_term_workflow",
  "section_plan",
  "academic_term_year_level_window",
  "queue_kiosk_credential",
  "enrollment_subject_waiver",
  "student_admission_requirement",
  "admission_requirement_type",
] as const
export const auditActionSchema = z.enum(auditActions)
export const auditableTypeSchema = z.enum(auditableTypes)

// One field of an audit record compared old against new, prepared by the
// server (names resolved, name-like fields already hidden).
export const auditChangeSchema = z
  .object({
    field: z.string().min(1),
    label: z.string().min(1),
    old: z.union([z.string(), z.number(), z.boolean(), z.null()]),
    new: z.union([z.string(), z.number(), z.boolean(), z.null()]),
    changed: z.boolean(),
  })
  .strict()

export const auditLogSchema = z
  .object({
    type: z.literal("audit_log"),
    id: z.number().int().positive(),
    actor_user_id: z.number().int().positive(),
    actor_name: z.string().min(1),
    actor_role: z.enum(userRoles),
    actor_role_label: z.string().min(1),
    // Set only when a Super Admin acted through the department switcher (ADR 0038): the role
    // (and college) they were acting as. Null for everyone else. Plain strings, and optional,
    // so a role added later (or an older backend that lacks the keys) cannot fail the whole list
    // the way the missing keys did under `.strict()`.
    acting_role: z.string().nullable().optional(),
    acting_role_label: z.string().nullable().optional(),
    acting_college: z.string().nullable().optional(),
    // Deliberately not `auditActionSchema`/`auditableTypeSchema`: those
    // enums back the filter dropdowns, but the resource itself must accept
    // any backend action/type value so one unrecognized entry does not fail
    // `.strict()` parsing and take down the whole audit log page (this bit
    // the notification bell in the same way — see notification-schema.ts).
    action: z.string().min(1),
    auditable_type: z.string().min(1),
    auditable_id: z.number().int().positive().nullable(),
    before_values: nullableObject,
    after_values: nullableObject,
    changes: z.array(auditChangeSchema),
    reason: z.string().nullable(),
    request_id: z.string().min(1).max(128),
    ip_address: z.string().max(45).nullable(),
    created_at: z.iso.datetime().nullable(),
  })
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

export const paginatedAuditLogsSchema = z
  .object({
    data: z.array(auditLogSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

// The per-user first level of the audit screen.
export const auditActorSchema = z
  .object({
    type: z.literal("audit_actor"),
    actor_user_id: z.number().int().positive(),
    actor_name: z.string().min(1),
    actor_role: z.enum(userRoles),
    actor_role_label: z.string().min(1),
    entries_count: z.number().int().positive(),
    last_activity_at: z.iso.datetime().nullable(),
  })
  .strict()

export const paginatedAuditActorsSchema = z
  .object({
    data: z.array(auditActorSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const auditLogFiltersSchema = z
  .object({
    action: auditActionSchema.optional(),
    auditable_type: auditableTypeSchema.optional(),
    actor_user_id: z.number().int().positive().optional(),
    from: z.iso.date().optional(),
    to: z.iso.date().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const auditActorFiltersSchema = auditLogFiltersSchema.omit({
  actor_user_id: true,
})

export type AuditLog = z.infer<typeof auditLogSchema>
export type AuditChange = z.infer<typeof auditChangeSchema>
export type AuditActor = z.infer<typeof auditActorSchema>
export type AuditActorFilters = z.input<typeof auditActorFiltersSchema>
export type AuditLogFilters = z.input<typeof auditLogFiltersSchema>
export interface Paginated<T> {
  data: readonly T[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
