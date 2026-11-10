import {
  enrollmentStatusOverviewEnvelopeSchema,
  enrollmentStatusSectionsEnvelopeSchema,
  enrollmentStatusStudentDetailEnvelopeSchema,
  enrollmentStatusStudentsEnvelopeSchema,
  enrollmentSummaryEnvelopeSchema,
  institutionSummaryEnvelopeSchema,
  policySettingsEnvelopeSchema,
  programChairAnalyticsSummaryEnvelopeSchema,
  type EnrollmentStatusGroup,
  type EnrollmentStatusOverview,
  type EnrollmentStatusSections,
  type EnrollmentStatusStudentDetail,
  type EnrollmentStatusStudentsPage,
  type EnrollmentSummary,
  type InstitutionSummary,
  type PolicySettingsSummary,
  type ProgramChairAnalyticsSummary,
} from "@/features/schemas/dashboard-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const ENROLLMENT_SUMMARY_PATH = "/api/v1/dashboards/enrollment-summary"
export const INSTITUTION_SUMMARY_PATH = "/api/v1/dashboards/institution-summary"
export const POLICY_SETTINGS_PATH = "/api/v1/dashboards/policy-settings"
export const PROGRAM_CHAIR_ANALYTICS_SUMMARY_PATH =
  "/api/v1/dashboards/program-chair-analytics-summary"
export const ENROLLMENT_STATUS_PATH = "/api/v1/dashboards/enrollment-status"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  label: string,
): T {
  const result = schema.safeParse(payload)
  if (result.success) return result.data
  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

function withTerm(path: string, academicTermId?: number): string {
  return academicTermId ? `${path}?academic_term_id=${academicTermId}` : path
}

export async function getEnrollmentSummary(
  academicTermId?: number,
  signal?: AbortSignal,
): Promise<EnrollmentSummary> {
  const envelope = parse(
    enrollmentSummaryEnvelopeSchema,
    await getAuthenticatedJson(
      withTerm(ENROLLMENT_SUMMARY_PATH, academicTermId),
      signal,
    ),
    "enrollment summary",
  )
  return envelope.data
}

export async function getInstitutionSummary(
  academicTermId?: number,
  signal?: AbortSignal,
): Promise<InstitutionSummary> {
  const envelope = parse(
    institutionSummaryEnvelopeSchema,
    await getAuthenticatedJson(
      withTerm(INSTITUTION_SUMMARY_PATH, academicTermId),
      signal,
    ),
    "institution summary",
  )
  return envelope.data
}

export async function getPolicySettings(
  signal?: AbortSignal,
): Promise<PolicySettingsSummary> {
  const envelope = parse(
    policySettingsEnvelopeSchema,
    await getAuthenticatedJson(POLICY_SETTINGS_PATH, signal),
    "policy settings",
  )
  return envelope.data
}

export async function getProgramChairAnalyticsSummary(
  academicTermId?: number,
  yearLevel?: number,
  trendSchoolYearFrom?: string,
  trendSchoolYearTo?: string,
  trendSemester?: string,
  department?: string,
  signal?: AbortSignal,
): Promise<ProgramChairAnalyticsSummary> {
  const parameters = new URLSearchParams()
  if (academicTermId) parameters.set("academic_term_id", String(academicTermId))
  if (yearLevel) parameters.set("year_level", String(yearLevel))
  if (trendSchoolYearFrom)
    parameters.set("trend_school_year_from", trendSchoolYearFrom)
  if (trendSchoolYearTo)
    parameters.set("trend_school_year_to", trendSchoolYearTo)
  if (trendSemester) parameters.set("trend_semester", trendSemester)
  if (department) parameters.set("department", department)
  const path =
    parameters.size > 0
      ? `${PROGRAM_CHAIR_ANALYTICS_SUMMARY_PATH}?${parameters.toString()}`
      : PROGRAM_CHAIR_ANALYTICS_SUMMARY_PATH

  const envelope = parse(
    programChairAnalyticsSummaryEnvelopeSchema,
    await getAuthenticatedJson(path, signal),
    "program head analytics summary",
  )
  return envelope.data
}

// --- Enrollment Dashboard drill-down (ADR 0024) ---------------------------

export async function getEnrollmentStatusOverview(
  academicTermId?: number,
  signal?: AbortSignal,
): Promise<EnrollmentStatusOverview> {
  const envelope = parse(
    enrollmentStatusOverviewEnvelopeSchema,
    await getAuthenticatedJson(
      withTerm(ENROLLMENT_STATUS_PATH, academicTermId),
      signal,
    ),
    "enrollment status overview",
  )
  return envelope.data
}

export interface EnrollmentStatusSectionsOptions {
  department: string
  academicTermId?: number
}

export async function getEnrollmentStatusSections(
  { department, academicTermId }: EnrollmentStatusSectionsOptions,
  signal?: AbortSignal,
): Promise<EnrollmentStatusSections> {
  const parameters = new URLSearchParams({ department })
  if (academicTermId) parameters.set("academic_term_id", String(academicTermId))

  const envelope = parse(
    enrollmentStatusSectionsEnvelopeSchema,
    await getAuthenticatedJson(
      `${ENROLLMENT_STATUS_PATH}/sections?${parameters.toString()}`,
      signal,
    ),
    "enrollment status sections",
  )
  return envelope.data
}

export interface EnrollmentStatusStudentsOptions {
  department: string
  academicTermId?: number
  /** A section code, or `null` for "No section yet". Omit for the whole department. */
  sectionCode?: string | null
  group?: EnrollmentStatusGroup
  page?: number
  perPage?: number
}

export function enrollmentStatusStudentsPath({
  department,
  academicTermId,
  sectionCode,
  group,
  page,
  perPage,
}: EnrollmentStatusStudentsOptions): string {
  const parameters = new URLSearchParams({ department })
  if (academicTermId) parameters.set("academic_term_id", String(academicTermId))
  if (sectionCode === null) parameters.set("without_section", "1")
  else if (sectionCode !== undefined)
    parameters.set("section_code", sectionCode)
  if (group) parameters.set("group", group)
  parameters.set("page", String(page ?? 1))
  parameters.set("per_page", String(perPage ?? 15))

  return `${ENROLLMENT_STATUS_PATH}/students?${parameters.toString()}`
}

export async function getEnrollmentStatusStudents(
  options: EnrollmentStatusStudentsOptions,
  signal?: AbortSignal,
): Promise<EnrollmentStatusStudentsPage> {
  return parse(
    enrollmentStatusStudentsEnvelopeSchema,
    await getAuthenticatedJson(enrollmentStatusStudentsPath(options), signal),
    "enrollment status students",
  )
}

export async function getEnrollmentStatusStudent(
  studentProfileId: number,
  academicTermId?: number,
  signal?: AbortSignal,
): Promise<EnrollmentStatusStudentDetail> {
  const envelope = parse(
    enrollmentStatusStudentDetailEnvelopeSchema,
    await getAuthenticatedJson(
      withTerm(
        `${ENROLLMENT_STATUS_PATH}/students/${studentProfileId}`,
        academicTermId,
      ),
      signal,
    ),
    "enrollment status student",
  )
  return envelope.data
}
