import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { EnrollmentBlockDetailDialog } from "@/features/components/portal/enrollment-block-detail-dialog"
import type { EnrollmentBlock } from "@/features/schemas/enrollment-block-schema"

const selectableBlock: EnrollmentBlock = {
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
  preference_score: 40,
  preference_reasons: ["Matches your preferred time block"],
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
  ],
}

const blockedBlock: EnrollmentBlock = {
  ...selectableBlock,
  block_code: "IT304",
  is_selectable: false,
  reasons: [{ code: "block_full", message: "This section is already full." }],
}

describe("EnrollmentBlockDetailDialog", () => {
  it("renders nothing when no block is being viewed", () => {
    render(
      <EnrollmentBlockDetailDialog
        block={null}
        onOpenChange={vi.fn()}
        onChoose={vi.fn()}
      />,
    )

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
  })

  it("is labelled by the section code and shows the full weekly schedule defaulting to table view", async () => {
    const user = userEvent.setup()
    render(
      <EnrollmentBlockDetailDialog
        block={selectableBlock}
        onOpenChange={vi.fn()}
        onChoose={vi.fn()}
      />,
    )

    const dialog = await screen.findByRole("dialog", { name: /IT301/ })
    const table = within(dialog).getByRole("table", {
      name: /weekly schedule/i,
    })
    expect(
      within(table).getByText("CS201 — Data Structures"),
    ).toBeInTheDocument()
    expect(within(table).getByText("LAB-1")).toBeInTheDocument()

    // Switch to Calendar view
    await user.click(within(dialog).getByRole("radio", { name: "Calendar view" }))
    expect(within(dialog).getAllByText("CS201").length).toBeGreaterThan(0)
    expect(within(dialog).getAllByText("LAB-1").length).toBeGreaterThan(0)
  })

  it("stages a choice without submitting anything", async () => {
    const user = userEvent.setup()
    const onChoose = vi.fn()
    render(
      <EnrollmentBlockDetailDialog
        block={selectableBlock}
        onOpenChange={vi.fn()}
        onChoose={onChoose}
      />,
    )

    await user.click(
      screen.getByRole("button", { name: "Choose this section" }),
    )

    expect(onChoose).toHaveBeenCalledWith("IT301")
  })

  it("closes without choosing on Cancel", async () => {
    const user = userEvent.setup()
    const onOpenChange = vi.fn()
    render(
      <EnrollmentBlockDetailDialog
        block={selectableBlock}
        onOpenChange={onOpenChange}
        onChoose={vi.fn()}
      />,
    )

    await user.click(screen.getByRole("button", { name: "Cancel" }))

    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it("shows blocking reasons and disables Choose this section when the block is not selectable", async () => {
    render(
      <EnrollmentBlockDetailDialog
        block={blockedBlock}
        onOpenChange={vi.fn()}
        onChoose={vi.fn()}
      />,
    )

    expect(
      await screen.findByText("This section is already full."),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Choose this section" }),
    ).toBeDisabled()
  })

  it("disables Choose this section when the enrollment window is closed, even for an otherwise selectable block", () => {
    render(
      <EnrollmentBlockDetailDialog
        block={selectableBlock}
        onOpenChange={vi.fn()}
        onChoose={vi.fn()}
        disabled
      />,
    )

    expect(
      screen.getByRole("button", { name: "Choose this section" }),
    ).toBeDisabled()
  })

  it("has no detectable accessibility violations while open", async () => {
    const { container } = render(
      <EnrollmentBlockDetailDialog
        block={selectableBlock}
        onOpenChange={vi.fn()}
        onChoose={vi.fn()}
      />,
    )

    await screen.findByRole("dialog", { name: /IT301/ })
    expect(await axe(container)).toHaveNoViolations()
  })
})
