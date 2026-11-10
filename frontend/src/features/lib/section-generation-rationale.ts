import type { Section, Subject } from "@/features/schemas/reference-data-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"
import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"

export interface SectionGenerationRationaleGroup {
  subjectId: number
  subjectCode: string
  subjectTitle: string
  yearLevel: number | null
  curriculumName?: string | null
  curriculumVersion?: string | null
  sectionCount: number
  reasons: string[]
}

function modelStrategyLabel(strategy: string | null | undefined): string {
  if (strategy === "random_forest") return "Random Forest model"
  if (strategy === "historical_baseline") return "historical baseline"
  if (strategy === "service_unavailable_historical_baseline")
    return "historical fallback"
  return "the demand forecast model"
}

// Every reason here is templated from data that already exists — no new
// backend collection. This mirrors the rationale-badge pattern already
// shipped for faculty assignments in the Faculty Load Report tab.
function reasonsForSection(
  section: Section,
  plan: SectionPlan | undefined,
  run: ScheduleGenerationRun | null,
): string[] {
  if (section.section_plan_id === null) {
    return ["Manually created outside the section plan workflow."]
  }

  if (plan?.recommendation_source == null) {
    return [
      "Manually planned by the Program Head — no demand forecast was available for this subject.",
    ]
  }

  if (
    plan.recommendation_source === "predictive" &&
    plan.recommendation_is_overridden
  ) {
    return [
      `A demand forecast suggested ${plan.recommended_section_count ?? "—"} section(s); the Program Head set ${plan.section_count} instead.`,
    ]
  }

  const forecast = run?.forecasts?.find(
    (candidate) =>
      candidate.subject_id === section.subject_id &&
      candidate.year_level === plan.year_level,
  )

  if (!forecast) {
    return [
      "Generated from an earlier demand forecast — not part of the current run.",
    ]
  }

  const sectionFormula = forecast.rationale.section_formula
  const reasons = [
    `Predicted demand: ${Math.round(forecast.predicted_demand)} students (${modelStrategyLabel(run?.model?.strategy)}).`,
    `Suggested ${forecast.suggested_section_count} section(s)${typeof sectionFormula === "string" ? `, formula: ${sectionFormula}` : ""}.`,
  ]
  reasons.push(
    forecast.historical_basis.school_year
      ? `Based on ${forecast.historical_basis.school_year} · ${forecast.historical_basis.semester} · Year ${forecast.historical_basis.year_level} history.`
      : "No usable history for this forecast.",
  )

  return reasons
}

/**
 * Groups every generated section by subject + year level and explains why
 * each group exists, whether it came from a demand forecast (predicted,
 * overridden, or stale) or was planned by hand with no forecast available.
 */
export function buildSectionGenerationRationale(
  sections: readonly Section[],
  plans: readonly SectionPlan[],
  subjects: readonly Subject[],
  run: ScheduleGenerationRun | null,
): SectionGenerationRationaleGroup[] {
  const planById = new Map(plans.map((plan) => [plan.id, plan]))
  const subjectById = new Map(subjects.map((subject) => [subject.id, subject]))
  const groups = new Map<string, SectionGenerationRationaleGroup>()

  for (const section of sections) {
    const plan = section.section_plan_id
      ? planById.get(section.section_plan_id)
      : undefined
    const yearLevel = plan?.year_level ?? null
    const key = `${section.subject_id}:${yearLevel ?? "none"}`
    const reasons = reasonsForSection(section, plan, run)
    const existing = groups.get(key)

    if (existing) {
      existing.sectionCount += 1
      for (const reason of reasons) {
        if (!existing.reasons.includes(reason)) existing.reasons.push(reason)
      }
      continue
    }

    const forecast = run?.forecasts?.find(
      (candidate) =>
        candidate.subject_id === section.subject_id &&
        candidate.year_level === plan?.year_level,
    )
    const subject = subjectById.get(section.subject_id)
    groups.set(key, {
      subjectId: section.subject_id,
      subjectCode: subject?.code ?? `Subject #${section.subject_id}`,
      subjectTitle: subject?.title ?? "",
      yearLevel,
      curriculumName: forecast?.curriculum_name ?? null,
      curriculumVersion: forecast?.curriculum_effective_school_year ?? null,
      sectionCount: 1,
      reasons: [...reasons],
    })
  }

  return [...groups.values()].sort(
    (left, right) =>
      left.subjectCode.localeCompare(right.subjectCode) ||
      (left.yearLevel ?? 0) - (right.yearLevel ?? 0),
  )
}
