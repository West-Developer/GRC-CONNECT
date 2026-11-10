import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"

import { CurriculumCreationWizard } from "@/features/components/portal/curriculum-creation-wizard"

const programs = [
  {
    type: "program" as const,
    id: 1,
    code: "BSCS",
    name: "Computer Science",
    status: "active" as const,
    status_label: "Active",
  },
]
const curricula = [
  {
    type: "curriculum" as const, id: 11, program_id: 1,
    equivalency_source_curriculum_id: null, equivalency_source_curriculum_name: null,
    name: "BSCS 2021 Curriculum", effective_school_year: "2021-2022",
    status: "archived" as const, status_label: "Archived", decided_at: null,
    last_decision_reason: null, subjects: [],
  },
]

describe("CurriculumCreationWizard", () => {
  it("collects the chair's scoped program before the curriculum name and retains it on Back", async () => {
    const user = userEvent.setup()
    render(
      <CurriculumCreationWizard
        programs={programs}
        curricula={curricula}
        college="ccs"
        onProceed={vi.fn().mockResolvedValue(undefined)}
      />,
    )

    await user.click(
      screen.getByRole("button", { name: "Create new curriculum" }),
    )
    expect(screen.getByText("College: CCS")).toBeInTheDocument()
    expect(screen.queryByLabelText("Curriculum name")).not.toBeInTheDocument()

    await user.click(screen.getByLabelText("Program"))
    await user.click(
      await screen.findByRole("option", {
        name: "BSCS — Computer Science",
      }),
    )
    await user.click(screen.getByRole("button", { name: "Next" }))

    expect(screen.getByLabelText("Curriculum name")).toBeInTheDocument()
    await user.click(screen.getByLabelText("Old curriculum source"))
    await user.click(await screen.findByRole("option", { name: "BSCS 2021 Curriculum" }))
    await user.click(screen.getByRole("button", { name: "Back" }))
    expect(screen.getByLabelText("Program")).toHaveTextContent(
      "BSCS — Computer Science",
    )
  })

  it("cancels without a POST and proceeds with only the published create input", async () => {
    const user = userEvent.setup()
    const onProceed = vi.fn().mockResolvedValue(undefined)
    render(
      <CurriculumCreationWizard
        programs={programs}
        curricula={curricula}
        college="ccs"
        onProceed={onProceed}
      />,
    )

    await user.click(
      screen.getByRole("button", { name: "Create new curriculum" }),
    )
    await user.click(screen.getByRole("button", { name: "Cancel" }))
    expect(onProceed).not.toHaveBeenCalled()

    await user.click(
      screen.getByRole("button", { name: "Create new curriculum" }),
    )
    await user.click(screen.getByLabelText("Program"))
    await user.click(
      await screen.findByRole("option", {
        name: "BSCS — Computer Science",
      }),
    )
    await user.click(screen.getByRole("button", { name: "Next" }))
    await user.click(screen.getByLabelText("Old curriculum source"))
    await user.click(await screen.findByRole("option", { name: "BSCS 2021 Curriculum" }))
    await user.type(
      screen.getByLabelText("Curriculum name"),
      "BSCS 2026 Curriculum",
    )
    await user.click(screen.getByRole("button", { name: "Proceed" }))

    expect(onProceed).toHaveBeenCalledWith({
      program_id: 1,
      equivalency_source_curriculum_id: 11,
      name: "BSCS 2026 Curriculum",
      subjects: [],
    })
  })
})
