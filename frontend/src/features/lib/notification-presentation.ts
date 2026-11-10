import {
  BadgeCheck,
  Ban,
  CalendarCheck,
  CircleDollarSign,
  FileCheck,
  type LucideIcon,
  Megaphone,
  UndoDot,
} from "lucide-react"

import type { UserRole } from "@/features/auth/roles"

export type NotificationTone = "neutral" | "success" | "warning" | "destructive"

export interface NotificationPresentation {
  label: string
  tone: NotificationTone
  icon: LucideIcon
}

// Keyed by `Notification.notification_type` (backend `NotificationType`
// enum). Deliberately a lookup with a default fallback rather than a
// `switch` that must be exhaustive — an unrecognized type (the backend adds
// one this build has not shipped yet) still renders sensibly instead of
// throwing or being filtered out.
const PRESENTATION_BY_TYPE: Record<string, NotificationPresentation> = {
  schedule_submitted_for_dean: {
    label: "Schedule submitted",
    tone: "neutral",
    icon: FileCheck,
  },
  schedule_dean_approved: {
    label: "Dean approved",
    tone: "success",
    icon: BadgeCheck,
  },
  schedule_executive_approved: {
    label: "Executive Director approved",
    tone: "success",
    icon: BadgeCheck,
  },
  schedule_returned: {
    label: "Returned for correction",
    tone: "destructive",
    icon: UndoDot,
  },
  schedule_published: {
    label: "Schedule published",
    tone: "success",
    icon: Megaphone,
  },
  enrollment_submitted: {
    label: "Enrollment submitted",
    tone: "neutral",
    icon: FileCheck,
  },
  enrollment_program_head_approved: {
    label: "Program Head approved",
    tone: "success",
    icon: BadgeCheck,
  },
  enrollment_program_head_rejected: {
    label: "Enrollment not approved",
    tone: "destructive",
    icon: Ban,
  },
  enrollment_program_head_subjects_revised: {
    label: "Schedule revised",
    tone: "warning",
    icon: FileCheck,
  },
  enrollment_revision_accepted: {
    label: "Student accepted the changes",
    tone: "success",
    icon: BadgeCheck,
  },
  enrollment_revision_declined: {
    label: "Student did not accept the changes",
    tone: "destructive",
    icon: Ban,
  },
  enrollment_registrar_approved: {
    label: "Enrollment approved",
    tone: "success",
    icon: BadgeCheck,
  },
  enrollment_registrar_rejected: {
    label: "Enrollment rejected",
    tone: "destructive",
    icon: Ban,
  },
  enrollment_voided: {
    label: "Enrollment voided",
    tone: "destructive",
    icon: Ban,
  },
  enrollment_payment_confirmed: {
    label: "Payment confirmed",
    tone: "success",
    icon: CircleDollarSign,
  },
  academic_grade_locked: {
    label: "Grade locked",
    tone: "neutral",
    icon: FileCheck,
  },
  withdrawal_request_submitted: {
    label: "Withdrawal requested",
    tone: "neutral",
    icon: FileCheck,
  },
  withdrawal_request_approved: {
    label: "Withdrawal approved",
    tone: "success",
    icon: BadgeCheck,
  },
  withdrawal_request_rejected: {
    label: "Withdrawal rejected",
    tone: "destructive",
    icon: Ban,
  },
  subject_withdrawn: {
    label: "Subject withdrawn",
    tone: "neutral",
    icon: FileCheck,
  },
  transferee_credit_approved: {
    label: "Transferee credit approved",
    tone: "success",
    icon: BadgeCheck,
  },
  transferee_credit_rejected: {
    label: "Transferee credit rejected",
    tone: "destructive",
    icon: Ban,
  },
  // A student sent their list for credit mapping (to the Program Chair), the Chair
  // endorsed a credit (to the Registrar Head), and the Head returned one (to the
  // Program Chair): see ADR 0026, amended.
  transferee_credit_requested: {
    label: "Credit request",
    tone: "neutral",
    icon: FileCheck,
  },
  transferee_credit_endorsed: {
    label: "Credit awaiting approval",
    tone: "warning",
    icon: FileCheck,
  },
  transferee_credit_returned: {
    label: "Credit returned",
    tone: "warning",
    icon: FileCheck,
  },
  // A Transferee or Returnee has no subject to credit (to the Program Chair), and the Chair's confirmation (to the student).
  transferee_no_credit_declared: {
    label: "No subject to credit",
    tone: "neutral",
    icon: FileCheck,
  },
  transferee_no_credit_confirmed: {
    label: "No credit confirmed",
    tone: "success",
    icon: BadgeCheck,
  },
  // A transferee's credit mapping closed: they hold a queue number and may choose their subjects (2026-10-05).
  transferee_queue_number_issued: {
    label: "Your queue number",
    tone: "success",
    icon: CircleDollarSign,
  },
  enrollment_category_reclassified: {
    label: "Enrollment standing updated",
    tone: "neutral",
    icon: CalendarCheck,
  },
  enrollment_change_request_submitted: {
    label: "Change request submitted",
    tone: "neutral",
    icon: FileCheck,
  },
  enrollment_change_request_approved: {
    label: "Change request approved",
    tone: "success",
    icon: BadgeCheck,
  },
  enrollment_change_request_rejected: {
    label: "Change request rejected",
    tone: "destructive",
    icon: Ban,
  },
  faculty_specialization_approved: {
    label: "Subject approved",
    tone: "success",
    icon: BadgeCheck,
  },
  faculty_specialization_rejected: {
    label: "Subject not approved",
    tone: "destructive",
    icon: Ban,
  },
  curriculum_submitted_for_dean: {
    label: "Curriculum submitted",
    tone: "neutral",
    icon: FileCheck,
  },
  curriculum_dean_approved: {
    label: "Curriculum Dean approved",
    tone: "success",
    icon: BadgeCheck,
  },
  curriculum_executive_approved: {
    label: "Curriculum approved",
    tone: "success",
    icon: BadgeCheck,
  },
  curriculum_returned: {
    label: "Curriculum returned",
    tone: "destructive",
    icon: UndoDot,
  },
  section_assigned: {
    label: "Teaching assignment",
    tone: "neutral",
    icon: CalendarCheck,
  },
  section_change_requested: {
    label: "Schedule change requested",
    tone: "warning",
    icon: CalendarCheck,
  },
  section_change_approved: {
    label: "Schedule change approved",
    tone: "success",
    icon: BadgeCheck,
  },
  section_change_rejected: {
    label: "Schedule change not approved",
    tone: "destructive",
    icon: UndoDot,
  },
  section_professor_reassigned: {
    label: "Professor reassigned",
    tone: "neutral",
    icon: CalendarCheck,
  },
  student_year_level_promoted: {
    label: "Year level promoted",
    tone: "success",
    icon: BadgeCheck,
  },
  // A freshman who paid while every section was full, and the moment a section opens (ADR 0043).
  freshman_awaiting_section: {
    label: "Payment received",
    tone: "warning",
    icon: CircleDollarSign,
  },
  freshman_section_available: {
    label: "A section is open",
    tone: "success",
    icon: CalendarCheck,
  },
  // A freshman who paid and is submitted to a block: waiting for the Registrar's approval, not enrolled yet (2026-10-04).
  freshman_awaiting_registrar_approval: {
    label: "Waiting for approval",
    tone: "warning",
    icon: CircleDollarSign,
  },
}

