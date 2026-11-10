import dynamic from "next/dynamic"
import type { ComponentType } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { Skeleton } from "@/features/components/ui/skeleton"

/**
 * Every workspace is its own chunk (ADR 0029). They used to be static imports, so
 * signing in shipped all 48 workspaces (charts, PDF/print, the 2,300-line Program
 * Chair planner) as one ~480 KB gzipped route chunk before anything rendered. Now
 * the shell and the one workspace being opened load; the rest wait until visited.
 *
 * `lazyWorkspace` keeps each `import()` literal at its call site (so the bundler
 * can split it), preserves the workspace's prop types, and records the loader so
 * `preloadConnectedModules()` can warm every chunk (tests, or a future hover
 * prefetch) without going through the component.
 */
const preloaders: (() => Promise<unknown>)[] = []

function WorkspaceLoading() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="portal-workspace grid gap-4"
    >
      <span className="sr-only">Loading workspace…</span>
      <Skeleton className="h-8 w-1/3" />
      <Skeleton className="h-24" />
      <Skeleton className="h-24" />
      <Skeleton className="h-24" />
    </div>
  )
}

function lazyWorkspace<
  Loaded extends Record<string, unknown>,
  Name extends keyof Loaded & string,
>(
  load: () => Promise<Loaded>,
  exportName: Name,
): Loaded[Name] extends ComponentType<infer Props>
  ? ComponentType<Props>
  : never {
  preloaders.push(load)

  return dynamic(
    () =>
      load().then((loaded) => ({
        default: loaded[exportName] as ComponentType,
      })),
    { loading: WorkspaceLoading },
  ) as never
}

/** Loads every workspace chunk. Resolves once all are cached. */
export function preloadConnectedModules(): Promise<unknown[]> {
  return Promise.all(preloaders.map((load) => load()))
}

const StudentRecordsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-records-workspace"),
  "StudentRecordsWorkspace",
)
const StudentInformationWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-information-workspace"),
  "StudentInformationWorkspace",
)
const AnalyticsDashboardWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/analytics-dashboard-workspace"),
  "AnalyticsDashboardWorkspace",
)
const FacultyInputWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/faculty-input-workspace"),
  "FacultyInputWorkspace",
)
const TeachingScheduleWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/teaching-schedule-workspace"),
  "TeachingScheduleWorkspace",
)
const CurriculumWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/curriculum-workspace"),
  "CurriculumWorkspace",
)
const CurriculumApprovalsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/curriculum-approvals-workspace"),
  "CurriculumApprovalsWorkspace",
)
const ProgramChairEnrollmentWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/program-chair-enrollment-workspace"),
  "ProgramChairEnrollmentWorkspace",
)
const ProgramChairCreditMappingsWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/program-chair-credit-mappings-workspace"),
  "ProgramChairCreditMappingsWorkspace",
)
const RegistrarHeadCreditReviewWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/registrar-head-credit-review-workspace"),
  "RegistrarHeadCreditReviewWorkspace",
)
const RegistrarSubjectWithdrawalsWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/registrar-subject-withdrawals-workspace"),
  "RegistrarSubjectWithdrawalsWorkspace",
)
const AcademicTermWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/academic-term-workspace"),
  "AcademicTermWorkspace",
)
const ScheduleProposalsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/schedule-proposals-workspace"),
  "ScheduleProposalsWorkspace",
)
const ScheduleWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/schedule-workspace"),
  "ScheduleWorkspace",
)
const StudentScheduleWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-schedule-workspace"),
  "StudentScheduleWorkspace",
)
const FacultyLoadingWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/faculty-loading-workspace"),
  "FacultyLoadingWorkspace",
)
const FacultyWorkforceWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/faculty-workforce-workspace"),
  "FacultyWorkforceWorkspace",
)
const ScheduleDecisionWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/schedule-decision-workspace"),
  "ScheduleDecisionWorkspace",
)
const MasterScheduleWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/master-schedule-workspace"),
  "MasterScheduleWorkspace",
)
const SubmittedSchedulesWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/submitted-schedules-workspace"),
  "SubmittedSchedulesWorkspace",
)
const SectionChangeRequestsWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/section-change-requests-workspace"),
  "SectionChangeRequestsWorkspace",
)
const DeanFacultyLoadWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/dean-faculty-load-workspace"),
  "DeanFacultyLoadWorkspace",
)
const AuditLogsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/audit-logs-workspace"),
  "AuditLogsWorkspace",
)
const EnrollmentWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/enrollment-workspace"),
  "EnrollmentWorkspace",
)
const RegistrarEnrollmentWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/registrar-enrollment-workspace"),
  "RegistrarEnrollmentWorkspace",
)
const AccountingPaymentWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/accounting-payment-workspace"),
  "AccountingPaymentWorkspace",
)
const PaymentRecordsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/payment-records-workspace"),
  "PaymentRecordsWorkspace",
)
const StudentGradesWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-grades-workspace"),
  "StudentGradesWorkspace",
)
const StudentDigitalComWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-digital-com-workspace"),
  "StudentDigitalComWorkspace",
)
const EnrollmentChangeRequestsWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/enrollment-change-requests-workspace"),
  "EnrollmentChangeRequestsWorkspace",
)
const EnrollmentRequestsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/enrollment-requests-workspace"),
  "EnrollmentRequestsWorkspace",
)
const RegistrarRecordsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/registrar-records-workspace"),
  "RegistrarRecordsWorkspace",
)
const RegistrarGradesWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/registrar-grades-workspace"),
  "RegistrarGradesWorkspace",
)
const ClassRostersWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/class-rosters-workspace"),
  "ClassRostersWorkspace",
)
const GradeSubmissionWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/grade-submission-workspace"),
  "GradeSubmissionWorkspace",
)
const EnrollmentDashboardWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/enrollment-dashboard-workspace"),
  "EnrollmentDashboardWorkspace",
)
const InstitutionDashboardWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/institution-dashboard-workspace"),
  "InstitutionDashboardWorkspace",
)
const PolicySettingsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/policy-settings-workspace"),
  "PolicySettingsWorkspace",
)
const FeeSettingsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/fee-settings-workspace"),
  "FeeSettingsWorkspace",
)
const PaymentDiscountWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/payment-discount-workspace"),
  "PaymentDiscountWorkspace",
)
const RoomsOperationsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/rooms-operations-workspace"),
  "RoomsOperationsWorkspace",
)
const FacultyInvitationWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/faculty-invitation-workspace"),
  "FacultyInvitationWorkspace",
)
const StaffInvitationWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/staff-invitation-workspace"),
  "StaffInvitationWorkspace",
)
const ItControlStudentsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/it-control-students-workspace"),
  "ItControlStudentsWorkspace",
)
const ItControlFacultyWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/it-control-faculty-workspace"),
  "ItControlFacultyWorkspace",
)
const QueueKioskAccessWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/queue-kiosk-access-workspace"),
  "QueueKioskAccessWorkspace",
)
const AttritionAnalyticsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/attrition-analytics-workspace"),
  "AttritionAnalyticsWorkspace",
)
const HonorsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/honors-workspace"),
  "HonorsWorkspace",
)
const GraduatesWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/graduates-workspace"),
  "GraduatesWorkspace",
)
const ChedReportsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/ched-reports-workspace"),
  "ChedReportsWorkspace",
)
const CashierCorRecordsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/cashier-cor-records-workspace"),
  "CashierCorRecordsWorkspace",
)
const RegistrarStudentsWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/registrar-students-workspace"),
  "RegistrarStudentsWorkspace",
)
const ProfessorInformationWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/professor-information-workspace"),
  "ProfessorInformationWorkspace",
)
const ProgramChairIrregularEnrollmentsWorkspace = lazyWorkspace(
  () =>
    import("@/features/components/portal/program-chair-irregular-enrollments-workspace"),
  "ProgramChairIrregularEnrollmentsWorkspace",
)
const AdvancePaymentWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/advance-payment-workspace"),
  "AdvancePaymentWorkspace",
)
const StatementOfAccountWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/statement-of-account-workspace"),
  "StatementOfAccountWorkspace",
)
const StudentAdmissionWorkspace = lazyWorkspace(
  () => import("@/features/components/portal/student-admission-workspace"),
  "StudentAdmissionWorkspace",
)

export type ConnectedModuleId =
  | "student-records"
  | "irregular-enrollments"
  | "student-information"
  | "availability-preferences"
  | "teaching-schedule"
  | "program-chair-enrollment"
  | "subjects-prerequisites"
  | "schedule"
  | "faculty-loading"
  | "faculty-workforce"
  | "rooms"
  | "schedule-proposals"
  | "program-chair-analytics"
  | "faculty-invitations"
  | "staff-invitations"
  | "registrar-analytics"
  | "schedule-approvals"
  | "curriculum-approvals"
  | "master-schedule"
  | "submitted-schedules"
  | "section-change-requests"
  | "faculty-load-monitoring"
  | "audit-logs"
  | "enrollment"
  | "grade-approvals"
  | "academic-transcripts"
  | "enrollment-approvals"
  | "payment-queue"
  | "payment-records"
  | "cor-records"
  | "queue-kiosk-access"
  | "grades"
  | "digital-com"
  | "enrollment-change-requests"
  | "enrollment-requests"
  | "credit-mappings"
  | "drops-withdrawals"
  | "academic-records"
  | "enrollment-documents"
  | "class-rosters"
  | "grade-submission"
  | "enrollment-dashboard"
  | "institution-dashboard"
  | "policy-settings"
  | "fee-settings"
  | "payment-discount"
  | "academic-terms"
  | "it-control-students"
  | "it-control-faculty"
  | "attrition-analytics"
  | "honors"
  | "graduates"
  | "ched-reports"
  | "professor-information"
  | "advance-payment"
  | "statement-of-account"
  | "admission-requirements"
  | "students"
  | "subject-withdrawals"

