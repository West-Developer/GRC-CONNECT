"use client"

import { useQuery } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import {
  getAttritionReport,
  getHonorsReport,
} from "@/features/services/attrition-honors-service"

export function useAttritionReportQuery(input: {
  baselineAcademicTermId?: number
  comparisonAcademicTermId?: number
  college?: string
  programId?: number
  yearLevel?: number
}) {
  const { session } = useAuth()
  return useQuery({
    queryKey: ["attrition-report", session?.userId ?? null, input],
    queryFn: ({ signal }) =>
      getAttritionReport(
        {
          baselineAcademicTermId: input.baselineAcademicTermId!,
          comparisonAcademicTermId: input.comparisonAcademicTermId!,
          college: input.college,
          programId: input.programId,
          yearLevel: input.yearLevel,
        },
        signal,
      ),
    enabled:
      session?.role === "registrar_head" &&
      !!input.baselineAcademicTermId &&
      !!input.comparisonAcademicTermId,
  })
}

export function useHonorsReportQuery(input: {
  academicTermId?: number
  college?: string
  programId?: number
  yearLevel?: number
  page?: number
}) {
  const { session } = useAuth()
  return useQuery({
    queryKey: ["honors-report", session?.userId ?? null, input],
    queryFn: ({ signal }) =>
      getHonorsReport(
        {
          academicTermId: input.academicTermId!,
          college: input.college,
          programId: input.programId,
          yearLevel: input.yearLevel,
          page: input.page,
        },
        signal,
      ),
    enabled: session?.role === "dean" && !!input.academicTermId,
    refetchOnWindowFocus: true,
    refetchInterval: 30_000,
  })
}
