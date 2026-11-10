import {
  hasScheduleConflict,
  parseScheduleDays,
} from "@/features/lib/schedule-order"
import type { EligibleSubject } from "@/features/schemas/enrollment-schema"

export type RecommendationMode = "manual" | "concise" | "morning" | "afternoon"

type EligibleSection = EligibleSubject["available_sections"][number]

export interface RecommendationResult {
  mode: RecommendationMode
  recommendations: Record<number, number>
  totalSubjects: number
  matchedSubjects: number
  summary: string
  distinctDaysCount: number
}

interface SubjectChoiceOption {
  primarySection: EligibleSection
  pairedSection: EligibleSection | null
  morningScore: number
  afternoonScore: number
  days: number[]
}

interface SubjectChoiceItem {
  primarySubject: EligibleSubject
  pairedSubject: EligibleSubject | null
  options: SubjectChoiceOption[]
}

function computeSectionScores(section: EligibleSection): {
  morningScore: number
  afternoonScore: number
  days: number[]
} {
  const days = parseScheduleDays(section.schedule_days)
  let morningScore = 0
  let afternoonScore = 0

  if (section.starts_at_time && section.ends_at_time) {
    if (section.ends_at_time <= "13:00:00") {
      morningScore += 20
    } else if (section.starts_at_time < "12:00:00") {
      morningScore += 5
    } else {
      morningScore -= 20
    }

    if (section.starts_at_time >= "12:00:00") {
      afternoonScore += 20
    } else if (section.ends_at_time > "13:00:00") {
      afternoonScore += 5
    } else {
      afternoonScore -= 20
    }
  }

  return { morningScore, afternoonScore, days }
}

function buildChoiceItems(
  subjects: readonly EligibleSubject[],
  mode: RecommendationMode,
): SubjectChoiceItem[] {
  const subjectById = new Map(
    subjects.map((subject) => [subject.subject_id, subject]),
  )
  const processedPairedIds = new Set<number>()
  const items: SubjectChoiceItem[] = []

  for (const subject of subjects) {
    if (processedPairedIds.has(subject.subject_id)) continue

    const pairedId = subject.paired_subject_id
    const paired = pairedId !== null ? (subjectById.get(pairedId) ?? null) : null

    if (paired) {
      processedPairedIds.add(paired.subject_id)
    }

    const rawOptions: SubjectChoiceOption[] = []
    const seenScheduleSignatures = new Set<string>()

    for (const primarySection of subject.available_sections) {
      if (paired) {
        const matchingPaired = paired.available_sections.find(
          (p) => p.section_code === primarySection.section_code,
        )
        if (
          matchingPaired &&
          !hasScheduleConflict(primarySection, matchingPaired)
        ) {
          const sig = `${primarySection.schedule_days}|${primarySection.starts_at_time}|${primarySection.ends_at_time}__${matchingPaired.schedule_days}|${matchingPaired.starts_at_time}|${matchingPaired.ends_at_time}`
          if (seenScheduleSignatures.has(sig)) continue
          seenScheduleSignatures.add(sig)

          const primaryScores = computeSectionScores(primarySection)
          const pairedScores = computeSectionScores(matchingPaired)
          rawOptions.push({
            primarySection,
            pairedSection: matchingPaired,
            morningScore:
              primaryScores.morningScore + pairedScores.morningScore,
            afternoonScore:
              primaryScores.afternoonScore + pairedScores.afternoonScore,
            days: [...new Set([...primaryScores.days, ...pairedScores.days])],
          })
        }
      } else {
        const sig = `${primarySection.schedule_days}|${primarySection.starts_at_time}|${primarySection.ends_at_time}`
        if (seenScheduleSignatures.has(sig)) continue
        seenScheduleSignatures.add(sig)

        const scores = computeSectionScores(primarySection)
        rawOptions.push({
          primarySection,
          pairedSection: null,
          morningScore: scores.morningScore,
          afternoonScore: scores.afternoonScore,
          days: scores.days,
        })
      }
    }

    // Sort options according to the requested preset mode
    if (mode === "morning") {
      rawOptions.sort((a, b) => b.morningScore - a.morningScore)
    } else if (mode === "afternoon") {
      rawOptions.sort((a, b) => b.afternoonScore - a.afternoonScore)
    } else if (mode === "concise") {
      rawOptions.sort((a, b) => a.days.length - b.days.length)
    }

    // Limit to top 5 candidates per subject to guarantee fast, bounded computation
    const prunedOptions = rawOptions.slice(0, 5)

    items.push({
      primarySubject: subject,
      pairedSubject: paired,
      options: prunedOptions,
    })
  }

  // Minimum Remaining Values (MRV): Sort subjects with fewest options first
  items.sort((a, b) => a.options.length - b.options.length)

  return items
}

