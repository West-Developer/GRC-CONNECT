import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentSectionTable } from "@/features/components/portal/enrollment-section-table"
import type { EnrollmentBlock } from "@/features/schemas/enrollment-block-schema"

vi.setConfig({ testTimeout: 30_000 })

function block(overrides: Partial<EnrollmentBlock>): EnrollmentBlock {
  return {
    type: "enrollment_block",
    block_code: "IT301",
    year_level: 2,
    curriculum_id: 9,
    section_plan_id: 12,
    total_units: 6,
    seats_remaining: 7,
    capacity: 40,
    is_selectable: true,
    reasons: [],
    preference_score: null,
    preference_reasons: [],
    subjects: [
      {
        section_id: 5,
        subject_id: 7,
        code: "CS201",
        title: "Data Structures",
        units: 3,
        schedule_days: "MWF",
        starts_at_time: "08:00:00",
        ends_at_time: "09:00:00",
        room: "LAB-1",
        modality: "f2f",
        professor_name: "Dr. Cruz",
        capacity: 40,
        enrolled_count: 33,
        remaining_seats: 7,
      },
      {
        section_id: 6,
        subject_id: 8,
        code: "GE201",
        title: "Ethics",
        units: 3,
        schedule_days: "TTh",
        starts_at_time: "10:00:00",
        ends_at_time: "11:30:00",
        room: "R201",
        modality: "f2f",
        professor_name: "Dr. Reyes",
        capacity: 40,
        enrolled_count: 30,
        remaining_seats: 10,
      },
    ],
    ...overrides,
  }
}

const blocks: EnrollmentBlock[] = [
  block({
    block_code: "IT301",
    preference_score: 40,
    preference_reasons: ["Matches your preferred time block"],
  }),
  block({
    block_code: "IT302",
    preference_score: null,
    preference_reasons: [],
  }),
  block({
    block_code: "IT303",
    preference_score: 90,
    preference_reasons: ["Matches your preferred days"],
  }),
]

function renderTable({
  list = blocks,
  selectedBlockCode = null,
  onChoose = vi.fn(),
  onChangeSection = vi.fn(),
  disabled = false,
}: {
  list?: EnrollmentBlock[]
  selectedBlockCode?: string | null
  onChoose?: (blockCode: string) => void
  onChangeSection?: () => void
  disabled?: boolean
} = {}) {
  return render(
    <EnrollmentSectionTable
      blocks={list}
      selectedBlockCode={selectedBlockCode}
      onChoose={onChoose}
      onChangeSection={onChangeSection}
      disabled={disabled}
      renderSelectedFooter={() => <span>Selected section actions</span>}
    />,
  )
}

