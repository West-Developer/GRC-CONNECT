import {
  ArrowLeftRight,
  BarChart3,
  Building2,
  CalendarDays,
  ClipboardCheck,
  FileCheck2,
  FileClock,
  FileSpreadsheet,
  FileText,
  FileSearch,
  Gauge,
  GraduationCap,
  IdCard,
  Lock,
  Medal,
  Network,
  NotebookText,
  Percent,
  ReceiptText,
  ScrollText,
  UserCheck,
  UserMinus,
  UserPlus,
  Users,
  type LucideIcon,
} from "lucide-react"

import type { UserRole } from "@/features/auth/roles"

export interface PortalModule {
  id: string
  label: string
  description: string
  icon: LucideIcon
  /**
   * The module this one lives inside. A nested module has no sidebar entry or overview card of its own: its page
   * is reached from the parent's page (and by link), and the parent shows as the current page there.
   */
  nestedUnder?: string
}

export interface RolePortalDefinition {
  roleLabel: string
  welcomeHeading: string
  modules: readonly PortalModule[]
}

function portalModule(
  id: string,
  label: string,
  description: string,
  icon: LucideIcon,
  nestedUnder?: string,
): PortalModule {
  return nestedUnder === undefined
    ? { id, label, description, icon }
    : { id, label, description, icon, nestedUnder }
}

