import { describe, expect, it } from "vitest"

import { buildPrescriptiveRecommendations } from "@/features/lib/prescriptive-recommendations"
import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"

function baseRun(
  overrides: Partial<ScheduleGenerationRun> = {},
): ScheduleGenerationRun {
  return {
    type: "schedule_generation_run",
    id: 1,
    academic_term_id: 1,
    prediction_run_id: 1,
    college: "ccs",
    status: "succeeded",
    warnings: [],
    error_summary: null,
    started_at: null,
    completed_at: null,
    created_at: null,
    ...overrides,
  }
}

describe("buildPrescriptiveRecommendations", () => {
  it("returns no sentences when neither forecasts nor faculty_load are present", () => {
    expect(buildPrescriptiveRecommendations(baseRun())).toEqual([])
  })

  it("derives a sentence per forecast using only its own numbers", () => {
    const run = baseRun({
      forecasts: [
        {
          subject_id: 1,
          subject_code: "CS101",
          subject_title: "Intro to Programming",
          year_level: 1,
          predicted_demand: 45,
          suggested_section_count: 2,
          confidence_lower: 40,
          confidence_upper: 50,
          historical_basis: {
            school_year: "2024-2025",
            semester: "1st",
            year_level: 1,
          },
          rationale: {},
        },
      ],
    })

    const recommendations = buildPrescriptiveRecommendations(run)

    expect(recommendations).toEqual([
      "Expected 45 CS101 students (Year 1). Action Recommended: Plan 2 section(s).",
    ])
  })

  it("omits the year label when a forecast has no year_level", () => {
    const run = baseRun({
      forecasts: [
        {
          subject_id: 2,
          subject_code: "GE101",
          subject_title: "General Education Elective",
          year_level: null,
          predicted_demand: 30,
          suggested_section_count: 1,
          confidence_lower: null,
          confidence_upper: null,
          historical_basis: {
            school_year: null,
            semester: null,
            year_level: null,
          },
          rationale: {},
        },
      ],
    })

    expect(buildPrescriptiveRecommendations(run)).toEqual([
      "Expected 30 GE101 students. Action Recommended: Plan 1 section(s).",
    ])
  })

  it("adds an unassigned-load sentence only when unassigned_count is positive", () => {
    const run = baseRun({
      faculty_load: {
        academic_term_id: 1,
        college: "ccs",
        threshold_units: 24,
        required_teaching_units: 90,
        required_assignments: 6,
        equivalent_faculty_loads: 4,
        assigned_count: 4,
        unassigned_count: 2,
        overloaded_count: 0,
        faculty: [],
        unassigned: [],
      },
    })

    expect(buildPrescriptiveRecommendations(run)).toEqual([
      "2 teaching assignment(s) are unassigned. Action Recommended: Assign a professor before publishing the schedule.",
    ])
  })

  it("adds an overload sentence only when overloaded_count is positive", () => {
    const run = baseRun({
      faculty_load: {
        academic_term_id: 1,
        college: "ccs",
        threshold_units: 24,
        required_teaching_units: 90,
        required_assignments: 6,
        equivalent_faculty_loads: 4,
        assigned_count: 6,
        unassigned_count: 0,
        overloaded_count: 3,
        faculty: [],
        unassigned: [],
      },
    })

    expect(buildPrescriptiveRecommendations(run)).toEqual([
      "3 professor(s) are over the configured teaching load threshold. Action Recommended: Redistribute their assignments or adjust the threshold.",
    ])
  })

  it("emits no faculty-load sentences when both counts are zero", () => {
    const run = baseRun({
      faculty_load: {
        academic_term_id: 1,
        college: "ccs",
        threshold_units: 24,
        required_teaching_units: 90,
        required_assignments: 6,
        equivalent_faculty_loads: 4,
        assigned_count: 6,
        unassigned_count: 0,
        overloaded_count: 0,
        faculty: [],
        unassigned: [],
      },
    })

    expect(buildPrescriptiveRecommendations(run)).toEqual([])
  })

  it("combines forecast and faculty_load sentences in order", () => {
    const run = baseRun({
      forecasts: [
        {
          subject_id: 1,
          subject_code: "CS101",
          subject_title: "Intro to Programming",
          year_level: 1,
          predicted_demand: 45,
          suggested_section_count: 2,
          confidence_lower: null,
          confidence_upper: null,
          historical_basis: {
            school_year: null,
            semester: null,
            year_level: null,
          },
          rationale: {},
        },
      ],
      faculty_load: {
        academic_term_id: 1,
        college: "ccs",
        threshold_units: 24,
        required_teaching_units: 90,
        required_assignments: 6,
        equivalent_faculty_loads: 4,
        assigned_count: 4,
        unassigned_count: 2,
        overloaded_count: 1,
        faculty: [],
        unassigned: [],
      },
    })

    expect(buildPrescriptiveRecommendations(run)).toEqual([
      "Expected 45 CS101 students (Year 1). Action Recommended: Plan 2 section(s).",
      "2 teaching assignment(s) are unassigned. Action Recommended: Assign a professor before publishing the schedule.",
      "1 professor(s) are over the configured teaching load threshold. Action Recommended: Redistribute their assignments or adjust the threshold.",
    ])
  })
})
