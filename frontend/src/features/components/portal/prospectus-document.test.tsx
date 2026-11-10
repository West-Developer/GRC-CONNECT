import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ProspectusDocument } from "@/features/components/portal/prospectus-document"
import { renderWithSession } from "@/tests/render-app"

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const takenEntry = {
  subject_id: 7,
  code: "CS101",
  title: "Introduction to Computing",
  units: 3,
  is_required: true,
  offered_either_semester: false,
  is_completion_only: false,
  mark: "1.50",
  mark_label: "with Distinction",
  final_grade: "1.50",
  status: "locked",
  status_label: "Locked",
  academic_term_id: 2,
  term_label: "2025-2026 · 1st",
  attempt_count: 1,
  prerequisites: [
    {
      subject_id: 6,
      code: "CS100",
      title: "Intro to Programming",
      minimum_grade: "75",
    },
  ],
} as const

const untakenEntry = {
  subject_id: 8,
  code: "CS102",
  title: "Data Structures",
  units: 3,
  is_required: true,
  offered_either_semester: false,
  is_completion_only: false,
  mark: null,
  mark_label: null,
  final_grade: null,
  status: null,
  status_label: null,
  academic_term_id: null,
  term_label: null,
  attempt_count: 0,
  prerequisites: [],
} as const

const unplacedEntry = {
  subject_id: 99,
  code: "ELEC1",
  title: "Free Elective",
  units: 3,
  mark: "1.00",
  mark_label: "Excellent",
  final_grade: "1.00",
  status: "locked",
  status_label: "Locked",
  academic_term_id: 3,
  term_label: "2025-2026 · 2nd",
} as const

function prospectusFixture(
  overrides: Partial<{
    semesters: unknown[]
    unplaced_entries: unknown[]
    curriculum_transition: unknown
    transferee_credits: unknown[]
  }> = {},
) {
  return {
    data: {
      type: "prospectus",
      student_id: 4,
      student_number: "2026-0001",
      program_code: "BSIT",
      program_name: "BS Information Technology",
      curriculum_id: 1,
      curriculum_name: "BSIT 2023 Curriculum",
      effective_school_year: "2023-2024",
      year_level: 1,
      enrollment_category: "regular",
      enrollment_category_label: "Regular",
      enrollment_category_derived_at: "2026-07-30T00:00:00Z",
      semesters: overrides.semesters ?? [
        {
          year_level: 1,
          semester: "1st",
          semester_label: "1st Semester",
          entries: [takenEntry, untakenEntry],
        },
      ],
      unplaced_entries: overrides.unplaced_entries ?? [],
      curriculum_transition: overrides.curriculum_transition ?? null,
      transferee_credits: overrides.transferee_credits ?? [],
    },
  }
}

