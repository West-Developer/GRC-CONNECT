import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ProgramChairCreditMappingsWorkspace } from "@/features/components/portal/program-chair-credit-mappings-workspace"
import { renderWithSession } from "@/tests/render-app"

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const chairSession = {
  userId: "12",
  displayName: "Prof. Chair",
  role: "program_chair" as const,
  signedInAt: "2026-09-24T00:00:00Z",
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
    created_at: "2026-09-01T00:00:00Z",
    ...overrides,
  }
}

const mariaFirst = credit({ id: 7 })
const mariaSecond = credit({
  id: 8,
  source_subject_code: "MAT 101",
  source_subject_title: "College Algebra",
})
const juan = credit({
  id: 9,
  student_id: 31,
  student_name: "Juan Dela Cruz",
  student_number: "2026-0003",
  source_subject_code: "STA 201",
  source_subject_title: "Statistics",
})
const endorsedCredit = credit({
  id: 10,
  student_id: 32,
  student_name: "Ana Reyes",
  student_number: "2026-0004",
  source_subject_title: "Physics",
  subject_id: 11,
  subject_code: "PHY201",
  subject_title: "General Physics",
  status: "endorsed",
  status_label: "Endorsed",
  endorsed_at: "2026-09-02T00:00:00Z",
})

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
  [13, "MATH101", "College Algebra"],
].map(([id, code, title]) => ({
  subject_id: id,
  subject_code: code,
  subject_title: title,
  units: 3,
  year_level: 1,
  semester: "1st",
}))

interface Recorded {
  patches: unknown[]
  posts: unknown[]
  submits: number
}

function mockRoutes(recorded: Recorded, overrides: { tors?: unknown[] } = {}) {
  // Maria's two credits, as the server would hold them while the chair works.
  const maria: Record<string, unknown>[] = [
    { ...mariaFirst },
    { ...mariaSecond },
  ]

  return (input: RequestInfo | URL, init?: RequestInit) => {
    const url = requestUrl(input)
    const method = init?.method ?? "GET"

    if (url.includes("/tor-documents")) {
      return Promise.resolve(
        new Response(JSON.stringify(paginated(overrides.tors ?? []))),
      )
    }
    if (url.includes("/student-profiles/30/transferee-credits/suggestions")) {
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: {
              student_id: 30,
              credits: [
                {
                  credit_id: 7,
                  suggestions: [
                    suggestion(10, "CS101", "Introduction to Computing", 1),
                  ],
                },
                {
                  credit_id: 8,
                  suggestions: [
                    suggestion(13, "MATH101", "College Algebra", 0.9),
                  ],
                },
              ],
              curriculum_subjects: curriculumSubjects,
            },
          }),
        ),
      )
    }
    if (url.includes("/submit-to-registrar-head") && method === "POST") {
      recorded.submits += 1
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: maria.map((row) => ({
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
      const body = JSON.parse(init?.body as string) as Record<string, unknown>
      recorded.patches.push(body)
      const row = maria.find((entry) => entry.id === Number(patch[1]))!
      const subject = curriculumSubjects.find(
        (entry) => entry.subject_id === body.subject_id,
      )!
      row.subject_id = subject.subject_id
      row.subject_code = subject.subject_code
      row.subject_title = subject.subject_title
      return Promise.resolve(new Response(JSON.stringify({ data: row })))
    }
    if (url.includes("/transferee-credits") && method === "POST") {
      recorded.posts.push(JSON.parse(init?.body as string))
      return Promise.resolve(
        new Response(JSON.stringify({ data: mariaFirst }), { status: 201 }),
      )
    }
    if (url.includes("/transferee-credits") && url.includes("student_id=30")) {
      return Promise.resolve(new Response(JSON.stringify(paginated(maria))))
    }
    if (
      url.includes("/transferee-credits") &&
      url.includes("status=endorsed")
    ) {
      return Promise.resolve(
        new Response(JSON.stringify(paginated([endorsedCredit]))),
      )
    }
    if (url.includes("/transferee-credits")) {
      return Promise.resolve(
        new Response(JSON.stringify(paginated([...maria, juan]))),
      )
    }
    if (url.includes("/academic-record/students")) {
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [
              {
                type: "academic_record_student",
                id: 30,
                student_id: 30,
                student_number: "2026-0002",
                name: "Maria Santos",
                first_name: "Maria",
                last_name: "Santos",
                email: "maria.santos@grc.com",
                program_code: "BSIT",
                program_name: "BS Information Technology",
                year_level: 2,
                enrollment_category: "regular",
                enrollment_category_label: "Regular",
                academic_standing: "good",
                academic_standing_label: "Good standing",
              },
            ],
          }),
        ),
      )
    }
    return Promise.resolve(new Response(JSON.stringify({ data: [] })))
  }
}

describe("ProgramChairCreditMappingsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let recorded: Recorded

  beforeEach(() => {
    recorded = { patches: [], posts: [], submits: 0 }
    vi.stubGlobal("fetch", fetchMock)
  })
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("is not available for another role", () => {
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: { ...chairSession, role: "registrar_staff" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("lists the students waiting for review, one row per student, and the credits waiting for the Registrar Head", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for review",
    })
    expect(
      within(queue).getByText("Maria Santos (2026-0002)"),
    ).toBeInTheDocument()
    expect(within(queue).getByText("2 subjects")).toBeInTheDocument()
    expect(
      within(queue).getByText("Juan Dela Cruz (2026-0003)"),
    ).toBeInTheDocument()
    expect(within(queue).getByText("1 subject")).toBeInTheDocument()

    const waiting = await screen.findByRole("table", {
      name: "Endorsed credits",
    })
    expect(
      within(waiting).getByText("Ana Reyes (2026-0004)"),
    ).toBeInTheDocument()
    expect(
      within(waiting).getByText("PHY201 — General Physics"),
    ).toBeInTheDocument()
  })

  it("lists the students of every page of the pending queue, not only the first 100 rows", async () => {
    // The API never returns more than 100 rows a page, and a transferee with a
    // full transcript has dozens of rows, so a queue read once would hide students.
    const requestedPages: number[] = []
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (
        url.includes("/transferee-credits") &&
        url.includes("status=pending")
      ) {
        const page = Number(new URL(url, "http://x").searchParams.get("page"))
        requestedPages.push(page)
        return Promise.resolve(
          new Response(
            JSON.stringify({
              ...paginated(page === 1 ? [mariaFirst] : [juan]),
              meta: {
                current_page: page,
                last_page: 2,
                per_page: 100,
                total: 2,
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for review",
    })
    expect(
      await within(queue).findByText("Juan Dela Cruz (2026-0003)"),
    ).toBeInTheDocument()
    expect(
      within(queue).getByText("Maria Santos (2026-0002)"),
    ).toBeInTheDocument()
    expect(requestedPages).toEqual([1, 2])
  })

  it("opens one student's side-by-side screen from the queue", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for review",
    })
    await user.click(
      within(queue).getByRole("button", { name: "Review Maria Santos" }),
    )

    expect(
      await screen.findByRole("heading", {
        name: "Map credits for Maria Santos",
      }),
    ).toBeInTheDocument()
    expect(
      await screen.findByRole("combobox", {
        name: /Subject for .*Intro to Computing/,
      }),
    ).toBeInTheDocument()
  })

  it("sends a student to the Registrar Head, tells the chair how many were sent, and closes the screen", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    const queue = await screen.findByRole("table", {
      name: "Students waiting for review",
    })
    await user.click(
      within(queue).getByRole("button", { name: "Review Maria Santos" }),
    )
    const confirmAll = await screen.findByRole("button", {
      name: "Confirm all suggested",
    })
    await waitFor(() => expect(confirmAll).toBeEnabled())
    await user.click(confirmAll)
    const submit = screen.getByRole("button", {
      name: "Submit to Registrar Head",
    })
    await waitFor(() => expect(submit).toBeEnabled())
    await user.click(submit)

    expect(
      await screen.findByText(
        "Sent 2 credits of Maria Santos to the Registrar Head.",
      ),
    ).toBeInTheDocument()
    expect(recorded.submits).toBe(1)
    expect(
      screen.queryByRole("heading", { name: "Map credits for Maria Santos" }),
    ).not.toBeInTheDocument()
  })

  it("records a credit for a walk-in student found in the chair's college", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const user = userEvent.setup()
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    await user.type(await screen.findByLabelText(/Find student/i), "Santos")
    const matches = await screen.findByRole("list", {
      name: "Matching students",
    })
    await user.click(
      within(matches).getByRole("button", { name: /Maria Santos/ }),
    )
    await user.type(screen.getByLabelText("Previous school"), "FEU Tech")
    await user.type(screen.getByLabelText("Subject title"), "Programming 1")
    await user.type(screen.getByLabelText(/Subject code/), "prog101")
    await user.click(screen.getByRole("button", { name: "Record credit" }))

    await waitFor(() =>
      expect(recorded.posts).toEqual([
        {
          student_id: 30,
          source_institution: "FEU Tech",
          source_subject_code: "PROG101",
          source_subject_title: "Programming 1",
          credited_units: 3,
        },
      ]),
    )
    expect(
      await screen.findByText(/Recorded for Maria Santos/),
    ).toBeInTheDocument()
  })

  it("starts recording credits for a student from their uploaded TOR, with the TOR beside the form", async () => {
    const tor = {
      type: "tor_document",
      id: 5,
      student_id: 30,
      student_number: "2026-0002",
      student_name: "Maria Santos",
      original_name: "maria-tor.pdf",
      mime_type: "application/pdf",
      size_bytes: 100_000,
      uploaded_at: "2026-10-01T04:00:00Z",
    }
    fetchMock.mockImplementation(mockRoutes(recorded, { tors: [tor] }))
    const user = userEvent.setup()
    renderWithSession(<ProgramChairCreditMappingsWorkspace />, {
      session: chairSession,
    })

    await user.click(
      await screen.findByRole("button", {
        name: "Record credits for Maria Santos",
      }),
    )

    // The form already has the student, and shows their TOR to read while typing.
    expect(screen.queryByLabelText(/Find student/i)).not.toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Change student" }),
    ).toBeInTheDocument()
    expect(
      await screen.findByRole("list", { name: "Uploaded TOR files" }),
    ).toBeInTheDocument()

    await user.type(screen.getByLabelText("Previous school"), "FEU Tech")
    await user.type(screen.getByLabelText("Subject title"), "Programming 1")
    await user.click(screen.getByRole("button", { name: "Record credit" }))

    await waitFor(() =>
      expect(recorded.posts).toEqual([
        {
          student_id: 30,
          source_institution: "FEU Tech",
          source_subject_title: "Programming 1",
          credited_units: 3,
        },
      ]),
    )
  })

  it("has no detectable accessibility violations once loaded", async () => {
    fetchMock.mockImplementation(mockRoutes(recorded))
    const { container } = renderWithSession(
      <ProgramChairCreditMappingsWorkspace />,
      {
        session: chairSession,
      },
    )

    await screen.findByRole("table", { name: "Students waiting for review" })
    expect(await axe(container)).toHaveNoViolations()
  })
})
