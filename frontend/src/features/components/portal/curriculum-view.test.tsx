import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it } from "vitest"

import { CurriculumView } from "@/features/components/portal/curriculum-view"
import type {
  Curriculum,
  Program,
} from "@/features/schemas/reference-data-schema"
import { renderWithSession } from "@/tests/render-app"

const programs: Program[] = [
  {
    type: "program",
    id: 1,
    code: "BSA",
    name: "BS Accountancy",
    status: "active",
    status_label: "Active",
  },
  {
    type: "program",
    id: 2,
    code: "BSIT",
    name: "BS Information Technology",
    status: "active",
    status_label: "Active",
  },
]

const curricula: Curriculum[] = [
  {
    type: "curriculum",
    id: 1,
    program_id: 1,
    name: "BSA 2024-2029",
    effective_school_year: "2024-2029",
    status: "active",
    status_label: "Active",
    decided_at: null,
    last_decision_reason: null,
    subjects: [
      {
        subject_id: 11,
        code: "ACC101",
        title: "Financial Accounting",
        units: 3,
        year_level: 1,
        semester: "1st",
        is_required: true,
        prerequisites: [],
      },
      {
        subject_id: 12,
        code: "NSTP1",
        title: "National Service Training Program 1",
        units: 3,
        year_level: 1,
        semester: "1st|2nd",
        is_required: true,
        prerequisites: [],
      },
      {
        subject_id: 13,
        code: "ACC201",
        title: "Cost Accounting",
        units: 3,
        year_level: 2,
        semester: "2nd",
        is_required: true,
        prerequisites: [
          {
            prerequisite_subject_id: 11,
            code: "ACC101",
            minimum_grade: "75",
          },
        ],
      },
    ],
  },
  {
    type: "curriculum",
    id: 2,
    program_id: 1,
    name: "BSA 2018-2023",
    effective_school_year: "2018-2023",
    status: "archived",
    status_label: "Archived",
    decided_at: null,
    last_decision_reason: null,
    subjects: [
      {
        subject_id: 14,
        code: "OLD101",
        title: "Old Subject",
        units: 3,
        year_level: 1,
        semester: "1st",
        is_required: true,
        prerequisites: [],
      },
    ],
  },
  {
    type: "curriculum",
    id: 3,
    program_id: 2,
    name: "BSIT 2024-2029",
    effective_school_year: "2024-2029",
    status: "active",
    status_label: "Active",
    decided_at: null,
    last_decision_reason: null,
    subjects: [
      {
        subject_id: 21,
        code: "ITC",
        title: "Introduction to Computing",
        units: 2,
        year_level: 1,
        semester: "1st",
        is_required: true,
        prerequisites: [],
      },
    ],
  },
]

function render(
  overrides: {
    programs?: readonly Program[]
    curricula?: readonly Curriculum[]
  } = {},
) {
  return renderWithSession(
    <CurriculumView
      programs={overrides.programs ?? programs}
      curricula={overrides.curricula ?? curricula}
    />,
  )
}

/** The `<table>` whose caption matches `captionText`. */
function tableFor(captionText: string): HTMLElement {
  const caption = screen.getByText(captionText)
  const table = caption.closest("table")
  if (!table) throw new Error(`No table found for caption "${captionText}"`)
  return table
}

async function selectOption(
  user: ReturnType<typeof userEvent.setup>,
  labelText: string,
  optionName: string,
) {
  await user.click(screen.getByLabelText(labelText))
  await user.click(await screen.findByRole("option", { name: optionName }))
}

