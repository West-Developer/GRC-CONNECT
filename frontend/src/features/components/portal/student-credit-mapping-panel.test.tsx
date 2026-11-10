import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { StudentCreditMappingPanel } from "@/features/components/portal/student-credit-mapping-panel"
import { renderWithSession } from "@/tests/render-app"

const chairSession = {
  userId: "12",
  displayName: "Prof. Chair",
  role: "program_chair" as const,
  signedInAt: "2026-10-04T00:00:00Z",
}

const student = {
  student_id: 30,
  student_name: "Maria Santos",
  student_number: "2026-0002",
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

function credit(overrides: Record<string, unknown>) {
  return {
    type: "transferee_credit",
    id: 7,
    student_id: 30,
    student_number: "2026-0002",
    student_name: "Maria Santos",
    source_institution: "Technological Institute of the Philippines",
    source_subject_code: "CS 101",
    source_subject_title: "Intro to Computing",
    source_grade: "1.50",
    credited_units: 3,
    source_school_year: "2023-2024",
    source_semester: "1st",
    subject_id: null,
    subject_code: null,
    subject_title: null,
    requested_by_student: true,
    status: "pending",
    status_label: "Pending",
    endorsed_at: null,
    processed_at: null,
    created_at: "2026-10-01T00:00:00Z",
    ...overrides,
  }
}

const suggestion = (
  id: number,
  code: string,
  title: string,
  score: number,
) => ({
  type: "credit_subject_suggestion",
  subject_id: id,
  subject_code: code,
  subject_title: title,
  units: 3,
  year_level: 1,
  semester: "1st",
  score,
  reasons: ["Same units"],
})

const curriculumSubjects = [
  [10, "CS101", "Introduction to Computing"],
  [12, "CS105", "Introduction to Computing Systems"],
  [13, "MATH101", "College Algebra"],
  [14, "ENG101", "Purposive Communication"],
].map(([id, code, title]) => ({
  subject_id: id,
  subject_code: code,
  subject_title: title,
  units: 3,
  year_level: 1,
  semester: "1st",
}))

const subjectById = new Map(
  curriculumSubjects.map((subject) => [subject.subject_id, subject]),
)

interface World {
  credits: Record<string, unknown>[]
  workspace: { credit_id: number; suggestions: unknown[] }[]
  patches: { id: number; body: Record<string, unknown> }[]
  submits: number
  submitResponse?: () => Response
}

/** Two pending credits whose best suggestion is the SAME subject (CS101). */
function twoPendingCredits(): World {
  return {
    credits: [
      credit({ id: 7 }),
      credit({
        id: 8,
        source_subject_code: "MAT 101",
        source_subject_title: "College Algebra",
      }),
    ],
    workspace: [
      {
        credit_id: 7,
        suggestions: [
          suggestion(10, "CS101", "Introduction to Computing", 1),
          suggestion(12, "CS105", "Introduction to Computing Systems", 0.5),
        ],
      },
      {
        credit_id: 8,
        suggestions: [
          suggestion(10, "CS101", "Introduction to Computing", 0.6),
          suggestion(13, "MATH101", "College Algebra", 0.55),
        ],
      },
    ],
    patches: [],
    submits: 0,
  }
}

function paginated(entries: unknown[]) {
  return {
    data: entries,
    links: { first: "http://x/1", last: "http://x/1", prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: entries.length,
    },
  }
}

function routes(world: World) {
  return (input: RequestInfo | URL, init?: RequestInit) => {
    const url = requestUrl(input)
    const method = init?.method ?? "GET"

    if (url.includes("/tor-documents")) {
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    }
    if (url.includes("/student-profiles/30/transferee-credits/suggestions")) {
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              student_id: 30,
              credits: world.workspace,
              curriculum_subjects: curriculumSubjects,
            },
          }),
        ),
      )
    }
    if (url.includes("/submit-to-registrar-head") && method === "POST") {
      world.submits += 1
      if (world.submitResponse) return Promise.resolve(world.submitResponse())
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: world.credits.map((row) => ({
              ...row,
              status: "endorsed",
              status_label: "Endorsed",
            })),
          }),
        ),
      )
    }
    const patch = /\/transferee-credits\/(\d+)$/.exec(url)
    if (patch && method === "PATCH") {
      const id = Number(patch[1])
      const body = JSON.parse(init?.body as string) as Record<string, unknown>
      world.patches.push({ id, body })
      const row = world.credits.find((entry) => entry.id === id)!
      if (body.action === "decline") {
        row.status = "rejected"
        row.status_label = "Rejected"
      } else {
        const subject = subjectById.get(body.subject_id as number)!
        row.subject_id = subject.subject_id
        row.subject_code = subject.subject_code
        row.subject_title = subject.subject_title
        row.credited_units = body.credited_units
      }
      return Promise.resolve(new Response(JSON.stringify({ data: row })))
    }
    if (url.includes("/transferee-credits") && method === "GET") {
      return Promise.resolve(
        new Response(JSON.stringify(paginated(world.credits))),
      )
    }
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("StudentCreditMappingPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const onClose = vi.fn()
  const onSent = vi.fn()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    fetchMock.mockReset()
    onClose.mockReset()
    onSent.mockReset()
    vi.unstubAllGlobals()
  })

  function renderPanel(world: World) {
    fetchMock.mockImplementation(routes(world))

    return renderWithSession(
      <StudentCreditMappingPanel
        student={student}
        onClose={onClose}
        onSent={onSent}
      />,
      { session: chairSession },
    )
  }

  const subjectBox = (title: string) =>
    screen.findByRole("combobox", {
      name: new RegExp(`Subject for .*${title}`),
    })

  it("pre-selects the best suggestion on each row, marks it Suggested, and saves nothing", async () => {
    const world = twoPendingCredits()
    renderPanel(world)

    const first = await subjectBox("Intro to Computing")
    await waitFor(() =>
      expect(first).toHaveValue("CS101 — Introduction to Computing"),
    )
    // The second row's best match is the same subject, so it gets its next one.
    expect(await subjectBox("College Algebra")).toHaveValue(
      "MATH101 — College Algebra",
    )
    expect(screen.getAllByText("Suggested")).toHaveLength(2)
    expect(world.patches).toEqual([])
  })

  it("saves a row's mapping, with its units, only when the chair confirms it", async () => {
    const world = twoPendingCredits()
    const user = userEvent.setup()
    renderPanel(world)
    await waitFor(async () =>
      expect(await subjectBox("Intro to Computing")).toHaveValue(
        "CS101 — Introduction to Computing",
      ),
    )

    await user.click(
      screen.getByRole("button", {
        name: "Confirm CS 101 — Intro to Computing",
      }),
    )

    await waitFor(() =>
      expect(world.patches).toEqual([
        { id: 7, body: { subject_id: 10, credited_units: 3 } },
      ]),
    )
    expect(await screen.findByText("Confirmed")).toBeInTheDocument()
  })

  it("lets the chair pick a different subject of the student's curriculum", async () => {
    const world = twoPendingCredits()
    const user = userEvent.setup()
    renderPanel(world)

    await user.click(await subjectBox("Intro to Computing"))
    await user.click(await screen.findByRole("option", { name: /ENG101/ }))
    // Her own pick is no longer "Suggested".
    await waitFor(() =>
      expect(screen.getAllByText("Suggested")).toHaveLength(1),
    )
    await user.click(
      screen.getByRole("button", {
        name: "Confirm CS 101 — Intro to Computing",
      }),
    )

    await waitFor(() =>
      expect(world.patches).toEqual([
        { id: 7, body: { subject_id: 14, credited_units: 3 } },
      ]),
    )
  })

  it("confirms every pre-selected row once with Confirm all suggested", async () => {
    const world = twoPendingCredits()
    const user = userEvent.setup()
    renderPanel(world)
    await waitFor(async () =>
      expect(await subjectBox("College Algebra")).toHaveValue(
        "MATH101 — College Algebra",
      ),
    )

    await user.click(
      screen.getByRole("button", { name: "Confirm all suggested" }),
    )

    await waitFor(() =>
      expect(world.patches).toEqual([
        { id: 7, body: { subject_id: 10, credited_units: 3 } },
        { id: 8, body: { subject_id: 13, credited_units: 3 } },
      ]),
    )
    expect(
      await screen.findByText("Confirmed 2 suggested mappings."),
    ).toBeInTheDocument()
  })

  it("keeps Submit off until every pending row is confirmed, then sends the student once", async () => {
    const world = twoPendingCredits()
    const user = userEvent.setup()
    renderPanel(world)

    const submit = await screen.findByRole("button", {
      name: "Submit to Registrar Head",
    })
    expect(submit).toBeDisabled()
    expect(await screen.findByText("2 still to confirm")).toBeInTheDocument()

    await waitFor(async () =>
      expect(await subjectBox("College Algebra")).toHaveValue(
        "MATH101 — College Algebra",
      ),
    )
    await user.click(
      screen.getByRole("button", { name: "Confirm all suggested" }),
    )
    await waitFor(() => expect(submit).toBeEnabled())

    await user.click(submit)

    await waitFor(() => expect(onSent).toHaveBeenCalledWith(2))
    expect(world.submits).toBe(1)
  })

  it("declines a request with no equivalent only with a reason", async () => {
    const world = twoPendingCredits()
    const user = userEvent.setup()
    renderPanel(world)

    await user.click(
      await screen.findByRole("button", {
        name: "No equivalent for CS 101 — Intro to Computing",
      }),
    )
    const confirm = screen.getByRole("button", { name: "Confirm decline" })
    expect(confirm).toBeDisabled()
    await user.type(
      screen.getByLabelText("Reason for CS 101 — Intro to Computing"),
      "Not offered here.",
    )
    expect(confirm).toBeEnabled()
    await user.click(confirm)

    await waitFor(() =>
      expect(world.patches).toEqual([
        { id: 7, body: { action: "decline", reason: "Not offered here." } },
      ]),
    )
  })

  it("shows the Registrar Head's reason on a returned row and keeps its saved subject", async () => {
    const world: World = {
      credits: [
        credit({
          id: 7,
          subject_id: 13,
          subject_code: "MATH101",
          subject_title: "College Algebra",
          returned_at: "2026-10-03T00:00:00Z",
          return_reason: "Wrong subject for this title.",
        }),
      ],
      workspace: [{ credit_id: 7, suggestions: [] }],
      patches: [],
      submits: 0,
    }
    renderPanel(world)

    expect(
      await screen.findByText(
        "Returned by the Registrar Head: Wrong subject for this title.",
      ),
    ).toBeInTheDocument()
    await waitFor(async () =>
      expect(await subjectBox("Intro to Computing")).toHaveValue(
        "MATH101 — College Algebra",
      ),
    )
    // Already saved, so it does not hold the student back.
    expect(
      screen.getByRole("button", { name: "Submit to Registrar Head" }),
    ).toBeEnabled()
  })

  it("shows rows that are already with the Registrar Head or decided read-only", async () => {
    const world: World = {
      credits: [
        credit({
          id: 7,
          status: "endorsed",
          status_label: "Endorsed",
          subject_id: 10,
          subject_code: "CS101",
          subject_title: "Introduction to Computing",
        }),
        credit({
          id: 8,
          status: "approved",
          status_label: "Approved",
          subject_id: 13,
          subject_code: "MATH101",
          subject_title: "College Algebra",
          source_subject_code: "MAT 101",
          source_subject_title: "College Algebra",
        }),
        credit({
          id: 9,
          status: "rejected",
          status_label: "Rejected",
          source_subject_code: "",
          source_subject_title: "Basket Weaving",
        }),
      ],
      workspace: [],
      patches: [],
      submits: 0,
    }
    renderPanel(world)

    const list = await screen.findByRole("list", { name: "Credits to map" })
    expect(within(list).getByText("Endorsed")).toBeInTheDocument()
    expect(within(list).getByText("Approved")).toBeInTheDocument()
    expect(within(list).getByText("Rejected")).toBeInTheDocument()
    expect(within(list).getByText("No equivalent")).toBeInTheDocument()
    expect(within(list).queryByRole("button")).not.toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Submit to Registrar Head" }),
    ).toBeDisabled()
  })

  it("shows the API's reason when sending is refused, and stays open", async () => {
    const world = twoPendingCredits()
    world.submitResponse = () =>
      new Response(
        JSON.stringify({
          error: {
            code: "VALIDATION_ERROR",
            message: "The given data was invalid.",
            errors: {
              credits: [
                "Map or decline every request before sending it to the Registrar Head. Still unmapped: College Algebra.",
              ],
            },
            request_id: "req-1",
          },
        }),
        { status: 422 },
      )
    const user = userEvent.setup()
    renderPanel(world)
    await waitFor(async () =>
      expect(await subjectBox("College Algebra")).toHaveValue(
        "MATH101 — College Algebra",
      ),
    )
    await user.click(
      screen.getByRole("button", { name: "Confirm all suggested" }),
    )
    const submit = screen.getByRole("button", {
      name: "Submit to Registrar Head",
    })
    await waitFor(() => expect(submit).toBeEnabled())

    await user.click(submit)

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /Still unmapped: College Algebra/,
    )
    expect(onSent).not.toHaveBeenCalled()
  })

  it("closes when the chair asks to", async () => {
    renderPanel(twoPendingCredits())
    const user = userEvent.setup()

    await user.click(await screen.findByRole("button", { name: "Close" }))

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it("has no detectable accessibility violations once loaded", async () => {
    const { container } = renderPanel(twoPendingCredits())

    await waitFor(async () =>
      expect(await subjectBox("Intro to Computing")).toHaveValue(
        "CS101 — Introduction to Computing",
      ),
    )
    expect(await axe(container)).toHaveNoViolations()
  })
})
