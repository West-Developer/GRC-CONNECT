import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { axe } from "vitest-axe"
import { describe, expect, it, vi } from "vitest"

import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import type { AcademicTerm } from "@/features/schemas/reference-data-schema"

function term(
  id: number,
  semester: "1st" | "2nd",
  status: AcademicTerm["status"],
): AcademicTerm {
  return {
    type: "academic-term",
    id,
    school_year: "2026-2027",
    semester,
    starts_at: null,
    ends_at: null,
    enrollment_opens_at: null,
    enrollment_closes_at: null,
    add_drop_deadline_at: null,
    grading_deadline_at: null,
    status,
    status_label: status,
  }
}

const CURRENT = term(37, "1st", "semester_ongoing")
const ARCHIVED = term(36, "2nd", "archived")

describe("AcademicTermSelector", () => {
  it("shows a disabled placeholder instead of an empty dropdown when there are no terms", () => {
    const onSelectTerm = vi.fn()
    render(
      <AcademicTermSelector
        sortedTerms={[]}
        term={null}
        isCurrentTerm={false}
        onSelectTerm={onSelectTerm}
      />,
    )

    const select = screen.getByLabelText("Academic term")
    expect(select).toBeDisabled()
    expect(select).toHaveDisplayValue("No academic terms available")
    // The old copy claimed an archived schedule was being viewed when nothing was selected.
    expect(screen.queryByText(/Viewing an archived schedule/)).toBeNull()
    expect(
      screen.getByText("No academic terms are available right now."),
    ).toBeInTheDocument()
    expect(onSelectTerm).not.toHaveBeenCalled()
  })

  it("says the terms are loading, not missing, while they are still being fetched", () => {
    render(
      <AcademicTermSelector
        sortedTerms={[]}
        term={null}
        isCurrentTerm={false}
        isLoading
        onSelectTerm={() => undefined}
      />,
    )

    const select = screen.getByLabelText("Academic term")
    expect(select).toBeDisabled()
    expect(select).toHaveAttribute("aria-busy", "true")
    expect(select).toHaveDisplayValue("Loading academic terms…")
    expect(
      screen.getByText("Loading school years and semesters…"),
    ).toBeInTheDocument()
    expect(screen.queryByText(/No academic terms/)).toBeNull()
  })

  it("asks the user to choose when terms exist but none is selected yet", () => {
    render(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={null}
        isCurrentTerm={false}
        onSelectTerm={() => undefined}
      />,
    )

    const select = screen.getByLabelText("Academic term")
    expect(select).toBeEnabled()
    expect(select).toHaveDisplayValue("Select a school year and semester")
    expect(
      screen.getByText(
        "Choose a school year and semester to view its schedule.",
      ),
    ).toBeInTheDocument()
  })

  it("keeps the current-term and archived-term copy when a term is selected", () => {
    const { rerender } = render(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={CURRENT}
        isCurrentTerm
        onSelectTerm={() => undefined}
      />,
    )
    expect(
      screen.getByText("Viewing the current term. Assignments are editable."),
    ).toBeInTheDocument()

    rerender(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={ARCHIVED}
        isCurrentTerm={false}
        onSelectTerm={() => undefined}
      />,
    )
    expect(screen.getByText(/Viewing an archived schedule/)).toBeInTheDocument()
    expect(screen.getByLabelText("Academic term")).toHaveDisplayValue(
      "2026-2027 · 2nd (Archived)",
    )
  })

  it("does not promise edits to a viewer who cannot edit the schedule", () => {
    render(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={CURRENT}
        isCurrentTerm
        onSelectTerm={() => undefined}
        readOnly
      />,
    )

    expect(screen.getByText("Viewing the current term.")).toBeInTheDocument()
    expect(screen.queryByText(/editable/)).not.toBeInTheDocument()
  })

  it("reports the chosen term id and ignores the empty placeholder", async () => {
    const user = userEvent.setup()
    const onSelectTerm = vi.fn()
    render(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={CURRENT}
        isCurrentTerm
        onSelectTerm={onSelectTerm}
      />,
    )

    await user.selectOptions(screen.getByLabelText("Academic term"), "36")

    expect(onSelectTerm).toHaveBeenCalledTimes(1)
    expect(onSelectTerm).toHaveBeenCalledWith(36)
  })

  it("has no accessibility violations in the empty and populated states", async () => {
    const empty = render(
      <AcademicTermSelector
        sortedTerms={[]}
        term={null}
        isCurrentTerm={false}
        onSelectTerm={() => undefined}
      />,
    )
    expect(await axe(empty.container)).toHaveNoViolations()
    empty.unmount()

    const populated = render(
      <AcademicTermSelector
        sortedTerms={[CURRENT, ARCHIVED]}
        term={CURRENT}
        isCurrentTerm
        onSelectTerm={() => undefined}
      />,
    )
    expect(await axe(populated.container)).toHaveNoViolations()
  })
})
