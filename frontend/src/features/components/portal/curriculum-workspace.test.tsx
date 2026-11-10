import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { CurriculumWorkspace } from "@/features/components/portal/curriculum-workspace"
import { curriculumReplacementSchema } from "@/features/schemas/curriculum-schema"
import { renderWithSession } from "@/tests/render-app"

const programs = {
  data: [
    {
      type: "program",
      id: 1,
      code: "BSCS",
      name: "Computer Science",
      status: "active",
      status_label: "Active",
    },
  ],
} as const
const subjects = {
  data: [
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
      title: "Programming 2",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
    {
      type: "subject",
      id: 13,
      code: "CS201",
      title: "Data Structures",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
    {
      type: "subject",
      id: 14,
      code: "CS301",
      title: "Algorithms",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
    {
      type: "subject",
      id: 15,
      code: "CS401",
      title: "Capstone",
      units: 3,
      status: "active",
      status_label: "Active",
      is_completion_only: false,
    },
  ],
} as const
const curriculum = {
  data: [
    {
      type: "curriculum",
      id: 9,
      program_id: 1,
      name: "BSCS 2026",
      effective_school_year: "2026-2027",
      status: "draft",
      status_label: "Draft",
      decided_at: null,
      last_decision_reason: null,
      subjects: [
        {
          subject_id: 11,
          code: "CS101",
          title: "Programming 1",
          year_level: 1,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
      ],
    },
    {
      type: "curriculum",
      id: 10,
      program_id: 1,
      name: "BSCS 2027",
      effective_school_year: "2027-2028",
      status: "draft",
      status_label: "Draft",
      decided_at: null,
      last_decision_reason: null,
      subjects: [],
    },
    // Every year level 1-4 carries a placement.
    {
      type: "curriculum",
      id: 12,
      program_id: 1,
      name: "BSCS 2029",
      effective_school_year: "2029-2030",
      status: "draft",
      status_label: "Draft",
      decided_at: null,
      last_decision_reason: null,
      subjects: [
        {
          subject_id: 11,
          code: "CS101",
          title: "Programming 1",
          year_level: 1,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
        {
          subject_id: 13,
          code: "CS201",
          title: "Data Structures",
          year_level: 2,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
        {
          subject_id: 14,
          code: "CS301",
          title: "Algorithms",
          year_level: 3,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
        {
          subject_id: 15,
          code: "CS401",
          title: "Capstone",
          year_level: 4,
          semester: "2nd",
          is_required: true,
          prerequisites: [],
        },
      ],
    },
    {
      type: "curriculum",
      id: 13,
      program_id: 1,
      name: "BSCS 2030",
      effective_school_year: "2030-2031",
      status: "pending_dean_review",
      status_label: "Pending Dean Review",
      decided_at: "2026-08-07T00:00:00Z",
      last_decision_reason: null,
      subjects: [
        {
          subject_id: 11,
          code: "CS101",
          title: "Programming 1",
          year_level: 1,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
      ],
    },
  ],
} as const

const draftOnlyCurricula = { data: [curriculum.data[0]] } as const
const coverageDraftOnlyCurricula = { data: [curriculum.data[2]] } as const
const pendingOnlyCurricula = { data: [curriculum.data[3]] } as const

function url(input: RequestInfo | URL) {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}
function renderWorkspace() {
  return renderWithSession(<CurriculumWorkspace />, {
    session: {
      userId: "4",
      displayName: "Chair",
      role: "program_chair",
      college: "ccs",
      signedInAt: "2026-07-29T12:00:00Z",
    },
  })
}

/**
 * The reference-data reads every test needs, with the create/replace response
 * left to the caller — the write path is what the autosave tests assert on.
 */
function mockApi(
  onWrite: (input: RequestInfo | URL, init?: RequestInit) => Response = () =>
    new Response(JSON.stringify({ data: curriculum.data[0] })),
  listedCurricula: unknown = draftOnlyCurricula,
) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    if (url(input).endsWith("/programs"))
      return Promise.resolve(new Response(JSON.stringify(programs)))
    if (url(input).endsWith("/subjects"))
      return Promise.resolve(new Response(JSON.stringify(subjects)))
    if (url(input).includes("/current-curriculum-subjects"))
      return Promise.resolve(new Response(JSON.stringify(subjects)))
    if (url(input).endsWith("/curricula") && init?.method !== "POST")
      return Promise.resolve(new Response(JSON.stringify(listedCurricula)))
    return Promise.resolve(onWrite(input, init))
  }
}

/** Opens a `Select` trigger (waiting for it to become enabled first) and picks an item. */
async function selectOption(
  user: ReturnType<typeof userEvent.setup>,
  labelText: string,
  optionName: string,
) {
  if (labelText === "Curriculum" && !screen.queryByLabelText(labelText)) {
    await user.click(
      await screen.findByRole("button", {
        name: /Open saved curriculum|Change curriculum/,
      }),
    )
  }
  const trigger = screen.getByLabelText(labelText)
  await waitFor(() => expect(trigger).not.toBeDisabled())
  await user.click(trigger)
  await user.click(await screen.findByRole("option", { name: optionName }))
}

async function openDraftForEditing(
  user: ReturnType<typeof userEvent.setup>,
  curriculumName: string,
) {
  await selectOption(user, "Curriculum", curriculumName)
  await user.click(
    await screen.findByRole("button", { name: "Edit curriculum" }),
  )
}

// Autosave debounces at 800ms, so every write assertion needs more headroom
// than Testing Library's 1s default.
const autosaveTimeout = { timeout: 4000 }

describe("CurriculumWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  /** Every valid full-replace payload sent to `path`, oldest first. */
  const replacements = (path: string) =>
    fetchMock.mock.calls
      .filter(
        ([request, init]) =>
          url(request).endsWith(path) && init?.method === "PATCH",
      )
      .flatMap(([, init]) => {
        if (typeof init?.body !== "string") return []
        const parsed = curriculumReplacementSchema.safeParse(
          JSON.parse(init.body) as unknown,
        )
        return parsed.success ? [parsed.data] : []
      })

  it("creates a Draft from the two-step wizard and opens it in a server-owned preview", async () => {
    const user = userEvent.setup()
    const createdDraft = {
      ...curriculum.data[1],
      id: 20,
      name: "BSCS 2026 Curriculum",
      effective_school_year: "2026-2027",
    }
    fetchMock.mockImplementation((input, init) => {
      if (url(input).endsWith("/programs"))
        return Promise.resolve(new Response(JSON.stringify(programs)))
      if (url(input).endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).endsWith("/curricula") && init?.method === "POST")
        return Promise.resolve(
          new Response(JSON.stringify({ data: createdDraft })),
        )
      if (url(input).endsWith("/curricula"))
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      return Promise.resolve(
        new Response(JSON.stringify({ data: createdDraft })),
      )
    })
    renderWorkspace()

    await user.click(
      await screen.findByRole("button", { name: "Create new curriculum" }),
    )
    await user.click(screen.getByLabelText("Program"))
    await user.click(
      await screen.findByRole("option", {
        name: "BSCS — Computer Science",
      }),
    )
    await user.click(screen.getByRole("button", { name: "Next" }))
    await user.type(
      screen.getByLabelText("Curriculum name"),
      "BSCS 2026 Curriculum",
    )
    await user.click(screen.getByRole("button", { name: "Proceed" }))

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/curricula"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({
            program_id: 1,
            name: "BSCS 2026 Curriculum",
            subjects: [],
          }),
        }),
      ),
    )
    expect(await screen.findByText("Draft")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Edit curriculum" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByLabelText("Effective school year"),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByText("Status", { selector: "label" }),
    ).not.toBeInTheDocument()
  })

  it("allows editing only Draft curricula and ends editing after submitting for Dean review", async () => {
    const user = userEvent.setup()
    const submitted = {
      ...curriculum.data[2],
      status: "pending_dean_review" as const,
      status_label: "Pending Dean Review",
    }
    fetchMock.mockImplementation((input, init) => {
      if (url(input).endsWith("/programs"))
        return Promise.resolve(new Response(JSON.stringify(programs)))
      if (url(input).endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).endsWith("/curricula") && init?.method !== "PATCH")
        return Promise.resolve(
          new Response(JSON.stringify({ data: [curriculum.data[2]] })),
        )
      if (url(input).endsWith("/curricula/12/transition"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: submitted })),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: submitted })))
    })
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2029")

    expect(
      await screen.findByRole("button", { name: "Edit curriculum" }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Edit curriculum" }))
    expect(screen.getByLabelText("Curriculum name")).not.toBeDisabled()

    await user.click(
      screen.getByRole("button", { name: "Submit for Dean Review" }),
    )
    await user.click(screen.getByRole("button", { name: "Confirm & Submit" }))
    expect(await screen.findByText("Pending Dean Review")).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Edit curriculum" }),
    ).not.toBeInTheDocument()
    expect(screen.getByLabelText("Curriculum name")).toBeDisabled()

    expect(
      screen.queryByRole("button", { name: "Edit curriculum" }),
    ).not.toBeInTheDocument()
  })

  it("offers only the latest in-progress curriculum from the saved-curriculum picker", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[0] })),
        curriculum,
      ),
    )
    renderWorkspace()

    await user.click(
      await screen.findByRole("button", { name: "Open saved curriculum" }),
    )
    await user.click(screen.getByLabelText("Curriculum"))

    expect(
      await screen.findByRole("option", { name: "BSCS 2030" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: "BSCS 2026" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: "BSCS 2027" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: "BSCS 2029" }),
    ).not.toBeInTheDocument()
  })

  it("shows a separate tab per year level with only code, description, units, semester, and prerequisites", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[2] })),
        coverageDraftOnlyCurricula,
      ),
    )
    renderWorkspace()

    await openDraftForEditing(user, "BSCS 2029")

    await screen.findByRole("tab", { name: /1st year/i })
    expect(screen.getByRole("tab", { name: /2nd year/i })).toBeInTheDocument()
    expect(screen.getByRole("tab", { name: /3rd year/i })).toBeInTheDocument()
    expect(screen.getByRole("tab", { name: /4th year/i })).toBeInTheDocument()

    const table = await screen.findByRole("table")
    const headers = within(table)
      .getAllByRole("columnheader")
      .map((cell) => cell.textContent)
    expect(headers).toEqual([
      "Subject Code",
      "Description",
      "Units",
      "Semester",
      "Prerequisite",
    ])
  })

  it("shows each placement's catalog description and units read-only in its year tab", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[2] })),
        coverageDraftOnlyCurricula,
      ),
    )
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2029")

    const table = await screen.findByRole("table")
    expect(within(table).getByText("CS101")).toBeInTheDocument()
    expect(within(table).getByText("Programming 1")).toBeInTheDocument()
    expect(within(table).getByText("3")).toBeInTheDocument()
    // Year 2's placement belongs to the 2nd Year tab, not this one.
    expect(within(table).queryByText("CS201")).not.toBeInTheDocument()

    await user.click(screen.getByRole("tab", { name: "2nd Year" }))
    expect(
      within(await screen.findByRole("table")).getByText("CS201"),
    ).toBeInTheDocument()
  })

  it("replaces the legacy placement picker with an Add subject row flow", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockApi())
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")

    expect(screen.queryByLabelText("Subject to place")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Add subject placement" }),
    ).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Add subject row" }))
    expect(
      screen.getByRole("dialog", { name: "Add subject row" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Use existing subject" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Create new subject" }),
    ).toBeInTheDocument()
  })

  it("autosaves an edited placement cell without a save button click", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(mockApi())
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await selectOption(user, "Semester for CS101", "2nd Semester")

    await waitFor(
      () =>
        expect(
          replacements("/curricula/9").some((body) =>
            body.subjects.some(
              (subject) =>
                subject.subject_id === 11 && subject.semester === "2nd",
            ),
          ),
        ).toBe(true),
      autosaveTimeout,
    )
    expect(
      screen.queryByRole("button", { name: "Save" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Save curriculum" }),
    ).not.toBeInTheDocument()
  })

  it("posts an existing current-curriculum subject and exposes the returned row", async () => {
    const user = userEvent.setup()
    const updated = {
      ...curriculum.data[0],
      subjects: [
        ...curriculum.data[0].subjects,
        {
          subject_id: 12,
          code: "CS102",
          title: "Programming 2",
          units: 3,
          year_level: 4,
          semester: "2nd",
          is_required: true,
          prerequisites: [],
        },
      ],
    }
    fetchMock.mockImplementation(
      mockApi((input) =>
        url(input).endsWith("/curricula/9/subject-placements")
          ? new Response(JSON.stringify({ data: updated }), { status: 201 })
          : new Response(JSON.stringify({ data: curriculum.data[0] })),
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await user.click(screen.getByRole("tab", { name: "4th Year" }))
    await user.click(screen.getByRole("button", { name: "Add subject row" }))
    await user.click(
      screen.getByRole("button", { name: "Use existing subject" }),
    )
    await user.type(screen.getByPlaceholderText("Search subjects"), "CS102")
    await user.click(
      await screen.findByRole("option", { name: "CS102 — Programming 2" }),
    )
    await selectOption(user, "Semester", "2nd Semester")
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    await waitFor(
      () =>
        expect(fetchMock).toHaveBeenCalledWith(
          expect.stringContaining("/curricula/9/subject-placements"),
          expect.objectContaining({
            method: "POST",
            body: JSON.stringify({
              source: "existing",
              subject_id: 12,
              year_level: 4,
              semester: "2nd",
            }),
          }),
        ),
      autosaveTimeout,
    )
    expect(
      within(
        await screen.findByRole("table", { name: "4th Year subjects" }),
      ).getByText("CS102"),
    ).toBeInTheDocument()
  })

  it("posts a new subject and exposes its returned curriculum row", async () => {
    const user = userEvent.setup()
    const updated = {
      ...curriculum.data[0],
      subjects: [
        ...curriculum.data[0].subjects,
        {
          subject_id: 16,
          code: "CS205",
          title: "Web Systems",
          units: 3,
          year_level: 2,
          semester: "1st",
          is_required: true,
          prerequisites: [],
        },
      ],
    }
    fetchMock.mockImplementation(
      mockApi((input) =>
        url(input).endsWith("/curricula/9/subject-placements")
          ? new Response(JSON.stringify({ data: updated }), { status: 201 })
          : new Response(JSON.stringify({ data: curriculum.data[0] })),
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await user.click(screen.getByRole("tab", { name: "2nd Year" }))
    await user.click(screen.getByRole("button", { name: "Add subject row" }))
    await user.click(screen.getByRole("button", { name: "Create new subject" }))
    await user.type(screen.getByLabelText("Subject code"), "CS205")
    await user.type(screen.getByLabelText("Description"), "Web Systems")
    await user.type(screen.getByLabelText("Units"), "3")
    await user.click(screen.getByRole("button", { name: "Add subject" }))

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/curricula/9/subject-placements"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({
            source: "new",
            code: "CS205",
            title: "Web Systems",
            units: 3,
            year_level: 2,
            semester: "1st",
          }),
        }),
      ),
    )
    const table = await screen.findByRole("table", {
      name: "2nd Year subjects",
    })
    expect(within(table).getByText("CS205")).toBeInTheDocument()
    expect(within(table).getByText("Web Systems")).toBeInTheDocument()
  })

  /**
   * Reference-data reads as usual, but the first write hangs until the
   * returned `release` is called — the only way to hold a save in flight while
   * the chair keeps working.
   */
  function mockDeferredWrite(written: unknown) {
    const gate: { release: (() => void) | null } = { release: null }
    let writes = 0
    fetchMock.mockImplementation((input, init) => {
      if (url(input).endsWith("/programs"))
        return Promise.resolve(new Response(JSON.stringify(programs)))
      if (url(input).endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).includes("/current-curriculum-subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).endsWith("/curricula") && init?.method !== "POST")
        return Promise.resolve(new Response(JSON.stringify(draftOnlyCurricula)))
      writes += 1
      if (writes > 1)
        return Promise.resolve(new Response(JSON.stringify({ data: written })))
      return new Promise<Response>((resolve) => {
        gate.release = () =>
          resolve(new Response(JSON.stringify({ data: written })))
      })
    })
    return gate
  }

  it("keeps an edit made while an earlier save is still in flight", async () => {
    const user = userEvent.setup()
    const gate = mockDeferredWrite(curriculum.data[0])
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await selectOption(user, "Semester for CS101", "2nd Semester")
    await waitFor(() => expect(gate.release).not.toBeNull(), autosaveTimeout)

    // A second edit made while the first write is open must remain queued.
    await selectOption(user, "Semester for CS101", "1st Semester")
    gate.release?.()

    await waitFor(
      () =>
        expect(
          replacements("/curricula/9").some((body) =>
            body.subjects.some(
              (subject) =>
                subject.subject_id === 11 && subject.semester === "1st",
            ),
          ),
        ).toBe(true),
      autosaveTimeout,
    )
  }, 20000)

  it("keeps the current curriculum selected when an earlier save resolves", async () => {
    const user = userEvent.setup()
    const gate = mockDeferredWrite(curriculum.data[0])
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await selectOption(user, "Semester for CS101", "2nd Semester")
    await waitFor(() => expect(gate.release).not.toBeNull(), autosaveTimeout)

    gate.release?.()

    await waitFor(() =>
      expect(screen.getByLabelText("Curriculum name")).toHaveValue("BSCS 2026"),
    )
    expect(
      screen.getByRole("heading", { name: "BSCS 2026" }),
    ).toBeInTheDocument()
  }, 20000)

  it("adds a prerequisite from the placement row and autosaves the edge", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[2] })),
        coverageDraftOnlyCurricula,
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2029")
    await user.click(screen.getByRole("tab", { name: "2nd Year" }))
    await user.click(
      screen.getByRole("button", { name: "Add prerequisite for CS201" }),
    )
    await user.click(
      screen.getByRole("button", { name: "CS101 — Programming 1" }),
    )

    expect(
      await within(
        await screen.findByRole("table", { name: "2nd Year subjects" }),
      ).findByText("CS101"),
    ).toBeInTheDocument()
    await waitFor(
      () =>
        expect(
          replacements("/curricula/12").some((body) =>
            body.subjects.some(
              (subject) =>
                subject.subject_id === 13 &&
                subject.prerequisites.some(
                  (edge) => edge.prerequisite_subject_id === 11,
                ),
            ),
          ),
        ).toBe(true),
      autosaveTimeout,
    )
  })

  it("locks every editing control once the curriculum is not a draft", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[3] })),
        pendingOnlyCurricula,
      ),
    )
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2030")

    expect(await screen.findByText("Pending Dean Review")).toBeInTheDocument()
    expect(screen.getByLabelText("Curriculum name")).toBeDisabled()
    expect(
      screen.queryByRole("button", { name: "Submit for Dean Review" }),
    ).not.toBeInTheDocument()
  })

  it("shows the return notes and lets the Program Chair edit a returned Draft", async () => {
    const user = userEvent.setup()
    const returnedDraft = {
      ...curriculum.data[0],
      last_decision_reason: "Please add the missing second-semester subjects.",
    }
    fetchMock.mockImplementation(
      mockApi(() => new Response(JSON.stringify({ data: returnedDraft })), {
        data: [returnedDraft],
      }),
    )
    renderWorkspace()

    await selectOption(user, "Curriculum", "BSCS 2026")

    expect(
      await screen.findByText(
        "Returned: Please add the missing second-semester subjects.",
      ),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Edit curriculum" }))
    expect(screen.getByLabelText("Curriculum name")).not.toBeDisabled()
  })

  it("opens a review dialog and submits a draft with at least one subject", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi((input) =>
        url(input).endsWith("/transition")
          ? new Response(
              JSON.stringify({
                data: {
                  ...curriculum.data[0],
                  status: "pending_dean_review",
                  status_label: "Pending Dean Review",
                },
              }),
            )
          : new Response(JSON.stringify({ data: curriculum.data[0] })),
      ),
    )
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2026")
    await user.click(
      screen.getByRole("button", { name: "Submit for Dean Review" }),
    )

    expect(screen.getByText("Review before submitting")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Confirm & Submit" }))

    expect(await screen.findByText("Pending Dean Review")).toBeInTheDocument()
  })

  it("hides the Submit button for a draft with no subjects placed", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () => new Response(JSON.stringify({ data: curriculum.data[1] })),
        { data: [curriculum.data[1]] },
      ),
    )
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2027")

    expect(
      screen.queryByRole("button", { name: "Submit for Dean Review" }),
    ).not.toBeInTheDocument()
  })

  it("keeps unsaved work until the chair confirms discard", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/programs")
              ? programs
              : url(input).endsWith("/subjects")
                ? subjects
                : url(input).includes("/current-curriculum-subjects")
                  ? subjects
                  : draftOnlyCurricula,
          ),
        ),
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await user.clear(screen.getByLabelText("Curriculum name"))
    await user.type(screen.getByLabelText("Curriculum name"), "Changed")
    await selectOption(user, "Curriculum", "Select a curriculum")
    expect(screen.getByRole("alertdialog")).toHaveTextContent(
      "Discard unsaved curriculum changes",
    )
    await user.click(screen.getByRole("button", { name: "Keep editing" }))
    expect(screen.getByLabelText("Curriculum name")).toHaveValue("Changed")
  })

  it("does not offer a subject that is already placed to the row chooser", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/programs")
              ? programs
              : url(input).endsWith("/subjects")
                ? subjects
                : url(input).includes("/current-curriculum-subjects")
                  ? subjects
                  : draftOnlyCurricula,
          ),
        ),
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await user.click(screen.getByRole("button", { name: "Add subject row" }))
    await user.click(
      screen.getByRole("button", { name: "Use existing subject" }),
    )
    await user.type(await screen.findByPlaceholderText("Search subjects"), "CS")

    expect(
      screen.queryByRole("option", { name: "CS101 — Programming 1" }),
    ).not.toBeInTheDocument()
    expect(
      await screen.findByRole("option", { name: "CS102 — Programming 2" }),
    ).toBeInTheDocument()
  })

  it("returns to the creation CTA when the saved-curriculum dialog is cleared", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/programs")
              ? programs
              : url(input).endsWith("/subjects")
                ? subjects
                : draftOnlyCurricula,
          ),
        ),
      ),
    )
    renderWorkspace()
    await selectOption(user, "Curriculum", "BSCS 2026")
    await selectOption(user, "Curriculum", "Select a curriculum")
    expect(document.querySelector("#curriculum-select")).toBeNull()
    expect(
      screen.getByRole("button", { name: "Create new curriculum" }),
    ).toBeInTheDocument()
  })

  it("uses the created curriculum identity on the next autosave", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (url(input).endsWith("/programs"))
        return Promise.resolve(new Response(JSON.stringify(programs)))
      if (url(input).endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).endsWith("/curricula") && init?.method !== "POST")
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      return Promise.resolve(
        new Response(JSON.stringify({ data: curriculum.data[0] }), {
          status: init?.method === "POST" ? 201 : 200,
        }),
      )
    })
    renderWorkspace()
    await user.click(
      await screen.findByRole("button", { name: "Create new curriculum" }),
    )
    await user.click(screen.getByLabelText("Program"))
    await user.click(
      await screen.findByRole("option", { name: "BSCS — Computer Science" }),
    )
    await user.click(screen.getByRole("button", { name: "Next" }))
    await user.type(screen.getByLabelText("Curriculum name"), "New BSCS")
    await user.click(screen.getByRole("button", { name: "Proceed" }))

    await waitFor(
      () =>
        expect(fetchMock).toHaveBeenCalledWith(
          expect.stringContaining("/curricula"),
          expect.objectContaining({ method: "POST" }),
        ),
      autosaveTimeout,
    )
    await user.click(
      await screen.findByRole("button", { name: "Edit curriculum" }),
    )
    await selectOption(user, "Semester for CS101", "2nd Semester")
    await waitFor(
      () =>
        expect(fetchMock).toHaveBeenCalledWith(
          expect.stringContaining("/curricula/9"),
          expect.objectContaining({ method: "PATCH" }),
        ),
      autosaveTimeout,
    )
    // Two sequential debounced writes plus the create/replace round trips run
    // past Vitest's 5s default.
  }, 20000)

  it("places the backend cycle rejection beside the prerequisite graph", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation(
      mockApi(
        () =>
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Invalid graph",
                errors: {
                  subjects: [
                    "The submitted subjects contain a direct or transitive prerequisite cycle.",
                  ],
                },
                request_id: "req-6",
              },
            }),
            { status: 422 },
          ),
      ),
    )
    renderWorkspace()
    await openDraftForEditing(user, "BSCS 2026")
    await selectOption(user, "Semester for CS101", "2nd Semester")
    expect(
      await screen.findAllByText(
        "The submitted subjects contain a direct or transitive prerequisite cycle.",
        undefined,
        autosaveTimeout,
      ),
    ).not.toHaveLength(0)
  })

  it("defaults to the Manage tab and offers a read-only View tab of the program's active curriculum", async () => {
    const user = userEvent.setup()
    const activeCurricula = {
      data: [
        { ...curriculum.data[0], status: "active", status_label: "Active" },
      ],
    }
    fetchMock.mockImplementation((input) => {
      if (url(input).endsWith("/programs"))
        return Promise.resolve(new Response(JSON.stringify(programs)))
      if (url(input).endsWith("/subjects"))
        return Promise.resolve(new Response(JSON.stringify(subjects)))
      if (url(input).endsWith("/curricula"))
        return Promise.resolve(new Response(JSON.stringify(activeCurricula)))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWorkspace()

    // Manage starts directly in the creation flow; selecting an existing
    // curriculum is intentionally reserved for the View tab.
    await screen.findByText("Create a curriculum")
    expect(document.querySelector("#curriculum-select")).toBeNull()
    expect(screen.getByRole("tab", { name: "Manage" })).toHaveAttribute(
      "aria-selected",
      "true",
    )

    await user.click(screen.getByRole("tab", { name: "View" }))

    expect(
      await screen.findByText("1st Year · 1st Semester"),
    ).toBeInTheDocument()
    const table = screen.getByRole("table")
    expect(within(table).getByText("CS101")).toBeInTheDocument()
    expect(within(table).getByText("Programming 1")).toBeInTheDocument()
    const headers = within(table)
      .getAllByRole("columnheader")
      .map((cell) => cell.textContent)
    expect(headers).toEqual(["Code", "Description", "Units", "Prerequisites"])
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation((input) =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            url(input).endsWith("/programs")
              ? programs
              : url(input).endsWith("/subjects")
                ? subjects
                : curriculum,
          ),
        ),
      ),
    )
    const { container } = renderWorkspace()
    await screen.findByText("Create a curriculum")
    expect(await axe(container)).toHaveNoViolations()
  })
})