describe("ProspectusDocument", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("renders a table per semester with taken and untaken subjects", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(prospectusFixture())),
    )

    renderWithSession(<ProspectusDocument />)

    expect(
      await screen.findByText(/BS Information Technology/),
    ).toBeInTheDocument()
    expect(screen.getByText(/1st Year · 1st Semester/)).toBeInTheDocument()
    // The Grade column shows the mark itself (numeric/C/NC), never the
    // "Good"/"Very Good" label -- that label only appears in the Status badge.
    expect(screen.getByText("1.50")).toBeInTheDocument()
    expect(screen.queryByText("with Distinction")).not.toBeInTheDocument()
    expect(screen.getByText("Not taken")).toBeInTheDocument()
  })

  it("shows a subject's pre-requisite codes, or a dash when it has none", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(prospectusFixture())),
    )

    renderWithSession(<ProspectusDocument />)

    const takenRow = (await screen.findByText("CS101")).closest("tr")!
    expect(within(takenRow).getByText("CS100")).toBeInTheDocument()

    const untakenRow = screen.getByText("CS102").closest("tr")!
    const prerequisiteCell = within(untakenRow).getAllByRole("cell")[2]
    expect(prerequisiteCell).toHaveTextContent("—")
  })

  it("colors a failed, incomplete, or not-taken row distinctly from a passed one", async () => {
    const failedEntry = {
      ...takenEntry,
      subject_id: 20,
      code: "CS999",
      mark: "5.00",
      mark_label: "Failed",
      status_label: "Locked",
    }
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            semesters: [
              {
                year_level: 1,
                semester: "1st",
                semester_label: "1st Semester",
                entries: [takenEntry, failedEntry, untakenEntry],
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    const failedRow = (await screen.findByText("5.00")).closest("tr")!
    const passedRow = screen.getByText("1.50").closest("tr")!
    const notTakenRow = screen.getByText("CS102").closest("tr")!

    expect(failedRow.className).not.toBe(passedRow.className)
    expect(notTakenRow.className).not.toBe(passedRow.className)
  })

  it("requests another student's prospectus when studentId is provided", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(prospectusFixture())),
    )

    renderWithSession(<ProspectusDocument studentId={4} />)

    await screen.findByText(/BS Information Technology/)
    expect(url(fetchMock.mock.calls[0][0])).toContain("student_id=4")
  })

  it("shows unplaced entries in their own table when present", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({ unplaced_entries: [unplacedEntry] }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    const table = await screen.findByText("Additional / credited subjects")
    const additionalSubjectsTable = table.closest("table")
    if (!additionalSubjectsTable)
      throw new Error("Additional subjects caption is not in a table.")
    expect(
      within(additionalSubjectsTable).getByText("ELEC1"),
    ).toBeInTheDocument()
  })

  it("shows read-only credited old-to-new curriculum subjects", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            curriculum_transition: {
              source_curriculum_name: "BSIT 2021 Curriculum",
              target_curriculum_name: "BSIT 2026 Curriculum",
              migrated_at: "2026-08-16T00:00:00Z",
              credits: [
                {
                  source_code: "IT-OLD",
                  source_title: "Old Programming",
                  target_code: "IT-NEW",
                  target_title: "Foundations of Programming",
                },
              ],
            },
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    expect(await screen.findByText(/Curriculum transition/)).toBeInTheDocument()
    expect(screen.getByText(/IT-OLD — Old Programming/)).toBeInTheDocument()
    expect(
      screen.getByText(/IT-NEW — Foundations of Programming/),
    ).toBeInTheDocument()
    expect(screen.getByText("Credited")).toBeInTheDocument()
  })

  it("lists subjects credited from a previous school, read-only, with what they count as", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            transferee_credits: [
              {
                source_institution:
                  "Technological Institute of the Philippines",
                source_subject_code: "CS 101",
                source_subject_title: "Intro to Computing",
                source_grade: "1.50",
                credited_units: 3,
                source_school_year: "2023-2024",
                source_semester: "1st",
                target_code: "IT-NEW",
                target_title: "Foundations of Programming",
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    expect(
      await screen.findByText("Credited from a previous school"),
    ).toBeInTheDocument()
    expect(screen.getByText(/CS 101 — Intro to Computing/)).toBeInTheDocument()
    expect(
      screen.getByText(
        /Technological Institute of the Philippines · 2023-2024 \(1st\)/,
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/IT-NEW — Foundations of Programming/),
    ).toBeInTheDocument()
    expect(screen.getByText("Credited")).toBeInTheDocument()
  })

  it("shows the grade each previous school gave, for information only, and says it is not converted", async () => {
    const credit = {
      source_institution: "Technological Institute of the Philippines",
      source_subject_code: "CS 101",
      source_subject_title: "Intro to Computing",
      source_grade: "1.50",
      credited_units: 3,
      source_school_year: "2023-2024",
      source_semester: "1st",
      target_code: "IT-NEW",
      target_title: "Foundations of Programming",
    }
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            transferee_credits: [
              credit,
              {
                ...credit,
                source_subject_code: "MAT 101",
                source_subject_title: "College Algebra",
                source_grade: null,
                target_code: "MATH101",
                target_title: "College Algebra",
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    await screen.findByText("Credited from a previous school")
    expect(screen.getAllByText(/Grade from previous school:/)).toHaveLength(1)
    expect(
      screen.getByText("Grade from previous school: 1.50"),
    ).toBeInTheDocument()
    expect(screen.getByText(/not converted/i)).toBeInTheDocument()
  })

  it("marks a credit the Program Chair passed on as awaiting the Registrar Head, and says what that means", async () => {
    const credit = {
      source_institution: "Technological Institute of the Philippines",
      source_subject_code: "CS 101",
      source_subject_title: "Intro to Computing",
      source_grade: "1.50",
      credited_units: 3,
      source_school_year: "2023-2024",
      source_semester: "1st",
      target_code: "IT-NEW",
      target_title: "Foundations of Programming",
    }
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            transferee_credits: [
              {
                ...credit,
                status: "endorsed",
                status_label: "Awaiting Registrar Head approval",
                awaiting_registrar_head_approval: true,
              },
              {
                ...credit,
                source_subject_code: "MAT 101",
                source_subject_title: "College Algebra",
                target_code: "MATH101",
                target_title: "College Algebra",
                status: "approved",
                status_label: "Credited",
                awaiting_registrar_head_approval: false,
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    await screen.findByText("Credited from a previous school")
    expect(
      screen.getByText("Awaiting Registrar Head approval"),
    ).toBeInTheDocument()
    expect(screen.getByText("Credited")).toBeInTheDocument()
    expect(
      screen.getByText(/become final once the Registrar Head approves them/),
    ).toBeInTheDocument()
  })

  it("does not talk about the Registrar Head's approval when every credit is already approved", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            transferee_credits: [
              {
                source_institution: "Other University",
                source_subject_code: "CS 101",
                source_subject_title: "Intro to Computing",
                source_grade: "1.50",
                credited_units: 3,
                source_school_year: null,
                source_semester: null,
                target_code: "IT-NEW",
                target_title: "Foundations of Programming",
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    await screen.findByText("Credited from a previous school")
    expect(
      screen.queryByText(/become final once the Registrar Head approves them/),
    ).not.toBeInTheDocument()
  })

  it("records a credited subject's previous-school grade on its own curriculum row, with whether it is final", async () => {
    const credited = {
      ...untakenEntry,
      subject_id: 31,
      code: "IT-APPROVED",
      title: "Approved Credit",
      credit: {
        source_institution: "Other University",
        source_subject_title: "Intro",
        source_grade: "1.75",
        status: "approved",
        status_label: "Credited",
        awaiting_registrar_head_approval: false,
      },
    }
    const awaiting = {
      ...untakenEntry,
      subject_id: 32,
      code: "IT-AWAITING",
      title: "Awaiting Credit",
      credit: {
        source_institution: "Other University",
        source_subject_title: "Basics",
        source_grade: "2.00",
        status: "endorsed",
        status_label: "Awaiting Registrar Head approval",
        awaiting_registrar_head_approval: true,
      },
    }
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify(
          prospectusFixture({
            semesters: [
              {
                year_level: 1,
                semester: "1st",
                semester_label: "1st Semester",
                entries: [credited, awaiting, untakenEntry],
              },
            ],
          }),
        ),
      ),
    )

    renderWithSession(<ProspectusDocument />)

    const approvedRow = (await screen.findByText("IT-APPROVED")).closest("tr")
    const awaitingRow = screen.getByText("IT-AWAITING").closest("tr")
    expect(approvedRow).not.toBeNull()
    expect(awaitingRow).not.toBeNull()
    expect(
      within(approvedRow as HTMLElement).getByText("1.75"),
    ).toBeInTheDocument()
    expect(
      within(approvedRow as HTMLElement).getByText("Credited"),
    ).toBeInTheDocument()
    expect(
      within(awaitingRow as HTMLElement).getByText("2.00"),
    ).toBeInTheDocument()
    expect(
      within(awaitingRow as HTMLElement).getByText(
        "Awaiting Registrar Head approval",
      ),
    ).toBeInTheDocument()
    // A subject with no credit still reads as not taken.
    expect(screen.getByText("Not taken")).toBeInTheDocument()
  })

  it("shows no previous-school table when nothing was credited from one", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(prospectusFixture())),
    )

    renderWithSession(<ProspectusDocument />)

    await screen.findByText(/BSIT 2023 Curriculum/)
    expect(
      screen.queryByText("Credited from a previous school"),
    ).not.toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify(prospectusFixture())),
    )

    const { container } = renderWithSession(<ProspectusDocument />)

    await screen.findByText(/BS Information Technology/)
    expect(await axe(container)).toHaveNoViolations()
  })

  describe("year accordion and phone disclosure (stakeholder Doc 13, Doc 20)", () => {
    const multiYear = () =>
      prospectusFixture({
        semesters: [
          {
            year_level: 1,
            semester: "1st",
            semester_label: "1st Semester",
            entries: [takenEntry],
          },
          {
            year_level: 2,
            semester: "1st",
            semester_label: "1st Semester",
            entries: [untakenEntry],
          },
          {
            year_level: 3,
            semester: "1st",
            semester_label: "1st Semester",
            entries: [{ ...untakenEntry, subject_id: 9, code: "CS301" }],
          },
        ],
      })

    function stubViewport(isPhone: boolean, reduceMotion = true) {
      vi.spyOn(window, "matchMedia").mockImplementation(
        (query: string) =>
          ({
            matches: query.includes("max-width: 47.99rem")
              ? isPhone
              : query.includes("prefers-reduced-motion")
                ? reduceMotion
                : false,
            media: query,
            onchange: null,
            addListener: () => undefined,
            removeListener: () => undefined,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            dispatchEvent: () => false,
          }) as MediaQueryList,
      )
    }

    afterEach(() => vi.restoreAllMocks())

    const yearButtons = (container: HTMLElement) => [
      ...container.querySelectorAll<HTMLButtonElement>(
        "button[aria-expanded][aria-controls]",
      ),
    ]

    it("starts with every year collapsed, then opens the year the student is in", async () => {
      stubViewport(false, false)
      const fixture = multiYear()
      fixture.data.year_level = 2
      fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(fixture)))

      const { container } = renderWithSession(<ProspectusDocument />)
      await screen.findByText(/BS Information Technology/)

      const buttons = yearButtons(container)
      expect(buttons).toHaveLength(3)
      expect(buttons[0]).toHaveTextContent("1st Year")
      // The overview comes first: nothing is open yet.
      expect(buttons.map((b) => b.getAttribute("aria-expanded"))).toEqual([
        "false",
        "false",
        "false",
      ])

      await waitFor(() =>
        expect(buttons.map((b) => b.getAttribute("aria-expanded"))).toEqual([
          "false",
          "true",
          "false",
        ]),
      )
    })

    it("opens the student's year at once when the user prefers reduced motion", async () => {
      stubViewport(true, true)
      const fixture = multiYear()
      fixture.data.year_level = 3
      fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(fixture)))

      const { container } = renderWithSession(<ProspectusDocument />)
      await screen.findByText(/BS Information Technology/)

      await waitFor(() =>
        expect(
          yearButtons(container).map((b) => b.getAttribute("aria-expanded")),
        ).toEqual(["false", "false", "true"]),
      )
    })

    it("falls back to the first year with unfinished subjects when the student's year is not in the curriculum", async () => {
      stubViewport(false, true)
      const fixture = multiYear()
      fixture.data.year_level = 4
      fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(fixture)))

      const { container } = renderWithSession(<ProspectusDocument />)
      await screen.findByText(/BS Information Technology/)

      // Year 1 is finished, year 2 is the first with something left.
      await waitFor(() =>
        expect(
          yearButtons(container).map((b) => b.getAttribute("aria-expanded")),
        ).toEqual(["false", "true", "false"]),
      )
    })

    it("lets the user open and close any year, and keeps collapsed years out of reach", async () => {
      stubViewport(false, true)
      const user = userEvent.setup()
      const fixture = multiYear()
      fixture.data.year_level = 2
      fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(fixture)))

      const { container } = renderWithSession(<ProspectusDocument />)
      await screen.findByText(/BS Information Technology/)
      const buttons = yearButtons(container)
      await waitFor(() =>
        expect(buttons[1]).toHaveAttribute("aria-expanded", "true"),
      )
      const panel = (index: number) =>
        document.getElementById(buttons[index].getAttribute("aria-controls")!)!

      expect(panel(0)).toHaveAttribute("inert")
      expect(panel(1)).not.toHaveAttribute("inert")

      await user.click(buttons[0])
      expect(buttons[0]).toHaveAttribute("aria-expanded", "true")
      expect(panel(0)).not.toHaveAttribute("inert")

      await user.click(buttons[1])
      expect(buttons[1]).toHaveAttribute("aria-expanded", "false")
      expect(panel(1)).toHaveAttribute("inert")
    })

    it("keeps every year's table in the page so printing can include all of them", async () => {
      stubViewport(false, true)
      fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(multiYear())))

      const { container } = renderWithSession(<ProspectusDocument />)
      await screen.findByText(/BS Information Technology/)

      expect(container.querySelectorAll("table")).toHaveLength(3)
      for (const button of yearButtons(container)) {
        const panel = document.getElementById(
          button.getAttribute("aria-controls")!,
        )!
        // Collapsed or not, the panel expands to full height on paper.
        expect(panel.className).toContain("print:grid-rows-[1fr]")
      }
    })

    it("labels each stacked cell so a row still reads without its column heading", async () => {
      fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify(prospectusFixture())),
      )

      renderWithSession(<ProspectusDocument />)

      const row = (await screen.findByText("CS101")).closest("tr")!
      const labelled = [...row.querySelectorAll("td[data-label]")].map((cell) =>
        cell.getAttribute("data-label"),
      )
      expect(labelled).toEqual(["Pre-requisite", "Units", "Grade", "Status"])
      expect(row.closest("table")).toHaveAttribute("data-stack-mobile")
    })

    it("collapses a subject's pre-requisite and status on a phone until tapped", async () => {
      stubViewport(true)
      fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify(prospectusFixture())),
      )
      const user = userEvent.setup()

      renderWithSession(<ProspectusDocument />)

      const takenRow = (await screen.findByText("CS101")).closest("tr")!
      const toggle = within(takenRow).getByRole("button", { name: /CS101/ })
      expect(toggle).toHaveAttribute("aria-expanded", "false")
      // Units and Grade — the "glanceable" fields — show immediately.
      expect(within(takenRow).getByText("3")).toBeInTheDocument()
      expect(within(takenRow).getByText("1.50")).toBeInTheDocument()
      // Pre-requisite and Status stay collapsed until the row is tapped.
      expect(within(takenRow).queryByText("CS100")).not.toBeInTheDocument()
      expect(within(takenRow).queryByText("Locked")).not.toBeInTheDocument()

      await user.click(toggle)

      expect(toggle).toHaveAttribute("aria-expanded", "true")
      expect(within(takenRow).getByText("CS100")).toBeInTheDocument()
      expect(within(takenRow).getByText("Locked")).toBeInTheDocument()
    })

    it("shows every subject field immediately on a wide screen, with no tap needed", async () => {
      stubViewport(false)
      fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify(prospectusFixture())),
      )

      renderWithSession(<ProspectusDocument />)

      const takenRow = (await screen.findByText("CS101")).closest("tr")!
      expect(within(takenRow).queryByRole("button")).not.toBeInTheDocument()
      expect(within(takenRow).getByText("CS100")).toBeInTheDocument()
      expect(within(takenRow).getByText("Locked")).toBeInTheDocument()
    })
  })
})
