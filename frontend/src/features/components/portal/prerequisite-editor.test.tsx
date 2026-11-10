import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { PrerequisiteEditor } from "@/features/components/portal/prerequisite-editor"
import { renderWithSession } from "@/tests/render-app"

describe("PrerequisiteEditor", () => {
  it("prevents duplicate, self, and cyclic prerequisite edges before save", async () => {
    const user = userEvent.setup()
    const onChange = (value: unknown) => value
    renderWithSession(
      <PrerequisiteEditor
        subjects={[
          {
            subject_id: 1,
            year_level: 1,
            semester: "1st",
            is_required: true,
            prerequisites: [
              { prerequisite_subject_id: 2, minimum_grade: "75" },
            ],
          },
          {
            subject_id: 2,
            year_level: 1,
            semester: "2nd",
            is_required: true,
            prerequisites: [],
          },
        ]}
        subjectCatalog={[
          {
            type: "subject",
            id: 1,
            code: "CS101",
            title: "Programming 1",
            units: 3,
            status: "active",
            status_label: "Active",
            is_completion_only: false,
          },
          {
            type: "subject",
            id: 2,
            code: "CS102",
            title: "Programming 2",
            units: 3,
            status: "active",
            status_label: "Active",
            is_completion_only: false,
          },
        ]}
        onChange={onChange}
      />,
      {
        session: {
          userId: "4",
          displayName: "Chair",
          role: "program_chair",
          signedInAt: "2026-07-29T12:00:00Z",
        },
      },
    )
    await user.click(screen.getByLabelText("Subject for prerequisite"))
    await user.click(await screen.findByRole("option", { name: "CS102" }))
    await user.click(screen.getByLabelText("Prerequisite subject"))
    await user.click(
      await screen.findByRole("option", { name: "CS101 — Programming 1" }),
    )
    await user.click(screen.getByRole("button", { name: "Add prerequisite" }))
    expect(screen.getByRole("alert")).toHaveTextContent(
      "would create a prerequisite cycle",
    )
  })

  it("uses the fixed passing threshold without showing a minimum-grade control", async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    renderWithSession(
      <PrerequisiteEditor
        subjects={[
          {
            subject_id: 1,
            year_level: 1,
            semester: "1st",
            is_required: true,
            prerequisites: [],
          },
          {
            subject_id: 2,
            year_level: 1,
            semester: "2nd",
            is_required: true,
            prerequisites: [],
          },
        ]}
        subjectCatalog={[
          {
            type: "subject",
            id: 1,
            code: "CS101",
            title: "Programming 1",
            units: 3,
            status: "active",
            status_label: "Active",
            is_completion_only: false,
          },
          {
            type: "subject",
            id: 2,
            code: "CS102",
            title: "Programming 2",
            units: 3,
            status: "active",
            status_label: "Active",
            is_completion_only: false,
          },
        ]}
        onChange={onChange}
      />,
      {
        session: {
          userId: "4",
          displayName: "Chair",
          role: "program_chair",
          signedInAt: "2026-07-29T12:00:00Z",
        },
      },
    )

    expect(screen.queryByLabelText("Minimum grade")).not.toBeInTheDocument()
    await user.click(screen.getByLabelText("Subject for prerequisite"))
    await user.click(await screen.findByRole("option", { name: "CS102" }))
    await user.click(screen.getByLabelText("Prerequisite subject"))
    await user.click(
      await screen.findByRole("option", { name: "CS101 — Programming 1" }),
    )
    await user.click(screen.getByRole("button", { name: "Add prerequisite" }))

    expect(onChange).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({
          subject_id: 2,
          prerequisites: [{ prerequisite_subject_id: 1, minimum_grade: "75" }],
        }),
      ]),
    )
  })
})