export interface ScheduleFitResult {
  distinctDays: number[]
  /** True when the combined set spans more than `maxDays` distinct days. */
  exceedsDayLimit: boolean
  /** True when the set has at least one section scoring into the morning
   * block and at least one scoring into the afternoon/evening block. */
  mixesTimeBlocks: boolean
  violatesFit: boolean
}

/**
 * Whether a set of sections a student would end up with fits the "1-2 days,
 * one time-of-day block" shape (Stakeholder Doc 17). Reuses the same
 * morning/afternoon scoring `generateScheduleRecommendation`'s "morning"/
 * "afternoon" presets already use, rather than inventing a third
 * classification the UI doesn't otherwise expose.
 */
export function evaluateScheduleFit(
  sections: readonly EligibleSection[],
  maxDays = 2,
): ScheduleFitResult {
  const distinctDays = [
    ...new Set(sections.flatMap((section) => computeSectionScores(section).days)),
  ]
  const scores = sections.map((section) => computeSectionScores(section))
  const hasMorning = scores.some((score) => score.morningScore > 0)
  const hasAfternoon = scores.some((score) => score.afternoonScore > 0)

  const exceedsDayLimit = distinctDays.length > maxDays
  const mixesTimeBlocks = hasMorning && hasAfternoon

  return {
    distinctDays,
    exceedsDayLimit,
    mixesTimeBlocks,
    violatesFit: exceedsDayLimit || mixesTimeBlocks,
  }
}

/**
 * Generates conflict-free schedule recommendations for irregular students.
 * Supports 3 modes:
 * - "concise": packs subjects into the fewest distinct days (e.g. 1-2 days).
 * - "morning": prioritizes sections ending by 1:00 PM.
 * - "afternoon": prioritizes sections starting at or after 12:00 PM.
 *
 * Guaranteed sub-5ms execution with bounded depth search and early termination.
 */
