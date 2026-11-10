import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { CurriculumMigrationPanel } from "@/features/components/portal/curriculum-migration-panel"
import { renderWithSession } from "@/tests/render-app"

const curricula = [
  {
    type: "curriculum" as const,
    id: 9,
    program_id: 1,
    equivalency_source_curriculum_id: 2,
    equivalency_source_curriculum_name: "BSCS 2021",
    name: "BSCS 2026",
    effective_school_year: "2026-2027",
    status: "active" as const,
    status_label: "Active",
    decided_at: null,
    last_decision_reason: null,
    subjects: [],
  },
]

const preview = {
  data: {
    student: { id: 7, student_number: "2021-0001" },
    source_curriculum_id: 2,
    target_curriculum_id: 9,
    credit_candidates: [
      {
        equivalency_id: 41,
        source_subject: { id: 3, code: "CS-OLD", title: "Old Programming" },
        target_subject: { id: 4, code: "CS-NEW", title: "New Programming" },
        source_completion: { academic_grade_id: 11, final_grade: "1.75" },
      },
    ],
  },
}

describe("CurriculumMigrationPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("previews qualifying credits and lets the Chair deselect before migration", async () => {
    fetchMock
      .mockResolvedValueOnce(new Response(JSON.stringify(preview)))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            data: {
              id: 12,
              student_id: 7,
              source_curriculum_id: 2,
              target_curriculum_id: 9,
              credited_subject_ids: [],
              migrated_at: "2026-08-16T00:00:00Z",
            },
          }),
          { status: 201 },
        ),
      )
    const user = userEvent.setup()

    renderWithSession(<CurriculumMigrationPanel curricula={curricula} />)

    await user.type(screen.getByLabelText("Student number"), "2021-0001")
    await user.click(screen.getByRole("button", { name: "Preview credits" }))
    expect(
      await screen.findByRole("checkbox", { name: "Credit CS-NEW" }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("checkbox", { name: "Credit CS-NEW" }))
    await user.click(
      screen.getByRole("button", {
        name: "Confirm selected credits and migrate",
      }),
    )

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    const previewUrl = String(fetchMock.mock.calls[0]?.[0])
    expect(previewUrl).toContain(
      "/api/v1/curricula/9/migration-preview?student_number=2021-0001",
    )
    const request = fetchMock.mock.calls[1]?.[1]
    expect(request?.method).toBe("POST")
    expect(JSON.parse(request?.body as string)).toEqual({
      student_id: 7,
      equivalency_ids: [],
    })
  })
})
