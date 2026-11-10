import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, type Mock } from "vitest"

import {
  CurriculumSubjectRowDialog,
  type CurriculumSubjectRowDialogProps,
} from "@/features/components/portal/curriculum-subject-row-dialog"
import type { Subject } from "@/features/schemas/reference-data-schema"
import { ApiClientError } from "@/features/services/api-client"

const candidates: Subject[] = [
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
]

const readyCandidates = {
  data: candidates,
  isPending: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
}

type SubmitMock = Mock<CurriculumSubjectRowDialogProps["onSubmit"]>
type OpenChangeMock = Mock<CurriculumSubjectRowDialogProps["onOpenChange"]>

function renderDialog(
  options: {
    candidateQuery?: typeof readyCandidates
    equivalencyCandidates?: readonly Subject[]
    onSubmit?: SubmitMock
    onOpenChange?: OpenChangeMock
  } = {},
) {
  const onSubmit =
    options.onSubmit ??
    vi
      .fn<CurriculumSubjectRowDialogProps["onSubmit"]>()
      .mockResolvedValue(undefined)
  const onOpenChange =
    options.onOpenChange ??
    vi.fn<CurriculumSubjectRowDialogProps["onOpenChange"]>()

  render(
    <CurriculumSubjectRowDialog
      open
      onOpenChange={onOpenChange}
      yearLevel={2}
      candidateQuery={options.candidateQuery ?? readyCandidates}
      {...{ equivalencyCandidates: options.equivalencyCandidates ?? [] }}
      isSubmitting={false}
      onSubmit={onSubmit}
    />,
  )

  return { onSubmit, onOpenChange }
}

async function chooseOption(
  user: ReturnType<typeof userEvent.setup>,
  label: string,
  option: string,
) {
  await user.click(screen.getByLabelText(label))
  await user.click(await screen.findByRole("option", { name: option }))
}

describe("CurriculumSubjectRowDialog", () => {
  it("searches current-curriculum candidates, autofills the selected subject, and posts existing", async () => {
    const user = userEvent.setup()
    const { onSubmit, onOpenChange } = renderDialog()

    await user.click(
      screen.getByRole("button", { name: "Use existing subject" }),
    )
    await user.type(screen.getByPlaceholderText("Search subjects"), "data")
    await user.click(
      await screen.findByRole("option", {
        name: "CS102 — Data Structures",
      }),
    )

    expect(screen.getByDisplayValue("CS102")).toBeInTheDocument()
    expect(screen.getByDisplayValue("Data Structures")).toBeInTheDocument()
    expect(screen.getByDisplayValue("3")).toBeInTheDocument()
    await chooseOption(user, "Semester", "2nd Semester")
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    expect(onSubmit).toHaveBeenCalledWith({
      source: "existing",
      subject_id: 12,
      year_level: 2,
      semester: "2nd",
    })
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it("validates and posts a new subject without losing the semester domain value", async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderDialog()

    await user.click(screen.getByRole("button", { name: "Create new subject" }))
    await user.type(screen.getByLabelText("Subject code"), " CS205 ")
    await user.type(screen.getByLabelText("Description"), " Web Systems ")
    await user.type(screen.getByLabelText("Units"), "3")
    await chooseOption(user, "Semester", "1st Semester")
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    expect(onSubmit).toHaveBeenCalledWith({
      source: "new",
      code: "CS205",
      title: "Web Systems",
      units: 3,
      year_level: 2,
      semester: "1st",
    })
  })

  it("lets a newly created target subject select one old-curriculum equivalent", async () => {
    const user = userEvent.setup()
    const { onSubmit } = renderDialog({ equivalencyCandidates: candidates })

    await user.click(screen.getByRole("button", { name: "Create new subject" }))
    await user.type(screen.getByLabelText("Subject code"), "CS205")
    await user.type(screen.getByLabelText("Description"), "Web Systems")
    await user.type(screen.getByLabelText("Units"), "3")
    await user.click(screen.getByLabelText("Equivalent old-curriculum subject"))
    await user.click(
      await screen.findByRole("option", { name: "CS102 — Data Structures" }),
    )
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    expect(onSubmit).toHaveBeenCalledWith({
      source: "new",
      code: "CS205",
      title: "Web Systems",
      units: 3,
      year_level: 2,
      semester: "1st",
      equivalent_source_subject_id: 12,
    })
  })

  it("shows an explicit empty state when the current curriculum has no candidates", async () => {
    const user = userEvent.setup()
    renderDialog({
      candidateQuery: { ...readyCandidates, data: [] },
    })

    await user.click(
      screen.getByRole("button", { name: "Use existing subject" }),
    )

    expect(
      screen.getByText(
        "No subjects are available from the current curriculum.",
      ),
    ).toBeInTheDocument()
  })

  it("keeps new-subject values visible when the API rejects a duplicate code", async () => {
    const user = userEvent.setup()
    const duplicate = new ApiClientError({
      kind: "http",
      status: 422,
      message: "The given data was invalid.",
      fieldErrors: {
        code: ["The subject code is already used in this college."],
      },
    })
    const onSubmit = vi
      .fn<CurriculumSubjectRowDialogProps["onSubmit"]>()
      .mockRejectedValue(duplicate)
    renderDialog({ onSubmit })

    await user.click(screen.getByRole("button", { name: "Create new subject" }))
    await user.type(screen.getByLabelText("Subject code"), "CS205")
    await user.type(screen.getByLabelText("Description"), "Web Systems")
    await user.type(screen.getByLabelText("Units"), "3")
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    expect(
      await screen.findByText(
        "The subject code is already used in this college.",
      ),
    ).toBeInTheDocument()
    expect(screen.getByLabelText("Subject code")).toHaveValue("CS205")
    expect(screen.getByLabelText("Description")).toHaveValue("Web Systems")
    expect(screen.getByLabelText("Units")).toHaveValue(3)
  })
})