export function generateScheduleRecommendation(
  subjects: readonly EligibleSubject[],
  mode: RecommendationMode,
): RecommendationResult {
  const totalSubjects = subjects.length
  if (totalSubjects === 0 || mode === "manual") {
    return {
      mode,
      recommendations: {},
      totalSubjects,
      matchedSubjects: 0,
      summary: "Manual selection active.",
      distinctDaysCount: 0,
    }
  }

  const items = buildChoiceItems(subjects, mode)
  if (items.length === 0) {
    return {
      mode,
      recommendations: {},
      totalSubjects,
      matchedSubjects: 0,
      summary: "No subjects available for recommendation.",
      distinctDaysCount: 0,
    }
  }

  interface SolutionState {
    assignedCount: number
    selections: Record<number, number>
    sections: EligibleSection[]
    modeScore: number
    distinctDaysCount: number
  }

  let bestSolution: SolutionState = {
    assignedCount: -1,
    selections: {},
    sections: [],
    modeScore: -Infinity,
    distinctDaysCount: Infinity,
  }

  const isBetterSolution = (
    candidate: SolutionState,
    currentBest: SolutionState,
  ): boolean => {
    if (candidate.assignedCount > currentBest.assignedCount) return true
    if (candidate.assignedCount < currentBest.assignedCount) return false

    if (mode === "concise") {
      if (candidate.distinctDaysCount < currentBest.distinctDaysCount) return true
      if (candidate.distinctDaysCount > currentBest.distinctDaysCount) return false
      return candidate.modeScore > currentBest.modeScore
    }

    if (candidate.modeScore > currentBest.modeScore) return true
    if (candidate.modeScore < currentBest.modeScore) return false
    return candidate.distinctDaysCount < currentBest.distinctDaysCount
  }

  const MAX_SEARCH_STEPS = 500
  let stepCount = 0

  function backtrack(
    itemIndex: number,
    currentSelections: Record<number, number>,
    currentSections: EligibleSection[],
    currentModeScore: number,
    currentDaySet: Set<number>,
    assignedSubjectsCount: number,
  ) {
    if (++stepCount > MAX_SEARCH_STEPS) {
      return
    }

    if (itemIndex >= items.length) {
      const state: SolutionState = {
        assignedCount: assignedSubjectsCount,
        selections: { ...currentSelections },
        sections: [...currentSections],
        modeScore: currentModeScore,
        distinctDaysCount: currentDaySet.size,
      }
      if (isBetterSolution(state, bestSolution)) {
        bestSolution = state
      }
      return
    }

    // Branch and bound: if even matching all remaining items cannot exceed best, prune
    let remainingPotential = 0
    for (let i = itemIndex; i < items.length; i++) {
      remainingPotential += items[i].pairedSubject ? 2 : 1
    }
    if (assignedSubjectsCount + remainingPotential < bestSolution.assignedCount) {
      return
    }

    const item = items[itemIndex]
    const subjectUnitsCount = item.pairedSubject ? 2 : 1

    // For concise mode, order options dynamically by how many NEW days they add
    let candidateOptions = item.options
    if (mode === "concise" && currentDaySet.size > 0) {
      candidateOptions = [...item.options].sort((a, b) => {
        const addedA = a.days.filter((d) => !currentDaySet.has(d)).length
        const addedB = b.days.filter((d) => !currentDaySet.has(d)).length
        return addedA - addedB
      })
    }

    let hasChosenOption = false

    for (const option of candidateOptions) {
      if (stepCount > MAX_SEARCH_STEPS) return

      // Check conflict with currently selected sections
      let conflict = false
      for (const selected of currentSections) {
        if (hasScheduleConflict(option.primarySection, selected)) {
          conflict = true
          break
        }
        if (
          option.pairedSection &&
          hasScheduleConflict(option.pairedSection, selected)
        ) {
          conflict = true
          break
        }
      }

      if (conflict) continue

      hasChosenOption = true
      currentSelections[item.primarySubject.subject_id] = option.primarySection.id
      currentSections.push(option.primarySection)

      if (item.pairedSubject && option.pairedSection) {
        currentSelections[item.pairedSubject.subject_id] = option.pairedSection.id
        currentSections.push(option.pairedSection)
      }

      const addedDays: number[] = []
      for (const d of option.days) {
        if (!currentDaySet.has(d)) {
          currentDaySet.add(d)
          addedDays.push(d)
        }
      }

      const optionScore =
        mode === "morning"
          ? option.morningScore
          : mode === "afternoon"
            ? option.afternoonScore
            : -addedDays.length * 10

      backtrack(
        itemIndex + 1,
        currentSelections,
        currentSections,
        currentModeScore + optionScore,
        currentDaySet,
        assignedSubjectsCount + subjectUnitsCount,
      )

      // Backtrack
      for (const d of addedDays) {
        currentDaySet.delete(d)
      }
      currentSections.pop()
      delete currentSelections[item.primarySubject.subject_id]
      if (item.pairedSubject && option.pairedSection) {
        currentSections.pop()
        delete currentSelections[item.pairedSubject.subject_id]
      }

      // Early exit if optimal solution found:
      // Concise: all subjects assigned into 1 day (minimum possible distinct days)
      if (
        mode === "concise" &&
        bestSolution.assignedCount === totalSubjects &&
        bestSolution.distinctDaysCount <= 1
      ) {
        return
      }

      // Morning / Afternoon: all subjects assigned
      if (
        (mode === "morning" || mode === "afternoon") &&
        bestSolution.assignedCount === totalSubjects &&
        bestSolution.modeScore >= totalSubjects * 10
      ) {
        return
      }
    }

    // Only skip this subject if NO valid non-conflicting option was found
    if (!hasChosenOption && stepCount <= MAX_SEARCH_STEPS) {
      backtrack(
        itemIndex + 1,
        currentSelections,
        currentSections,
        currentModeScore - 50,
        currentDaySet,
        assignedSubjectsCount,
      )
    }
  }

  backtrack(0, {}, [], 0, new Set(), 0)

  const matched = bestSolution.assignedCount > 0 ? bestSolution.assignedCount : 0
  const modeLabels: Record<RecommendationMode, string> = {
    manual: "Manual",
    concise: "Concise (1–2 Days)",
    morning: "Morning Classes",
    afternoon: "Afternoon / Evening Classes",
  }

  const daysCount =
    bestSolution.distinctDaysCount === Infinity
      ? 0
      : bestSolution.distinctDaysCount

  let summary = ""
  if (matched === totalSubjects) {
    if (mode === "concise") {
      summary = `All ${matched} subjects packed into ${daysCount} day${daysCount === 1 ? "" : "s"} with no schedule conflicts.`
    } else {
      summary = `All ${matched} subjects scheduled in ${modeLabels[mode]} with no schedule conflicts.`
    }
  } else if (matched > 0) {
    summary = `${matched} of ${totalSubjects} subjects scheduled without conflict. ${totalSubjects - matched} subject(s) require manual selection.`
  } else {
    summary = `No conflict-free combination found for ${modeLabels[mode]}. Try manual selection.`
  }

  return {
    mode,
    recommendations: bestSolution.selections,
    totalSubjects,
    matchedSubjects: matched,
    summary,
    distinctDaysCount: daysCount,
  }
}
