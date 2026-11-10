import { screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"
import { axe } from "vitest-axe"

import { GradeSlipDocument } from "@/features/components/portal/grade-slip-document"
import type { GradeSlip } from "@/features/schemas/academic-record-schema"
import { renderWithSession } from "@/tests/render-app"

const row = {
  academic_grade_id: 3,
  code: "CS101",
  title: "Introduction to Computing",
  units: 3,
  mark: "1.50",
  mark_label: "with Distinction",
  final_grade: "1.50",
  section_code: "BSIT-1A",
  professor_name: "Prof. Reyes",
  status: "locked",
  status_label: "Locked",
  counts_toward_gpa: true,
} as const

const sectionlessRow = {
  ...row,
  academic_grade_id: 4,
  code: "LEAD1",
  title: "Leadership 1",
  mark: "C",
  mark_label: "Complete",
  final_grade: null,
  section_code: null,
  professor_name: null,
  counts_toward_gpa: false,
} as const

function gradeSlipFixture(
  overrides: Partial<{
    rows: GradeSlip["rows"]
    excluded_from_gpa_count: number
  }> = {},
): GradeSlip {
  return {
    type: "grade_slip",
    student_id: 4,
    student_number: "2026-0001",
    program_code: "BSIT",
    program_name: "BS Information Technology",
    year_level: 1,
    enrollment_category: "regular",
    enrollment_category_label: "Regular",
    academic_term_id: 2,
    school_year: "2026-2027",
    semester: "2nd",
    term_label: "2026-2027 · 2nd Semester",
    rows: overrides.rows ?? [row, sectionlessRow],
    total_academic_units: 6,
    gpa_units: 3,
    gpa: "1.50",
    excluded_from_gpa_count: overrides.excluded_from_gpa_count ?? 1,
    generated_at: "2026-07-30T00:00:00Z",
  }
}

describe("GradeSlipDocument", () => {
  it("renders the slip rows, totals, and GWA", () => {
    renderWithSession(<GradeSlipDocument slip={gradeSlipFixture()} />)

    expect(screen.getByText("CS101")).toBeInTheDocument()
    expect(screen.getByText("BSIT-1A")).toBeInTheDocument()
    expect(screen.getByText("Prof. Reyes")).toBeInTheDocument()
    expect(screen.getByText("6")).toBeInTheDocument()
    expect(screen.getAllByText("1.50")).toHaveLength(2)
  })

  it("shows a dash for a section-less grade instead of failing", () => {
    renderWithSession(<GradeSlipDocument slip={gradeSlipFixture()} />)

    expect(screen.getByText("LEAD1")).toBeInTheDocument()
    expect(screen.getAllByText("—").length).toBeGreaterThanOrEqual(2)
  })

  it("notes how many subjects are excluded from the GPA", () => {
    renderWithSession(<GradeSlipDocument slip={gradeSlipFixture()} />)

    expect(
      screen.getByText(/1 subject.*excluded from the GWA/),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    const { container } = renderWithSession(
      <GradeSlipDocument slip={gradeSlipFixture()} />,
    )

    expect(await axe(container)).toHaveNoViolations()
  })
})