describe("CurriculumView", () => {
  it("renders a compact single-table confirmation preview without editable filters", () => {
    renderWithSession(
      <CurriculumView
        programs={[programs[0]]}
        curricula={[curricula[0]]}
        preview
      />,
    )

    expect(screen.queryByLabelText("Program")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Curriculum")).not.toBeInTheDocument()
    expect(screen.getAllByRole("table")).toHaveLength(1)
    expect(screen.getByText("1st Year · 1st Semester")).toBeInTheDocument()
    expect(screen.getByText("2nd Year · 2nd Semester")).toBeInTheDocument()
  })

  it("defaults to the alphabetically first program's newest curriculum, grouped by year and semester, with Code/Description/Units/Prerequisites columns", () => {
    render()

    expect(screen.getByLabelText("Program")).toHaveTextContent(
      "BSA — BS Accountancy",
    )
    expect(screen.getByLabelText("Curriculum")).toHaveTextContent(
      "BSA 2024-2029",
    )
    expect(screen.getByLabelText("Curriculum")).toHaveTextContent(
      "New curriculum",
    )

    const firstYearFirstSem = tableFor("1st Year · 1st Semester")
    expect(within(firstYearFirstSem).getByText("ACC101")).toBeInTheDocument()
    expect(within(firstYearFirstSem).getByText("NSTP1")).toBeInTheDocument()
    const headers = within(firstYearFirstSem)
      .getAllByRole("columnheader")
      .map((cell) => cell.textContent)
    expect(headers).toEqual(["Code", "Description", "Units", "Prerequisites"])

    const firstYearSecondSem = tableFor("1st Year · 2nd Semester")
    expect(within(firstYearSecondSem).getByText("NSTP1")).toBeInTheDocument()
    expect(
      within(firstYearSecondSem).queryByText("ACC101"),
    ).not.toBeInTheDocument()

    const secondYearSecondSem = tableFor("2nd Year · 2nd Semester")
    expect(within(secondYearSecondSem).getByText("ACC201")).toBeInTheDocument()

    // The archived 2018-2023 curriculum isn't shown until picked below.
    expect(screen.queryByText("OLD101")).not.toBeInTheDocument()
  })

  it("shows each subject's prerequisites, or None when it has no prerequisites", () => {
    render()

    const firstYearFirstSem = tableFor("1st Year · 1st Semester")
    const acc101Row = within(firstYearFirstSem)
      .getByText("ACC101")
      .closest("tr")
    if (!acc101Row) throw new Error("ACC101 row not found")
    expect(within(acc101Row).getByText("None")).toBeInTheDocument()

    const secondYearSecondSem = tableFor("2nd Year · 2nd Semester")
    const acc201Row = within(secondYearSecondSem)
      .getByText("ACC201")
      .closest("tr")
    if (!acc201Row) throw new Error("ACC201 row not found")
    expect(within(acc201Row).getByText("ACC101")).toBeInTheDocument()
  })

  it("shows only final-approved curricula in the published catalog", async () => {
    const user = userEvent.setup()
    render()

    await user.click(screen.getByLabelText("Curriculum"))

    expect(
      screen.queryByRole("option", {
        name: "BSA 2018-2023 · Old curriculum",
      }),
    ).not.toBeInTheDocument()
  })

  it("resets to the newest curriculum when switching programs", async () => {
    const user = userEvent.setup()
    render()

    await selectOption(user, "Program", "BSIT — BS Information Technology")

    expect(screen.getByLabelText("Curriculum")).toHaveTextContent(
      "BSIT 2024-2029",
    )
    expect(screen.getByText("ITC")).toBeInTheDocument()
  })

  it("filters to a single year level and semester", async () => {
    const user = userEvent.setup()
    render()

    await selectOption(user, "Year level", "2nd Year")
    await selectOption(user, "Semester", "2nd Semester")

    expect(screen.getByText("2nd Year · 2nd Semester")).toBeInTheDocument()
    expect(
      screen.queryByText("1st Year · 1st Semester"),
    ).not.toBeInTheDocument()
    // NSTP1 only lives in 1st year and isn't anyone's prerequisite, so it's
    // a clean signal the 1st-year row is gone (unlike ACC101, which still
    // legitimately appears here as ACC201's Prerequisites badge).
    expect(screen.queryByText("NSTP1")).not.toBeInTheDocument()
    expect(screen.getByText("ACC201")).toBeInTheDocument()
  })

  it("switches to another program's active curriculum", async () => {
    const user = userEvent.setup()
    render()

    await selectOption(user, "Program", "BSIT — BS Information Technology")

    expect(screen.getByText("ITC")).toBeInTheDocument()
    expect(screen.queryByText("ACC101")).not.toBeInTheDocument()
  })

  it("shows an empty state when every curriculum is still a draft", () => {
    render({
      curricula: [{ ...curricula[0], status: "draft", status_label: "Draft" }],
    })

    expect(
      screen.getByText("No curriculum is available to view yet."),
    ).toBeInTheDocument()
    expect(screen.queryByRole("table")).not.toBeInTheDocument()
  })

  it("shows an empty state when the filters exclude every subject", async () => {
    const user = userEvent.setup()
    render()

    await selectOption(user, "Year level", "4th Year")

    expect(
      screen.getByText("No subjects match the selected filters."),
    ).toBeInTheDocument()
    expect(screen.queryByRole("table")).not.toBeInTheDocument()
  })
})
