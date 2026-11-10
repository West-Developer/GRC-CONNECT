"use client"

import { useMemo, useState } from "react"

import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { sortTermsNewestFirst } from "@/features/lib/academic-term-order"
import { getActiveAcademicTerm } from "@/features/services/reference-data-service"

export function useAcademicTermSelection() {
  const termsQuery = useAcademicTermsQuery()
  const currentTerm = getActiveAcademicTerm(termsQuery.data)
  // Newest school year and semester first (ADR 0049 follow-up): the id is only the order the terms
  // were created in, and the older school years were added after 2025-2026.
  const sortedTerms = useMemo(
    () => sortTermsNewestFirst(termsQuery.data ?? []),
    [termsQuery.data],
  )
  const [selectedTermId, setSelectedTermId] = useState<number | null>(null)
  const term =
    sortedTerms.find((candidate) => candidate.id === selectedTermId) ??
    currentTerm ??
    sortedTerms[0] ??
    null
  const termId = term?.id ?? 0
  const isCurrentTerm =
    term !== null && currentTerm !== null && term.id === currentTerm.id

  return {
    termsQuery,
    sortedTerms,
    term,
    termId,
    isCurrentTerm,
    selectedTermId,
    setSelectedTermId,
  }
}

export type AcademicTermSelection = ReturnType<typeof useAcademicTermSelection>
