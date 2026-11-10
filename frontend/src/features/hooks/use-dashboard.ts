"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getEnrollmentStatusOverview,
  getEnrollmentStatusSections,
  getEnrollmentStatusStudent,
  getEnrollmentStatusStudents,
  getEnrollmentSummary,
  getInstitutionSummary,
  getPolicySettings,
  getProgramChairAnalyticsSummary,
  type EnrollmentStatusStudentsOptions,
} from "@/features/services/dashboard-service"

export const enrollmentSummaryQueryKey = (
  userId: string | null,
  academicTermId?: number,
) => ["enrollment-summary", userId, academicTermId ?? null] as const

/**
 * Polls every 30s (Stakeholder Doc 17) — a passive staff-monitoring figure,
 * so it matches the notification-bell/schedule-proposals tier rather than a
 * transactional queue's faster one. Paused in background tabs by default.
 */
export function useEnrollmentSummaryQuery(
  academicTermId?: number,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: enrollmentSummaryQueryKey(
      session?.userId ?? null,
      academicTermId,
    ),
    queryFn: ({ signal }) => getEnrollmentSummary(academicTermId, signal),
    enabled: enabled && session !== null,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  })
}

export const institutionSummaryQueryKey = (
  userId: string | null,
  academicTermId?: number,
) => ["institution-summary", userId, academicTermId ?? null] as const

export function useInstitutionSummaryQuery(
  academicTermId?: number,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: institutionSummaryQueryKey(
      session?.userId ?? null,
      academicTermId,
    ),
    queryFn: ({ signal }) => getInstitutionSummary(academicTermId, signal),
    enabled: enabled && session !== null,
  })
}

export const policySettingsQueryKey = (userId: string | null) =>
  ["policy-settings", userId] as const

export function usePolicySettingsQuery(enabled = true) {
  const { session } = useAuth()
  return useQuery({
    queryKey: policySettingsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getPolicySettings(signal),
    enabled: enabled && session !== null,
  })
}

export const programChairAnalyticsSummaryQueryKey = (
  userId: string | null,
  academicTermId?: number,
  yearLevel?: number,
  trendSchoolYearFrom?: string,
  trendSchoolYearTo?: string,
  trendSemester?: string,
  department?: string,
) =>
  [
    "program-chair-analytics-summary",
    userId,
    academicTermId ?? null,
    yearLevel ?? null,
    trendSchoolYearFrom ?? null,
    trendSchoolYearTo ?? null,
    trendSemester ?? null,
    department ?? null,
  ] as const

export function useProgramChairAnalyticsSummaryQuery(
  academicTermId?: number,
  yearLevel?: number,
  trendSchoolYearFrom?: string,
  trendSchoolYearTo?: string,
  trendSemester?: string,
  department?: string,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: programChairAnalyticsSummaryQueryKey(
      session?.userId ?? null,
      academicTermId,
      yearLevel,
      trendSchoolYearFrom,
      trendSchoolYearTo,
      trendSemester,
      department,
    ),
    queryFn: ({ signal }) =>
      getProgramChairAnalyticsSummary(
        academicTermId,
        yearLevel,
        trendSchoolYearFrom,
        trendSchoolYearTo,
        trendSemester,
        department,
        signal,
      ),
    enabled: enabled && session !== null,
  })
}

// --- Enrollment Dashboard drill-down (ADR 0024) ---------------------------
// Keys carry the session user id (private data) and every filter that changes
// the answer, so a different department/section/group/page never reuses a row.

export const enrollmentStatusOverviewQueryKey = (
  userId: string | null,
  academicTermId?: number,
) => ["enrollment-status-overview", userId, academicTermId ?? null] as const

/** Same 30s passive-monitoring tier as `useEnrollmentSummaryQuery` above. */
export function useEnrollmentStatusOverviewQuery(
  academicTermId?: number,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: enrollmentStatusOverviewQueryKey(
      session?.userId ?? null,
      academicTermId,
    ),
    queryFn: ({ signal }) =>
      getEnrollmentStatusOverview(academicTermId, signal),
    enabled: enabled && session !== null,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  })
}

export function useEnrollmentStatusSectionsQuery(
  department: string | null,
  academicTermId?: number,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: [
      "enrollment-status-sections",
      session?.userId ?? null,
      academicTermId ?? null,
      department,
    ] as const,
    queryFn: ({ signal }) =>
      getEnrollmentStatusSections(
        { department: department ?? "", academicTermId },
        signal,
      ),
    enabled: enabled && session !== null && department !== null,
  })
}

export function useEnrollmentStatusStudentsQuery(
  options: EnrollmentStatusStudentsOptions | null,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: [
      "enrollment-status-students",
      session?.userId ?? null,
      options?.academicTermId ?? null,
      options?.department ?? null,
      // `null` (no section yet) and `undefined` (whole department) differ.
      options?.sectionCode === undefined
        ? "all"
        : (options.sectionCode ?? "none"),
      options?.group ?? null,
      options?.page ?? 1,
      options?.perPage ?? 15,
    ] as const,
    queryFn: ({ signal }) =>
      // Only runs when `options` is set (see `enabled`).
      getEnrollmentStatusStudents(options!, signal),
    enabled: enabled && session !== null && options !== null,
  })
}

export function useEnrollmentStatusStudentQuery(
  studentProfileId: number | null,
  academicTermId?: number,
  enabled = true,
) {
  const { session } = useAuth()
  return useQuery({
    queryKey: [
      "enrollment-status-student",
      session?.userId ?? null,
      academicTermId ?? null,
      studentProfileId,
    ] as const,
    queryFn: ({ signal }) =>
      getEnrollmentStatusStudent(studentProfileId ?? 0, academicTermId, signal),
    enabled: enabled && session !== null && studentProfileId !== null,
  })
}
