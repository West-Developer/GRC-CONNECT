"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getAcademicRecord,
  getGradeSlip,
  getProspectus,
  searchAcademicRecordStudents,
} from "@/features/services/academic-record-service"

export const prospectusQueryKey = (
  userId: string | null,
  studentId: number | undefined,
) => ["prospectus", userId, studentId ?? "own"] as const

export const academicRecordQueryKey = (
  userId: string | null,
  studentId: number | undefined,
) => ["academic-record", userId, studentId ?? "own"] as const

export const gradeSlipQueryKey = (
  userId: string | null,
  studentId: number | undefined,
  academicTermId: number | null,
) => ["grade-slip", userId, studentId ?? "own", academicTermId] as const

export function useProspectusQuery(
  studentId?: number,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: prospectusQueryKey(session?.userId ?? null, studentId),
    queryFn: ({ signal }) => getProspectus(studentId, signal),
    enabled: enabled && session !== null,
  })
}

export function useAcademicRecordQuery(
  studentId?: number,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: academicRecordQueryKey(session?.userId ?? null, studentId),
    queryFn: ({ signal }) => getAcademicRecord(studentId, signal),
    enabled: enabled && session !== null,
  })
}

export function useGradeSlipQuery(
  academicTermId: number | null,
  studentId?: number,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: gradeSlipQueryKey(session?.userId ?? null, studentId, academicTermId),
    queryFn: ({ signal }) => {
      if (academicTermId === null) {
        throw new Error("useGradeSlipQuery requires a non-null academicTermId when enabled.")
      }
      return getGradeSlip(academicTermId, studentId, signal)
    },
    enabled: enabled && session !== null && academicTermId !== null,
  })
}

export const academicRecordStudentsQueryKey = (
  userId: string | null,
  search: string,
  by: string | undefined,
) => ["academic-record-students", userId, search, by ?? "all"] as const

export function useAcademicRecordStudentSearchQuery(
  params: {
    search: string
    by?: "all" | "student_number" | "name"
    limit?: number
  },
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()
  const trimmed = params.search.trim()

  return useQuery({
    queryKey: academicRecordStudentsQueryKey(
      session?.userId ?? null,
      trimmed,
      params.by,
    ),
    queryFn: ({ signal }) =>
      searchAcademicRecordStudents(
        {
          search: trimmed,
          by: params.by,
          limit: params.limit,
        },
        signal,
      ),
    enabled: enabled && session !== null && trimmed.length > 0,
  })
}

