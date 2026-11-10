import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { useState } from "react"
import { describe, expect, it, vi } from "vitest"

import { CurriculumSubjectSpreadsheet } from "@/features/components/portal/curriculum-subject-spreadsheet"
import type { CurriculumSubjectInput } from "@/features/schemas/curriculum-schema"
import type { Subject } from "@/features/schemas/reference-data-schema"

const catalog: Subject[] = [
  {
    type: "subject",
    id: 11,
    code: "CS101",
    title: "Programming 1",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
  {
    type: "subject",
    id: 12,
    code: "CS102",
    title: "Data Structures",
    units: 3,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
  {
    type: "subject",
    id: 13,
    code: "CS201",
    title: "Algorithms",
    units: 4,
    status: "active",
    status_label: "Active",
    is_completion_only: false,
  },
]

const placements: CurriculumSubjectInput[] = [
  {
    subject_id: 11,
    year_level: 1,
    semester: "1st",
    is_required: true,
    prerequisites: [],
  },
  {
    subject_id: 12,
    year_level: 2,
    semester: "2nd",
    is_required: true,
    prerequisites: [],
  },
]

function ControlledSpreadsheet({ locked = false }: { locked?: boolean }) {
  const [subjects, setSubjects] = useState(placements)

  return (
    <CurriculumSubjectSpreadsheet
      yearLevel={1}
      subjects={subjects}
      subjectCatalog={catalog}
      prerequisiteSubjects={catalog.slice(1)}
      isLocked={locked}
      onChange={setSubjects}
      onAddRow={vi.fn()}
    />
  )
}

async function chooseOption(
  user: ReturnType<typeof userEvent.setup>,
  label: string,
  option: string,
) {
  await user.click(screen.getByLabelText(label))
  await user.click(await screen.findByRole("option", { name: option }))
}

describe("CurriculumSubjectSpreadsheet", () => {
  it("shows only the active year's rows under the five curriculum columns", () => {
    render(<ControlledSpreadsheet />)

    const table = screen.getByRole("table", { name: "1st Year subjects" })
    expect(
      within(table)
        .getAllByRole("columnheader")
        .map((cell) => cell.textContent),
    ).toEqual([
      "Subject Code",
      "Description",
      "Units",
      "Semester",
      "Prerequisite",
    ])
    expect(within(table).getByText("CS101")).toBeInTheDocument()
    expect(within(table).queryByText("CS102")).not.toBeInTheDocument()
    expect(within(table).getByText("Programming 1")).toBeInTheDocument()
    expect(within(table).getByText("3")).toBeInTheDocument()
  })

  it("stores the 1st and 2nd semester API values while showing full labels", async () => {
    const user = userEvent.setup()
    render(<ControlledSpreadsheet />)

    await chooseOption(user, "Semester for CS101", "2nd Semester")

    expect(screen.getByLabelText("Semester for CS101")).toHaveTextContent(
      "2nd Semester",
    )
    await chooseOption(user, "Semester for CS101", "1st Semester")
    expect(screen.getByLabelText("Semester for CS101")).toHaveTextContent(
      "1st Semester",
    )
  })

  it("adds a searchable subject from the latest active curriculum as a prerequisite", async () => {
    const user = userEvent.setup()
    render(<ControlledSpreadsheet />)

    await user.click(
      screen.getByRole("button", { name: "Add prerequisite for CS101" }),
    )
    await user.type(screen.getByLabelText("Search prerequisites"), "CS201")
    await user.click(screen.getByRole("button", { name: "CS201 — Algorithms" }))

    expect(screen.getByText("CS201")).toBeInTheDocument()
    expect(screen.queryByText(/75/)).not.toBeInTheDocument()
  })

  it("offers None to clear an existing prerequisite", async () => {
    const user = userEvent.setup()
    render(<ControlledSpreadsheet />)

    await user.click(
      screen.getByRole("button", { name: "Add prerequisite for CS101" }),
    )
    await user.click(
      screen.getByRole("button", { name: "CS102 — Data Structures" }),
    )
    await user.click(
      screen.getByRole("button", { name: "Edit prerequisites for CS101" }),
    )
    await user.click(screen.getByRole("button", { name: "None" }))

    expect(screen.getByText("None")).toBeInTheDocument()
  })

  it("opens the prerequisite editor when a row already has prerequisites", async () => {
    const user = userEvent.setup()
    render(<ControlledSpreadsheet />)

    await user.click(
      screen.getByRole("button", { name: "Add prerequisite for CS101" }),
    )
    await user.click(
      screen.getByRole("button", { name: "CS102 — Data Structures" }),
    )

    expect(
      screen.getByRole("button", { name: "Edit prerequisites for CS101" }),
    ).toBeInTheDocument()
    await user.click(
      screen.getByRole("button", { name: "Edit prerequisites for CS101" }),
    )
    expect(
      screen.getByRole("dialog", { name: "Edit prerequisites for CS101" }),
    ).toBeInTheDocument()
  })

  it("disables row creation, semester, prerequisite, and removal controls when locked", () => {
    render(<ControlledSpreadsheet locked />)

    expect(
      screen.getByRole("button", { name: "Add subject row" }),
    ).toBeDisabled()
    expect(screen.getByLabelText("Semester for CS101")).toBeDisabled()
    expect(
      screen.getByRole("button", { name: "Add prerequisite for CS101" }),
    ).toBeDisabled()
    expect(
      screen.getByRole("button", { name: "Remove CS101 row" }),
    ).toBeDisabled()
  })
})