describe("EnrollmentSectionTable", () => {
  it("shows one section as a thumbnail card, then reveals schedule upon selection with table and calendar views", async () => {
    const user = userEvent.setup()
    const { rerender } = renderTable()

    const section = screen.getByRole("article", { name: "IT301 section" })
    // Only the first section with seats is offered; the others stay hidden
    // until it fills up.
    expect(
      screen.queryByRole("article", { name: "IT302 section" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT303 section" }),
    ).not.toBeInTheDocument()
    // Seats still open (7 of the 40), not the section's capacity.
    expect(within(section).getByText("7 of 40 seats left")).toBeInTheDocument()
    expect(within(section).getByText("6 units")).toBeInTheDocument()
    expect(within(section).getByText("2 subjects")).toBeInTheDocument()
    expect(
      within(section).getByRole("button", { name: "Choose IT301" }),
    ).toBeInTheDocument()

    // Schedule table is NOT rendered yet before a section is selected
    expect(
      screen.queryByRole("table", { name: "IT301 schedule" }),
    ).not.toBeInTheDocument()

    // Once a section is selected, its schedule table and View in calendar option appear
    rerender(
      <EnrollmentSectionTable
        blocks={blocks}
        selectedBlockCode="IT301"
        onChoose={vi.fn()}
        onChangeSection={vi.fn()}
        disabled={false}
        renderSelectedFooter={() => <span>Selected section actions</span>}
      />,
    )

    const selectedSection = screen.getByRole("article", {
      name: "IT301 section",
    })
    expect(
      within(selectedSection).getByRole("table", { name: "IT301 schedule" }),
    ).toBeInTheDocument()
    expect(within(selectedSection).getByText("Subject code")).toBeInTheDocument()
    expect(within(selectedSection).getAllByText("Section ID")).not.toHaveLength(0)
    expect(within(selectedSection).getAllByText("Data Structures")).not.toHaveLength(0)

    // Can switch to Calendar view using "View in calendar"
    await user.click(within(selectedSection).getByRole("radio", { name: "View in calendar" }))
    expect(within(selectedSection).getAllByText("CS201").length).toBeGreaterThan(0)
  })

  it("shows each subject's schedule in 12-hour clock time, not military time", () => {
    renderTable({ selectedBlockCode: "IT301" })

    const section = screen.getByRole("article", { name: "IT301 section" })
    expect(
      within(section).getAllByText("8:00 AM–9:00 AM").length,
    ).toBeGreaterThan(0)
    expect(
      within(section).getAllByText("10:00 AM–11:30 AM").length,
    ).toBeGreaterThan(0)
  })

  it("calls onChoose with the inline section code", async () => {
    const user = userEvent.setup()
    const onChoose = vi.fn()
    renderTable({ onChoose })

    await user.click(screen.getByRole("button", { name: "Choose IT301" }))

    expect(onChoose).toHaveBeenCalledWith("IT301")
  })

  it("keeps a section with no schedule-preference score selectable", () => {
    renderTable({ list: [blocks[1]] })

    expect(screen.getByRole("button", { name: "Choose IT302" })).toBeEnabled()
  })

  it("offers the next section once the first one is full", () => {
    const fullBlock = block({
      block_code: "IT301",
      seats_remaining: 0,
      is_selectable: false,
      reasons: [{ code: "block_full", message: "No open seats." }],
    })
    renderTable({ list: [fullBlock, blocks[1], blocks[2]] })

    expect(
      screen.queryByRole("article", { name: "IT301 section" }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("article", { name: "IT302 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT303 section" }),
    ).not.toBeInTheDocument()
  })

  it("says so when every section is full", () => {
    const fullBlock = (code: string) =>
      block({
        block_code: code,
        seats_remaining: 0,
        is_selectable: false,
        reasons: [{ code: "block_full", message: "No open seats." }],
      })
    renderTable({ list: [fullBlock("IT301"), fullBlock("IT302")] })

    expect(
      screen.getByText(/every section for your year level is full/i),
    ).toBeInTheDocument()
    expect(screen.queryByRole("article")).not.toBeInTheDocument()
  })

  it("keeps the section the student already opened on screen even after it fills up", () => {
    const fullBlock = block({
      block_code: "IT301",
      seats_remaining: 0,
      is_selectable: false,
      reasons: [{ code: "block_full", message: "No open seats." }],
    })
    renderTable({
      list: [fullBlock, blocks[1]],
      selectedBlockCode: "IT301",
    })

    // The open schedule dialog is not pulled out from under the student; the
    // submit step reports that the section filled up.
    expect(
      screen.getByRole("table", { name: "IT301 schedule" }),
    ).toBeInTheDocument()
  })

  it("shows only the selected section and changes back to the full list", async () => {
    const user = userEvent.setup()
    const onChangeSection = vi.fn()
    renderTable({ selectedBlockCode: "IT302", onChangeSection })

    expect(
      screen.getByRole("article", { name: "IT302 section" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("article", { name: "IT301 section" }),
    ).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Change section" }))
    expect(onChangeSection).toHaveBeenCalledOnce()
  })

  it("disables an inline choice when the enrollment window is closed", () => {
    renderTable({ disabled: true })

    expect(screen.getByRole("button", { name: "Choose IT301" })).toBeDisabled()
  })

  it("orders a block's schedule Monday-to-Saturday automatically, regardless of input order", () => {
    const outOfOrder = block({
      block_code: "IT304",
      subjects: [
        {
          section_id: 20,
          subject_id: 20,
          code: "FRISUBJ",
          title: "Friday Subject",
          units: 3,
          schedule_days: "F",
          starts_at_time: "13:00:00",
          ends_at_time: "14:00:00",
          room: "R101",
          modality: "f2f",
          professor_name: "Dr. Cruz",
          capacity: 40,
          enrolled_count: 0,
          remaining_seats: 40,
        },
        {
          section_id: 21,
          subject_id: 21,
          code: "MONSUBJ",
          title: "Monday Subject",
          units: 3,
          schedule_days: "M",
          starts_at_time: "07:30:00",
          ends_at_time: "08:30:00",
          room: "R102",
          modality: "f2f",
          professor_name: "Dr. Reyes",
          capacity: 40,
          enrolled_count: 0,
          remaining_seats: 40,
        },
      ],
    })
    render(
      <EnrollmentSectionTable
        blocks={[outOfOrder]}
        selectedBlockCode="IT304"
        onChoose={vi.fn()}
        onChangeSection={vi.fn()}
        renderSelectedFooter={() => <span>Selected section actions</span>}
      />,
    )

    const text = document.body.textContent ?? ""
    expect(text.indexOf("MONSUBJ")).toBeLessThan(text.indexOf("FRISUBJ"))
  })

  it("keeps a lecture and its laboratory together, lecture first, even when their days are far apart", () => {
    const subject = (
      id: number,
      code: string,
      title: string,
      days: string,
      pair: number | null,
    ): EnrollmentBlock["subjects"][number] => ({
      section_id: id,
      subject_id: id,
      paired_subject_id: pair,
      code,
      title,
      units: 1,
      schedule_days: days,
      starts_at_time: "08:00:00",
      ends_at_time: "09:00:00",
      room: "R101",
      modality: "f2f",
      professor_name: "Dr. Cruz",
      capacity: 40,
      enrolled_count: 0,
      remaining_seats: 40,
    })
    const paired = block({
      block_code: "IT305",
      subjects: [
        // Schedule order alone would be ITP1 (Mon), ETHICS (Tue), ITP1L (Fri).
        subject(31, "ITP1L", "Fundamentals of Programming LAB", "F", 30),
        subject(32, "ETHICS", "Ethics", "T", null),
        subject(30, "ITP1", "Fundamentals of Programming LEC", "M", 31),
      ],
    })
    render(
      <EnrollmentSectionTable
        blocks={[paired]}
        selectedBlockCode="IT305"
        onChoose={vi.fn()}
        onChangeSection={vi.fn()}
        renderSelectedFooter={() => <span>Selected section actions</span>}
      />,
    )

    const text = document.body.textContent ?? ""
    const lecture = text.indexOf("Fundamentals of Programming LEC")
    const lab = text.indexOf("Fundamentals of Programming LAB")
    const ethics = text.indexOf("Ethics")
    expect(lecture).toBeGreaterThanOrEqual(0)
    expect(lecture).toBeLessThan(lab)
    expect(ethics).toBeGreaterThan(lab)
  })

  it("has no detectable accessibility violations", async () => {
    const { container } = renderTable()

    expect(await axe(container)).toHaveNoViolations()
  })
})
