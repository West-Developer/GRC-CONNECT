import { describe, expect, it } from "vitest"

import { buildSectionGenerationRationale } from "@/features/lib/section-generation-rationale"
import type { Section, Subject } from "@/features/schemas/reference-data-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"
import type { ScheduleGenerationRun } from "@/features/schemas/schedule-generation-schema"

const subject: Subject = {
  type: "subject",
  id: 101,
  code: "IT101",
  title: "Introduction to Computing",
  units: 3,
  status: "active",
  status_label: "Active",
  is_completion_only: false,
}

function makeSection(overrides: Partial<Section> = {}): Section {
  return {
    type: "section",
    id: 1,
    academic_term_id: 5,
    section_plan_id: 70,
    subject_id: 101,
    section_code: "1A01",
    professor_id: null,
    schedule_days: null,
    starts_at_time: null,
    ends_at_time: null,
    room: null,
    modality: null,
    capacity: 40,
    capacity_source: "plan",
    recommendation_source: null,
    recommended_capacity: null,
    recommendation_prediction_run_id: null,
    manual_override_reason: null,
    viability_threshold: null,
    enrolled_count: 0,
    remaining_seats: 40,
    is_block_exclusive: true,
    status: "planned",
    status_label: "Planned",
    ...overrides,
  }
}

function makePlan(overrides: Partial<SectionPlan> = {}): SectionPlan {
  return {
    type: "academic-term-section-plan",
    id: 70,
    academic_term_id: 5,
    curriculum_id: 10,
    college: "ccs",
    year_level: 1,
    section_count: 3,
    students_per_block: 40,
    status: "draft",
    status_label: "Draft",
    submitted_at: null,
    recommendation_source: null,
    recommended_section_count: null,
    recommendation_is_overridden: false,
    recommendation_prediction_run_id: null,
    ...overrides,
  }
}

const baseRun: ScheduleGenerationRun = {
  type: "schedule_generation_run",
  id: 51,
  academic_term_id: 5,
  prediction_run_id: 9,
  college: "ccs",
  status: "succeeded",
  warnings: [],
  error_summary: null,
  started_at: null,
  completed_at: null,
  created_at: null,
  model: {
    strategy: "random_forest",
    model_version: "section-demand-rf-v1",
    training_observation_count: 12,
    mae: 1,
    rmse: 1,
  },
  forecasts: [
    {
      subject_id: 101,
      subject_code: "IT101",
      subject_title: "Introduction to Computing",
      year_level: 1,
      predicted_demand: 82.4,
      suggested_section_count: 3,
      confidence_lower: 74,
      confidence_upper: 89,
      historical_basis: {
        school_year: "2026-2027",
        semester: "1st",
        year_level: 1,
      },
      rationale: {
        model_strategy: "random_forest",
        section_formula: "ceil(predicted demand / recommended capacity)",
        recommended_capacity: 40,
      },
    },
  ],
  section_block_recommendations: [],
}

describe("buildSectionGenerationRationale", () => {
  it("collapses every physical section of the same subject+year into one group", () => {
    const sections = [
      makeSection({ id: 1, section_code: "1A01" }),
      makeSection({ id: 2, section_code: "1B01" }),
      makeSection({ id: 3, section_code: "1C01" }),
    ]
    const plans = [
      makePlan({
        recommendation_source: "predictive",
        recommendation_is_overridden: false,
      }),
    ]

    const groups = buildSectionGenerationRationale(
      sections,
      plans,
      [subject],
      baseRun,
    )

    expect(groups).toHaveLength(1)
    expect(groups[0].sectionCount).toBe(3)
  })

  it("explains a predictive, non-overridden group with the matching forecast", () => {
    const sections = [makeSection()]
    const plans = [
      makePlan({
        recommendation_source: "predictive",
        recommendation_is_overridden: false,
      }),
    ]

    const [group] = buildSectionGenerationRationale(
      sections,
      plans,
      [subject],
      baseRun,
    )

    expect(group.reasons.join(" ")).toContain("Predicted demand: 82")
    expect(group.reasons.join(" ")).toContain(
      "ceil(predicted demand / recommended capacity)",
    )
    expect(group.reasons.join(" ")).toContain("2026-2027")
  })

  it("falls back to an 'earlier run' explanation when no forecast matches in the current run", () => {
    const sections = [makeSection()]
    const plans = [
      makePlan({
        recommendation_source: "predictive",
        recommendation_is_overridden: false,
      }),
    ]
    const runWithoutMatchingForecast: ScheduleGenerationRun = {
      ...baseRun,
      forecasts: [],
    }

    const [group] = buildSectionGenerationRationale(
      sections,
      plans,
      [subject],
      runWithoutMatchingForecast,
    )

    expect(group.reasons).toEqual([
      "Generated from an earlier demand forecast — not part of the current run.",
    ])
  })

  it("explains a manually planned block (no forecast was ever available)", () => {
    const sections = [makeSection()]
    const plans = [makePlan({ recommendation_source: null })]

    const [group] = buildSectionGenerationRationale(
      sections,
      plans,
      [subject],
      baseRun,
    )

    expect(group.reasons).toEqual([
      "Manually planned by the Program Head — no demand forecast was available for this subject.",
    ])
  })

  it("explains a predictive block the Program Chair overrode", () => {
    const sections = [makeSection()]
    const plans = [
      makePlan({
        section_count: 2,
        recommendation_source: "predictive",
        recommended_section_count: 3,
        recommendation_is_overridden: true,
      }),
    ]

    const [group] = buildSectionGenerationRationale(
      sections,
      plans,
      [subject],
      baseRun,
    )

    expect(group.reasons).toEqual([
      "A demand forecast suggested 3 section(s); the Program Head set 2 instead.",
    ])
  })

  it("explains a section with no linked plan at all", () => {
    const sections = [makeSection({ section_plan_id: null })]

    const [group] = buildSectionGenerationRationale(
      sections,
      [],
      [subject],
      baseRun,
    )

    expect(group.reasons).toEqual([
      "Manually created outside the section plan workflow.",
    ])
  })
})
