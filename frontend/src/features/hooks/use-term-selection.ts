"use client"

import { useState } from "react"

import type { AcademicTerm } from "@/features/schemas/reference-data-schema"
import { getActiveAcademicTerm } from "@/features/services/reference-data-service"

/**
 * Shared "default to the active term, let the student change it" behavior
 * used by both the Eligible Subjects and Enrollment workspaces. Derives the
 * selection from the active term instead of syncing it via an effect: only
 * an explicit student choice is stored in state.
 */
export function useTermSelection(terms: readonly AcademicTerm[] | undefined) {
  const [explicitTermId, setExplicitTermId] = useState<number | null>(null)
  const activeTerm = getActiveAcademicTerm(terms)
  const selectedTermId = explicitTermId ?? activeTerm?.id ?? null

  return { selectedTermId, setSelectedTermId: setExplicitTermId }
}
