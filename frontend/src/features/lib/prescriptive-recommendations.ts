import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"

// Pure client-side rule engine over the existing Demand Forecast payload —
// zero backend/ML change. Every number in the sentences below already exists
// on `run.forecasts` (predicted_demand, suggested_section_count, subject/year
// fields) or `run.faculty_load` (unassigned/overloaded counts); only the
// sentence templating is new here. No new thresholds are invented.
export function buildPrescriptiveRecommendations(
  run: ScheduleGenerationRun,
): string[] {
  const recommendations: string[] = []

  for (const forecast of run.forecasts ?? []) {
    const yearLabel =
      forecast.year_level === null ? "" : ` (Year ${forecast.year_level})`
    recommendations.push(
      `Expected ${forecast.predicted_demand.toFixed(0)} ${forecast.subject_code} students${yearLabel}. Action Recommended: Plan ${forecast.suggested_section_count} section(s).`,
    )
  }

  const facultyLoad = run.faculty_load
  if (facultyLoad) {
    if (facultyLoad.unassigned_count > 0) {
      recommendations.push(
        `${facultyLoad.unassigned_count} teaching assignment(s) are unassigned. Action Recommended: Assign a professor before publishing the schedule.`,
      )
    }
    if (facultyLoad.overloaded_count > 0) {
      recommendations.push(
        `${facultyLoad.overloaded_count} professor(s) are over the configured teaching load threshold. Action Recommended: Redistribute their assignments or adjust the threshold.`,
      )
    }
  }

  return recommendations
}