export const rolePortalDefinitions: Record<UserRole, RolePortalDefinition> = {
  student: {
    roleLabel: "Student",
    welcomeHeading: "Your enrollment path, organized in one place.",
    modules: [
      portalModule(
        "enrollment",
        "Enrollment",
        "Select sections and submit your enrollment for the term.",
        GraduationCap,
      ),
      portalModule(
        "schedule",
        "Schedule",
        "View your weekly class timetable, professor assignments, and room allocations.",
        CalendarDays,
      ),
      portalModule(
        "statement-of-account",
        "Statement of Account",
        "See what was assessed, what you paid, and what you still owe, term by term.",
        ScrollText,
      ),
      portalModule(
        "grades",
        "Grades",
        "Browse your recorded grades by school year and semester, and view your full prospectus.",
        NotebookText,
      ),
      portalModule(
        "digital-com",
        "Certificate of Registration",
        "View and print your Certificate of Registration.",
        FileCheck2,
      ),
      portalModule(
        "student-information",
        "Student Information",
        "View your official information and request Admission-approved corrections.",
        UserCheck,
      ),
      portalModule(
        "admission-requirements",
        "Admission",
        "See your admission status and which requirements are submitted or still missing.",
        ClipboardCheck,
      ),
    ],
  },
  admission_staff: {
    roleLabel: "Admission Staff",
    welcomeHeading: "Guide applicant records toward enrollment readiness.",
    modules: [
      portalModule(
        "student-records",
        "Student Records",
        "Create accounts, maintain verified profiles, and decide student information changes.",
        Users,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "See the students who are still in the admission process.",
        Gauge,
      ),
    ],
  },
  faculty: {
    roleLabel: "Professor / Faculty",
    welcomeHeading: "Prepare teaching commitments and class records.",
    modules: [
      portalModule(
        "availability-preferences",
        "Availability Preferences",
        "Communicate teaching availability and subject preferences.",
        CalendarDays,
      ),
      portalModule(
        "teaching-schedule",
        "Teaching Schedule",
        "Review approved teaching assignments.",
        FileClock,
      ),
      portalModule(
        "grade-submission",
        "Grade Submission",
        "Encode, edit, and submit grades for students in your assigned sections.",
        ClipboardCheck,
      ),
      portalModule(
        "professor-information",
        "My Information",
        "View your official faculty profile, department, and teaching load details.",
        IdCard,
      ),
    ],
  },
  program_chair: {
    roleLabel: "Program Head",
    welcomeHeading: "Shape curriculum demand into a reviewable schedule.",
    modules: [
      portalModule(
        "program-chair-enrollment",
        "Enrollment",
        "Define curriculum capacities, review faculty input, and generate the term schedule.",
        GraduationCap,
      ),
      // Advising and the dashboards sit right under Enrollment, the page they
      // are read alongside (stakeholder Doc 12).
      portalModule(
        "irregular-enrollments",
        "Irregular Advising",
        "Check irregular student subject schedules, review student curriculum prospectus, and approve enrollments.",
        FileCheck2,
        "program-chair-enrollment",
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "View your college's enrollment status for the current term — overall, per section, and per student.",
        Gauge,
      ),
      portalModule(
        "program-chair-analytics",
        "Enrollment Analytics",
        "Descriptive, predictive, and prescriptive views built from your college's existing enrollment and forecast data.",
        BarChart3,
      ),
      portalModule(
        "subjects-prerequisites",
        "Curriculum Editor",
        "Build each year level's subject list, units, and prerequisites.",
        Network,
      ),
      portalModule(
        "schedule",
        "Schedule",
        "Review and edit the generated section schedule and assignments for the selected term.",
        CalendarDays,
      ),
      portalModule(
        "faculty-loading",
        "Faculty Loading",
        "Set the faculty load threshold, review the load report, and manage the faculty workforce.",
        Gauge,
      ),
      portalModule(
        "faculty-workforce",
        "Faculty Workforce",
        "Search professors in your college, manage their workforce status, and approve which subjects they may teach.",
        Users,
      ),
      portalModule(
        "rooms",
        "Rooms",
        "Review room availability, capacity, and scheduled physical-week use before assigning a section.",
        Building2,
      ),
      portalModule(
        "credit-mappings",
        "Credit Mappings",
        "Review students' credit requests, map each previous subject to their curriculum, and endorse it to the Registrar.",
        Network,
        "program-chair-enrollment",
      ),
      portalModule(
        "faculty-invitations",
        "Invite Professors",
        "Invite a professor by email — they get a one-time code to set up their own account.",
        UserPlus,
      ),
    ],
  },
  dean: {
    roleLabel: "Dean",
    welcomeHeading: "Review academic plans and student progress signals.",
    modules: [
      portalModule(
        "schedule-approvals",
        "Enrollment",
        "Review submitted Program Head enrollment plans and return them with notes when changes are needed.",
        ClipboardCheck,
      ),
      portalModule(
        "curriculum-approvals",
        "Curriculum Approvals",
        "Review curricula submitted by Program Heads and record your decision.",
        ClipboardCheck,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "View your college's enrollment status for the current term — overall, per section, and per student.",
        Gauge,
      ),
      portalModule(
        "faculty-load-monitoring",
        "Faculty Load",
        "See each professor's teaching load against their maximum, set a professor's own limit, and change who teaches a section.",
        Users,
      ),
      portalModule(
        "honors",
        "Honors",
        "Review the live Dean's List after faculty submit complete term grades.",
        Medal,
      ),
      portalModule(
        "reports",
        "Reports",
        "Preview role-authorized academic reporting.",
        BarChart3,
      ),
    ],
  },
  executive_director: {
    roleLabel: "Executive Director",
    welcomeHeading: "See the institution-wide enrollment picture.",
    modules: [
      portalModule(
        "master-schedule",
        "Enrollment",
        "Review department enrollment plans, record decisions, and publish approved schedules.",
        CalendarDays,
      ),
      portalModule(
        "curriculum-approvals",
        "Curriculum Approvals",
        "Review curricula submitted by Program Heads and record your decision.",
        ClipboardCheck,
      ),
      portalModule(
        "institution-dashboard",
        "Institution Dashboard",
        "See where validated institution-level activity will be summarized.",
        Building2,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "View institution-wide enrollment status — overall, per department, per section, and per student.",
        Gauge,
      ),
      portalModule(
        "kpis",
        "KPIs",
        "Preview governed performance indicators with future source definitions.",
        Gauge,
      ),
      portalModule(
        "reports",
        "Reports",
        "Preview institution-level reporting for authorized leadership.",
        BarChart3,
      ),
    ],
  },
  registrar_head: {
    roleLabel: "Registrar Head",
    welcomeHeading: "Govern enrollment decisions, controls, and records.",
    modules: [
      portalModule(
        "academic-terms",
        "Enrollment",
        "Create the school year and semester that starts the enrollment cycle.",
        GraduationCap,
      ),
      portalModule(
        "enrollment-approvals",
        "Enrollment Approvals",
        "Give the final approval to submitted enrollments, open a student's information, and review their schedule.",
        ClipboardCheck,
      ),
      portalModule(
        "students",
        "Students",
        "Search every student. The Registrar Head can open a student's details.",
        Users,
      ),
      portalModule(
        "credit-mappings",
        "Credit Mappings",
        "Approve, reject or return the credit mappings the Program Head has endorsed.",
        Network,
      ),
      portalModule(
        "submitted-schedules",
        "Submitted Schedules",
        "See the class schedules Program Heads have submitted and the sections already published.",
        CalendarDays,
      ),
      portalModule(
        "section-change-requests",
        "Schedule Change Requests",
        "Approve or reject Program Head requests to change a published schedule.",
        ArrowLeftRight,
      ),
      portalModule(
        "grade-approvals",
        "Grade Approvals",
        "Lock submitted grades so they count toward prerequisites and standing.",
        Lock,
      ),
      portalModule(
        "academic-transcripts",
        "Academic Transcripts",
        "View and print any student's prospectus and grade slip.",
        FileSearch,
      ),
      portalModule(
        "subject-withdrawals",
        "Subject Withdrawals",
        "Withdraw a student from one or more enrolled subjects; each shows as W on their prospectus.",
        UserMinus,
      ),
      portalModule(
        "graduates",
        "Graduates",
        "View and search the official register of graduated students across all programs and school years.",
        GraduationCap,
      ),
      portalModule(
        "cor-records",
        "COR Records",
        "Find and print a student's confirmed Certificate of Registration history.",
        FileSearch,
      ),
      portalModule(
        "enrollment-requests",
        "Enrollment Requests",
        "Approve or reject student withdrawals, drops, added subjects, and section changes.",
        ArrowLeftRight,
      ),
      portalModule(
        "attrition-analytics",
        "Attrition Analytics",
        "Review aggregate first-to-second semester enrollment attrition without student identities.",
        BarChart3,
      ),
      portalModule(
        "registrar-analytics",
        "Enrollment Analytics",
        "Review official enrollment trends across all departments or focus on one department.",
        BarChart3,
      ),
      portalModule(
        "ched-reports",
        "CHED Reports",
        "Preview and download the CHED Enrollment List and the NSTP forms as CSV or in CHED's Excel template.",
        FileSpreadsheet,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "View enrollment status counts — overall, per department, per section, and per student.",
        Gauge,
      ),
      portalModule(
        "payment-discount",
        "Payment Discount",
        "Set the discount for students who pay in full, and the dates it is valid.",
        Percent,
      ),
      portalModule(
        "audit-logs",
        "Audit Logs",
        "Review traceable activity records within authorized controls.",
        FileText,
      ),
      portalModule(
        "rooms",
        "Rooms",
        "Maintain the authoritative room inventory and review all scheduled room use.",
        Building2,
      ),
      portalModule(
        "faculty-workforce",
        "Faculty Workforce",
        "View faculty across every college and the subjects they are approved to teach.",
        Users,
      ),
      portalModule(
        "staff-invitations",
        "Invite Staff",
        "Invite anyone into a GRC staff account by email — they get a one-time code to set up their own account.",
        UserPlus,
      ),
    ],
  },
  registrar_staff: {
    roleLabel: "Registrar Staff",
    welcomeHeading: "Maintain accurate enrollment and academic records.",
    modules: [
      portalModule(
        "enrollment-approvals",
        "Enrollment Approvals",
        "Approve or reject submitted enrollments so students can proceed to payment.",
        ClipboardCheck,
      ),
      portalModule(
        "students",
        "Students",
        "Search every student. The Registrar Head can open a student's details.",
        Users,
      ),
      portalModule(
        "credit-mappings",
        "Credit Mappings",
        "Follow the credit mappings the Program Head has endorsed. The Registrar Head decides them.",
        Network,
      ),
      portalModule(
        "enrollment-requests",
        "Enrollment Requests",
        "Approve or reject student withdrawals and view drop, add, and section-change requests.",
        UserMinus,
      ),
      portalModule(
        "cor-records",
        "COR Records",
        "Find, review, and print any student's confirmed Certificate of Registration (COR) history.",
        FileSearch,
      ),
      portalModule(
        "graduates",
        "Graduates",
        "View and search the official register of graduated students across all programs and school years.",
        GraduationCap,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "See the students waiting for the Registrar's approval.",
        Gauge,
      ),
    ],
  },
  accounting_staff: {
    roleLabel: "Accounting Staff",
    welcomeHeading: "Move approved enrollment through payment confirmation.",
    modules: [
      portalModule(
        "payment-queue",
        "Payment Queue",
        "Call the next student, confirm their payment, and generate the COR.",
        ReceiptText,
      ),
      portalModule(
        "payment-records",
        "Transaction History",
        "Review enrollment payments and balance-payment receipts.",
        ScrollText,
      ),
      portalModule(
        "advance-payment",
        "Advance Payment",
        "Record student advance payments and edit a student's billing classification.",
        ReceiptText,
      ),
      portalModule(
        "statement-of-account",
        "Statement of Account",
        "Look up a student's assessments, payments, and running balance, and print a copy.",
        ScrollText,
      ),
      portalModule(
        "cor-records",
        "COR Records",
        "Find and print confirmed Certificates of Registration for prior enrollments.",
        FileSearch,
      ),
      portalModule(
        "queue-kiosk-access",
        "Queue Kiosk Access",
        "Review the shared kiosk credential and rotate it before a controlled handoff.",
        Lock,
      ),
      portalModule(
        "enrollment-dashboard",
        "Enrollment Dashboard",
        "See the students waiting at the payment stage.",
        Gauge,
      ),
      portalModule(
        "fee-settings",
        "Fee Settings",
        "Configure tuition fee rates and miscellaneous fee particulars for student assessments.",
        ReceiptText,
      ),
    ],
  },
  it_admin: {
    roleLabel: "IT Control",
    welcomeHeading:
      "Find authorized account records and manage Student and Faculty accounts.",
    modules: [
      portalModule(
        "it-control-students",
        "Student Accounts",
        "Find student accounts, copy a test login email, and edit, archive or delete an account.",
        Users,
      ),
      portalModule(
        "it-control-faculty",
        "Faculty Accounts",
        "Find faculty accounts, copy a test login email, and edit, archive or delete an account.",
        UserCheck,
      ),
    ],
  },
  queue_kiosk: {
    roleLabel: "Queue Kiosk",
    welcomeHeading:
      "This device identity is available only through the dedicated Queue Kiosk.",
    modules: [],
  },
  super_admin: {
    roleLabel: "Super Admin",
    welcomeHeading: "Admin Console",
    modules: [
      portalModule(
        "super-admin-accounts",
        "Accounts & Access",
        "Manage institutional user accounts, roles, access statuses, and active sessions.",
        Users,
      ),
      portalModule(
        "audit-logs",
        "Audit Logs",
        "Review traceable activity records within authorized controls.",
        FileText,
      ),
    ],
  },
}

export function getRoleModule(
  role: UserRole,
  moduleId: string,
): PortalModule | null {
  return (
    rolePortalDefinitions[role].modules.find(
      (module) => module.id === moduleId,
    ) ?? null
  )
}

export const knownPortalModuleIds = new Set(
  Object.values(rolePortalDefinitions).flatMap(({ modules }) =>
    modules.map(({ id }) => id),
  ),
)