export type PortalModuleComponent = ComponentType

export const connectedModuleIds = [
  "student-records",
  "irregular-enrollments",
  "student-information",
  "availability-preferences",
  "teaching-schedule",
  "program-chair-enrollment",
  "subjects-prerequisites",
  "schedule",
  "faculty-loading",
  "faculty-workforce",
  "rooms",
  "schedule-proposals",
  "program-chair-analytics",
  "faculty-invitations",
  "staff-invitations",
  "registrar-analytics",
  "schedule-approvals",
  "curriculum-approvals",
  "master-schedule",
  "submitted-schedules",
  "section-change-requests",
  "faculty-load-monitoring",
  "audit-logs",
  "enrollment",
  "grade-approvals",
  "academic-transcripts",
  "enrollment-approvals",
  "payment-queue",
  "advance-payment",
  "statement-of-account",
  "admission-requirements",
  "payment-records",
  "cor-records",
  "queue-kiosk-access",
  "grades",
  "digital-com",
  "enrollment-change-requests",
  "enrollment-requests",
  "credit-mappings",
  "drops-withdrawals",
  "academic-records",
  "enrollment-documents",
  "class-rosters",
  "grade-submission",
  "enrollment-dashboard",
  "institution-dashboard",
  "policy-settings",
  "fee-settings",
  "payment-discount",
  "academic-terms",
  "it-control-students",
  "it-control-faculty",
  "attrition-analytics",
  "honors",
  "graduates",
  "ched-reports",
  "professor-information",
  "students",
  "subject-withdrawals",
] as const satisfies readonly ConnectedModuleId[]

const availabilityPreferencesWorkspace: PortalModuleComponent = () => (
  <FacultyInputWorkspace />
)

const teachingScheduleWorkspace: PortalModuleComponent = () => (
  <TeachingScheduleWorkspace />
)

const curriculumWorkspace: PortalModuleComponent = () => <CurriculumWorkspace />
const programChairEnrollmentWorkspace: PortalModuleComponent = () => (
  <ProgramChairEnrollmentWorkspace />
)
const academicTermWorkspace: PortalModuleComponent = () => (
  <AcademicTermWorkspace />
)
function ScheduleModuleRouter() {
  const { session } = useAuth()
  if (session?.role === "student") {
    return <StudentScheduleWorkspace />
  }
  return <ScheduleWorkspace />
}

const scheduleWorkspace: PortalModuleComponent = () => <ScheduleModuleRouter />
const facultyLoadingWorkspace: PortalModuleComponent = () => (
  <FacultyLoadingWorkspace />
)
const facultyWorkforceWorkspace: PortalModuleComponent = () => (
  <FacultyWorkforceWorkspace />
)
const roomsOperationsWorkspace: PortalModuleComponent = () => (
  <RoomsOperationsWorkspace />
)
const scheduleProposalsWorkspace: PortalModuleComponent = () => (
  <ScheduleProposalsWorkspace />
)

const gradeApprovalsWorkspace: PortalModuleComponent = () => (
  <RegistrarGradesWorkspace initialModuleId="grade-approvals" />
)
const academicTranscriptsWorkspace: PortalModuleComponent = () => (
  <RegistrarGradesWorkspace initialModuleId="academic-transcripts" />
)

const enrollmentApprovalsWorkspace: PortalModuleComponent = () => (
  <RegistrarEnrollmentWorkspace initialModuleId="enrollment-approvals" />
)

