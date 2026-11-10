import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { DemandForecastDialog } from "@/features/components/portal/demand-forecast-dialog"
import type { Section, Subject } from "@/features/schemas/reference-data-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"

const subjects: Subject[] = [
  {
    type: "subject",
    id: 5,
    code: "CS101",
    title: "Introduction to Computing",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
  {
    type: "subject",
    id: 6,
    code: "IT201",
    title: "Data Structures",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
]

const plans: SectionPlan[] = [
  {
    type: "academic-term-section-plan",
    id: 70,
    academic_term_id: 3,
    curriculum_id: 10,
    college: "ccs",
    year_level: 1,
    section_count: 1,
    students_per_block: 40,
    status: "draft",
    status_label: "Draft",
    submitted_at: null,
    recommendation_source: "predictive",
    recommended_section_count: 3,
    recommendation_is_overridden: false,
    recommendation_prediction_run_id: 7,
  },
  {
    type: "academic-term-section-plan",
    id: 71,
    academic_term_id: 3,
    curriculum_id: 10,
    college: "ccs",
    year_level: 2,
    section_count: 1,
    students_per_block: 40,
    status: "draft",
    status_label: "Draft",
    submitted_at: null,
    recommendation_source: null,
    recommended_section_count: null,
    recommendation_is_overridden: true,
    recommendation_prediction_run_id: null,
  },
]

const sections: Section[] = [
  {
    type: "section",
    id: 21,
    academic_term_id: 3,
    section_plan_id: 70,
    subject_id: 5,
    section_code: "1A01",
    professor_id: null,
    schedule_days: null,
    starts_at_time: null,
    ends_at_time: null,
    room: null,
    modality: null,
    capacity: 40,
    capacity_source: "plan",
    recommendation_source: "predictive",
    recommended_capacity: 40,
    recommendation_prediction_run_id: 7,
    manual_override_reason: null,
    viability_threshold: null,
    enrolled_count: 0,
    remaining_seats: 40,
    is_block_exclusive: true,
    status: "planned",
    status_label: "Planned",
  },
  {
    type: "section",
    id: 22,
    academic_term_id: 3,
    section_plan_id: 71,
    subject_id: 6,
    section_code: "2A01",
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
  },
]

describe("DemandForecastDialog", () => {
  it("shows advisory demand and faculty-load evidence, then closes through its explicit action", async () => {
    const onOpenChange = vi.fn()
    const user = userEvent.setup()

    render(
      <DemandForecastDialog
        open
        onOpenChange={onOpenChange}
        run={{
          type: "schedule_generation_run",
          id: 8,
          academic_term_id: 3,
          prediction_run_id: 7,
          college: "ccs",
          status: "succeeded",
          warnings: [
            {
              type: "room_metadata_incomplete",
              message: "Section CS101-A is missing room metadata.",
              entity_id: 12,
            },
            {
              type: "room_metadata_incomplete",
              message: "Section CS101-B is missing room metadata.",
              entity_id: 34,
            },
            {
              type: "no_historical_data",
              message: "No historical data was available for this term.",
              entity_id: null,
            },
          ],
          error_summary: null,
          started_at: null,
          completed_at: null,
          created_at: null,
          model: {
            strategy: "random_forest",
            model_version: "section-demand-rf-v1",
            training_observation_count: 12,
            mae: 2.1,
            rmse: 2.8,
          },
          forecasts: [
            {
              subject_id: 5,
              subject_code: "CS101",
              subject_title: "Introduction to Computing",
              year_level: 1,
              predicted_demand: 82,
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
                section_formula:
                  "ceil(predicted demand / recommended capacity)",
                recommended_capacity: 40,
              },
            },
          ],
          faculty_load: {
            academic_term_id: 3,
            college: "ccs",
            threshold_units: 18,
            required_teaching_units: 9,
            required_assignments: 3,
            equivalent_faculty_loads: 1,
            assigned_count: 2,
            unassigned_count: 1,
            overloaded_count: 0,
            faculty: [],
            unassigned: [],
          },
        }}
        sections={sections}
        plans={plans}
        subjects={subjects}
      />,
    )

    const dialog = screen.getByRole("dialog")
    expect(dialog).toHaveTextContent("Demand Forecast")
    expect(dialog).toHaveTextContent("Random Forest")
    expect(dialog).toHaveTextContent("Faculty Loading Support")
    expect(dialog).toHaveTextContent(
      "No historical data was available for this term.",
    )
    expect(screen.getByRole("button", { name: "Show 2" })).toBeInTheDocument()
    expect(
      screen.getByRole("link", { name: /View in Analytics/ }),
    ).toHaveAttribute("href", "/portal/program-chair-analytics")

    // The old sparse "Section Demand Forecasting" table is gone.
    expect(
      screen.queryByRole("region", { name: "Section demand forecasts" }),
    ).not.toBeInTheDocument()
    expect(screen.queryByText("Predicted students")).not.toBeInTheDocument()
    expect(screen.queryByText("Sections needed")).not.toBeInTheDocument()

    // Replaced by a "why this section exists" explanation per subject/year.
    const rationale = screen.getByRole("region", {
      name: "Why these sections were generated",
    })
    await user.click(
      within(rationale).getByRole("button", { name: "View explanations" }),
    )
    expect(within(rationale).getByText(/CS101/)).toBeInTheDocument()
    // Both fixture groups happen to have exactly one section each — assert
    // the "N section(s)" badge renders correctly for both rather than
    // picking one arbitrarily.
    expect(within(rationale).getAllByText("1 section")).toHaveLength(2)
    expect(
      within(rationale).getByText(/Predicted demand: 82/),
    ).toBeInTheDocument()
    expect(within(rationale).getByText(/IT201/)).toBeInTheDocument()
    expect(
      within(rationale).getByText(
        "Manually planned by the Program Head — no demand forecast was available for this subject.",
      ),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Close forecast" }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