const DEFAULT_PRESENTATION: NotificationPresentation = {
  label: "Notification",
  tone: "neutral",
  icon: CalendarCheck,
}

export function notificationPresentation(
  notificationType: string,
): NotificationPresentation {
  return PRESENTATION_BY_TYPE[notificationType] ?? DEFAULT_PRESENTATION
}

/**
 * Where clicking a notification should navigate. Routes each notification
 * type to its dedicated workflow workspace for the active user role.
 */
export function notificationDestinationPath(
  notificationType: string,
  role: UserRole,
): string | null {
  switch (notificationType) {
    case "faculty_specialization_approved":
    case "faculty_specialization_rejected":
      if (role === "faculty") return "/portal/availability-preferences"
      if (role === "program_chair") return "/portal/faculty-workforce"
      return null
    case "schedule_submitted_for_dean":
      return role === "dean" ? "/portal/schedule-approvals" : null
    case "schedule_dean_approved":
      if (role === "executive_director") return "/portal/master-schedule"
      if (role === "program_chair") return "/portal/program-chair-enrollment"
      return null
    case "schedule_executive_approved":
      if (role === "dean") return "/portal/schedule-approvals"
      if (role === "program_chair") return "/portal/program-chair-enrollment"
      return null
    case "schedule_returned":
      return role === "program_chair"
        ? "/portal/program-chair-enrollment"
        : null
    case "schedule_published":
      if (role === "program_chair") return "/portal/program-chair-enrollment"
      if (role === "faculty") return "/portal/teaching-schedule"
      return null
    // Curriculum reviews live in "Curriculum Approvals" for the Dean and Executive Director; the
    // Program Head authors in the Curriculum Editor.
    case "curriculum_submitted_for_dean":
      return role === "dean" ? "/portal/curriculum-approvals" : null
    case "curriculum_dean_approved":
      if (role === "executive_director") return "/portal/curriculum-approvals"
      if (role === "program_chair") return "/portal/subjects-prerequisites"
      return null
    case "curriculum_executive_approved":
    case "curriculum_returned":
      if (role === "dean") return "/portal/curriculum-approvals"
      if (role === "program_chair") return "/portal/subjects-prerequisites"
      return null
    case "section_assigned":
      return role === "faculty" ? "/portal/teaching-schedule" : null
    case "section_change_requested":
      return role === "registrar_head"
        ? "/portal/section-change-requests"
        : null
    case "section_change_approved":
    case "section_change_rejected":
      return role === "program_chair" ? "/portal/schedule" : null
    case "section_professor_reassigned":
      // The Registrar Head reviews the change; a professor it was taken from checks their own schedule.
      if (role === "faculty") return "/portal/teaching-schedule"
      return role === "registrar_head" ? "/portal/submitted-schedules" : null
    case "enrollment_payment_confirmed":
      return role === "student" ? "/portal/digital-com" : null
    // A freshman waiting for a section reads, and acts on, the news in Enrollment.
    case "freshman_awaiting_section":
    case "freshman_section_available":
      return role === "student" ? "/portal/enrollment" : null
    // A freshman who paid is waiting for the Registrar: the student watches Enrollment, the Registrar approves.
    case "freshman_awaiting_registrar_approval":
      if (role === "student") return "/portal/enrollment"
      return role === "registrar_staff" || role === "registrar_head"
        ? "/portal/enrollment-approvals"
        : null
    case "academic_grade_locked":
    case "student_year_level_promoted":
      return role === "student" ? "/portal/grades" : null
    case "enrollment_submitted":
      // The Program Chair only receives this type for an irregular student's
      // submission awaiting schedule checking (`SubmitEnrollment`), and that
      // decision is made in Irregular Advising & Approvals, not the section
      // planner.
      if (role === "program_chair") return "/portal/irregular-enrollments"
      if (role === "student") return "/portal/enrollment"
      if (role === "registrar_staff" || role === "registrar_head")
        return "/portal/enrollment-approvals"
      return null
    case "enrollment_program_head_approved":
      // Students read the outcome in Enrollment; the Registrar's copy means it now awaits them.
      if (role === "registrar_staff" || role === "registrar_head")
        return "/portal/enrollment-approvals"
      return role === "student" ? "/portal/enrollment" : null
    case "enrollment_revision_accepted":
    case "enrollment_revision_declined":
      // The Chair's answer from the student lands where the Chair works on irregular students.
      return role === "program_chair" ? "/portal/irregular-enrollments" : null
    case "enrollment_program_head_rejected":
    case "enrollment_program_head_subjects_revised":
    case "enrollment_registrar_approved":
    case "enrollment_registrar_rejected":
    case "enrollment_voided":
    case "enrollment_category_reclassified":
      return role === "student" ? "/portal/enrollment" : null
    // A Student's add/drop/change-section and withdrawal requests are made and followed in Enrollment.
    case "enrollment_change_request_submitted":
      if (role === "program_chair") return "/portal/program-chair-enrollment"
      if (role === "registrar_head" || role === "registrar_staff")
        return "/portal/enrollment-requests"
      if (role === "student") return "/portal/enrollment"
      return null
    case "enrollment_change_request_approved":
    case "enrollment_change_request_rejected":
      return role === "student" ? "/portal/enrollment" : null
    case "withdrawal_request_submitted":
      return role === "registrar_head" || role === "registrar_staff"
        ? "/portal/enrollment-requests"
        : null
    case "withdrawal_request_approved":
    case "withdrawal_request_rejected":
      return role === "student" ? "/portal/enrollment" : null
    // The Registrar Head withdrew a subject: the student sees it as W on the Grades page.
    case "subject_withdrawn":
      return role === "student" ? "/portal/grades" : null
    case "transferee_credit_requested":
      return role === "program_chair" ? "/portal/credit-mappings" : null
    case "transferee_credit_endorsed":
      return role === "registrar_head" ? "/portal/credit-mappings" : null
    case "transferee_credit_returned":
      return role === "program_chair" ? "/portal/credit-mappings" : null
    case "transferee_no_credit_declared":
      return role === "program_chair" ? "/portal/credit-mappings" : null
    case "transferee_no_credit_confirmed":
      return role === "student" ? "/portal/enrollment" : null
    // The student reads their number and chooses their subjects in Enrollment.
    case "transferee_queue_number_issued":
      return role === "student" ? "/portal/enrollment" : null
    case "transferee_credit_approved":
    case "transferee_credit_rejected":
      // The student's own request (and its status) lives on the Grades page.
      return role === "student" ? "/portal/grades" : null
    default:
      return null
  }
}