// Credit mapping is the Program Chair's work and the Registrar Head approves
// what the Chair has endorsed; Registrar Staff only follow it (ADR 0026,
// amended). One module id, three workspaces.
function CreditMappingsModuleRouter() {
  const { session } = useAuth()

  if (session?.role === "program_chair") {
    return <ProgramChairCreditMappingsWorkspace />
  }
  if (session?.role === "registrar_head") {
    return <RegistrarHeadCreditReviewWorkspace />
  }
  return <RegistrarRecordsWorkspace initialModuleId="credit-mappings" />
}
const creditMappingsWorkspace: PortalModuleComponent = () => (
  <CreditMappingsModuleRouter />
)
const dropsWithdrawalsWorkspace: PortalModuleComponent = () => (
  <RegistrarRecordsWorkspace initialModuleId="drops-withdrawals" />
)
const academicRecordsWorkspace: PortalModuleComponent = () => (
  <CashierCorRecordsWorkspace />
)
const enrollmentDocumentsWorkspace: PortalModuleComponent = () => (
  <CashierCorRecordsWorkspace />
)

export const connectedModuleRegistry: Readonly<
  Record<ConnectedModuleId, PortalModuleComponent>
> = {
  "student-records": StudentRecordsWorkspace,
  "student-information": StudentInformationWorkspace,
  "availability-preferences": availabilityPreferencesWorkspace,
  "teaching-schedule": teachingScheduleWorkspace,
  "program-chair-enrollment": programChairEnrollmentWorkspace,
  "subjects-prerequisites": curriculumWorkspace,
  schedule: scheduleWorkspace,
  "faculty-loading": facultyLoadingWorkspace,
  "faculty-workforce": facultyWorkforceWorkspace,
  rooms: roomsOperationsWorkspace,
  "schedule-proposals": scheduleProposalsWorkspace,
  "program-chair-analytics": AnalyticsDashboardWorkspace,
  "faculty-invitations": FacultyInvitationWorkspace,
  "staff-invitations": StaffInvitationWorkspace,
  "registrar-analytics": AnalyticsDashboardWorkspace,
  "schedule-approvals": ScheduleDecisionWorkspace,
  "curriculum-approvals": CurriculumApprovalsWorkspace,
  "master-schedule": MasterScheduleWorkspace,
  "submitted-schedules": SubmittedSchedulesWorkspace,
  "section-change-requests": SectionChangeRequestsWorkspace,
  "faculty-load-monitoring": DeanFacultyLoadWorkspace,
  "audit-logs": AuditLogsWorkspace,
  enrollment: EnrollmentWorkspace,
  "grade-approvals": gradeApprovalsWorkspace,
  "academic-transcripts": academicTranscriptsWorkspace,
  "enrollment-approvals": enrollmentApprovalsWorkspace,
  "payment-queue": AccountingPaymentWorkspace,
  "advance-payment": AdvancePaymentWorkspace,
  "statement-of-account": StatementOfAccountWorkspace,
  "admission-requirements": StudentAdmissionWorkspace,
  "payment-records": PaymentRecordsWorkspace,
  "cor-records": CashierCorRecordsWorkspace,
  "queue-kiosk-access": QueueKioskAccessWorkspace,
  grades: StudentGradesWorkspace,
  "digital-com": StudentDigitalComWorkspace,
  "enrollment-change-requests": EnrollmentChangeRequestsWorkspace,
  "enrollment-requests": EnrollmentRequestsWorkspace,
  "credit-mappings": creditMappingsWorkspace,
  "drops-withdrawals": dropsWithdrawalsWorkspace,
  "academic-records": academicRecordsWorkspace,
  "enrollment-documents": enrollmentDocumentsWorkspace,
  "class-rosters": ClassRostersWorkspace,
  "grade-submission": GradeSubmissionWorkspace,
  "enrollment-dashboard": EnrollmentDashboardWorkspace,
  "institution-dashboard": InstitutionDashboardWorkspace,
  "policy-settings": PolicySettingsWorkspace,
  "fee-settings": FeeSettingsWorkspace,
  "payment-discount": PaymentDiscountWorkspace,
  "academic-terms": academicTermWorkspace,
  "it-control-students": ItControlStudentsWorkspace,
  "it-control-faculty": ItControlFacultyWorkspace,
  "attrition-analytics": AttritionAnalyticsWorkspace,
  honors: HonorsWorkspace,
  graduates: GraduatesWorkspace,
  "ched-reports": ChedReportsWorkspace,
  "professor-information": ProfessorInformationWorkspace,
  "irregular-enrollments": ProgramChairIrregularEnrollmentsWorkspace,
  students: RegistrarStudentsWorkspace,
  "subject-withdrawals": RegistrarSubjectWithdrawalsWorkspace,
}

export function isConnectedModuleId(
  moduleId: string,
): moduleId is ConnectedModuleId {
  return connectedModuleIds.includes(moduleId as ConnectedModuleId)
}
