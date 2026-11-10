"use client"

import { keepPreviousData, useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { ChedReportKey } from "@/features/schemas/ched-report-schema"
import {
  getChedList,
  getChedSummary,
} from "@/features/services/ched-report-service"

/** One page of a CHED Enrollment List or an NSTP Form 2-B list. The previous page stays on screen while the next loads. */
export function useChedListQuery(
  report: Exclude<ChedReportKey, "nstp-summary">,
  academicTermId: number,
  page: number,
  perPage: number,
  search: string,
  enabled: boolean,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: [
      "ched-reports",
      session?.userId ?? null,
      report,
      academicTermId,
      page,
      perPage,
      search,
    ],
    queryFn: ({ signal }) =>
      getChedList(report, academicTermId, page, perPage, search, signal),
    enabled: enabled && session !== null && academicTermId > 0,
    placeholderData: keepPreviousData,
  })
}

/** NSTP Form 2-A for the school year of the chosen term. */
export function useChedSummaryQuery(academicTermId: number, enabled: boolean) {
  const { session } = useAuth()

  return useQuery({
    queryKey: [
      "ched-reports",
      session?.userId ?? null,
      "nstp-summary",
      academicTermId,
    ],
    queryFn: ({ signal }) => getChedSummary(academicTermId, signal),
    enabled: enabled && session !== null && academicTermId > 0,
  })
}
