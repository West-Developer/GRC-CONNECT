import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import {
  currentCurriculumSubjectsQueryKey,
  useAddCurriculumSubjectPlacementMutation,
  useCurrentCurriculumSubjectsQuery,
} from "@/features/hooks/use-curriculum-authoring"
import { curriculaQueryKey } from "@/features/hooks/use-curricula"
import { subjectsQueryKey } from "@/features/hooks/use-reference-data"
import type { Curriculum } from "@/features/schemas/reference-data-schema"
import {
  addCurriculumSubjectPlacement,
  getCurrentCurriculumSubjects,
} from "@/features/services/curriculum-service"
import { renderWithSession } from "@/tests/render-app"

vi.mock("@/features/services/curriculum-service", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/services/curriculum-service")>()),
  addCurriculumSubjectPlacement: vi.fn(),
  getCurrentCurriculumSubjects: vi.fn(),
}))

const curriculum: Curriculum = {
  type: "curriculum",
  id: 9,
  program_id: 4,
  name: "BSCS 2026",
  effective_school_year: "2026-2027",
  status: "draft",
  status_label: "Draft",
  decided_at: null,
  last_decision_reason: null,
  subjects: [],
}

function CurrentSubjects({ programId }: { programId: number | null }) {
  const query = useCurrentCurriculumSubjectsQuery(programId)
  return <output>{query.data?.map((subject) => subject.code).join(",")}</output>
}

function PlacementMutation() {
  const mutation = useAddCurriculumSubjectPlacementMutation()
  return (
    <button
      type="button"
      onClick={() =>
        mutation.mutate({
          curriculumId: 9,
          input: {
            source: "new",
            code: "CS102",
            title: "Data Structures",
            units: 3,
            year_level: 1,
            semester: "1st",
          },
        })
      }
    >
      Add subject
    </button>
  )
}

function ExistingPlacementMutation() {
  const mutation = useAddCurriculumSubjectPlacementMutation()
  return (
    <button
      type="button"
      onClick={() =>
        mutation.mutate({
          curriculumId: 9,
          input: {
            source: "existing",
            subject_id: 11,
            year_level: 1,
            semester: "1st",
          },
        })
      }
    >
      Add existing subject
    </button>
  )
}

describe("curriculum authoring hooks", () => {
  afterEach(() => vi.clearAllMocks())

  it("keeps an unavailable program scoped as null instead of a sentinel id", () => {
    expect(currentCurriculumSubjectsQueryKey("chair-7", null)).toEqual([
      "current-curriculum-subjects",
      "chair-7",
      null,
    ])
  })

  it("does not create a candidate query under a fabricated program id", () => {
    const { queryClient } = renderWithSession(
      <CurrentSubjects programId={null} />,
      {
        session: {
          userId: "chair-7",
          displayName: "Chair",
          role: "program_chair",
          signedInAt: "2026-08-08T00:00:00Z",
        },
      },
    )

    expect(
      queryClient.getQueryState(
        currentCurriculumSubjectsQueryKey("chair-7", null),
      ),
    ).toBeDefined()
    expect(getCurrentCurriculumSubjects).not.toHaveBeenCalled()
  })

  it("scopes current-subject candidates by authenticated user and program", async () => {
    vi.mocked(getCurrentCurriculumSubjects).mockResolvedValue([
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
    ])
    const { queryClient } = renderWithSession(<CurrentSubjects programId={4} />, {
      session: {
        userId: "chair-7",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-08-08T00:00:00Z",
      },
    })

    expect(currentCurriculumSubjectsQueryKey("chair-7", 4)).toEqual([
      "current-curriculum-subjects",
      "chair-7",
      4,
    ])
    await screen.findByText("CS101")
    expect(getCurrentCurriculumSubjects).toHaveBeenCalledWith(4, expect.anything())
    expect(
      queryClient.getQueryData(
        currentCurriculumSubjectsQueryKey("chair-7", 4),
      ),
    ).toHaveLength(1)
  })

  it("invalidates curriculum and subject caches after adding a new subject", async () => {
    vi.mocked(addCurriculumSubjectPlacement).mockResolvedValue(curriculum)
    const user = userEvent.setup()
    const { queryClient } = renderWithSession(<PlacementMutation />, {
      session: {
        userId: "chair-7",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-08-08T00:00:00Z",
      },
    })
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries")

    await user.click(screen.getByRole("button", { name: "Add subject" }))

    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: curriculaQueryKey("chair-7"),
        exact: true,
      }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: subjectsQueryKey("chair-7"),
      exact: true,
    })
  })

  it("invalidates only the exact curriculum cache after adding an existing subject", async () => {
    vi.mocked(addCurriculumSubjectPlacement).mockResolvedValue(curriculum)
    const user = userEvent.setup()
    const { queryClient } = renderWithSession(<ExistingPlacementMutation />, {
      session: {
        userId: "chair-7",
        displayName: "Chair",
        role: "program_chair",
        signedInAt: "2026-08-08T00:00:00Z",
      },
    })
    const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries")

    await user.click(screen.getByRole("button", { name: "Add existing subject" }))

    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: curriculaQueryKey("chair-7"),
        exact: true,
      }),
    )
    expect(invalidateSpy).not.toHaveBeenCalledWith({
      queryKey: subjectsQueryKey("chair-7"),
      exact: true,
    })
  })
})
